<?php

declare(strict_types=1);

namespace App\DataFixtures\Purger;

use Doctrine\Bundle\FixturesBundle\Purger\PurgerFactory;
use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\Common\DataFixtures\Purger\PurgerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * doctrine:fixtures:load purges every table with one DELETE per table; MariaDB checks
 * foreign keys row by row, so the self-referencing org_unit.parent_id (ON DELETE RESTRICT)
 * makes a reload fail. Foreign key checks are switched off for the purge only; the schema
 * keeps its RESTRICT semantics for the application.
 *
 * @implements PurgerFactory<ORMPurgerInterface>
 */
#[AsDecorator('doctrine.fixtures.purger.orm_purger_factory', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final readonly class ForeignKeySafePurgerFactory implements PurgerFactory
{
    /**
     * @param PurgerFactory<PurgerInterface> $inner
     */
    public function __construct(private PurgerFactory $inner)
    {
    }

    /**
     * @param list<string> $excluded
     */
    public function createForEntityManager(?string $emName, EntityManagerInterface $em, array $excluded = [], bool $purgeWithTruncate = false): ORMPurgerInterface
    {
        $purger = $this->inner->createForEntityManager($emName, $em, $excluded, $purgeWithTruncate);

        return new class($purger, $em) implements ORMPurgerInterface {
            public function __construct(private PurgerInterface $purger, private EntityManagerInterface $em)
            {
            }

            public function setEntityManager(EntityManagerInterface $em): void
            {
                $this->em = $em;
                if ($this->purger instanceof ORMPurgerInterface) {
                    $this->purger->setEntityManager($em);
                }
            }

            public function purge(): void
            {
                $connection = $this->em->getConnection();
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    $this->purger->purge();
                } finally {
                    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                }
            }
        };
    }
}

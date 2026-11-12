<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Org\OrgUnit;
use App\Entity\Sync\IngestToken;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Push API source tokens: issue (the plain token is returned once), list, revoke.
 * Used by the admin screen and by app:ingest-token.
 */
final readonly class IngestTokenManager
{
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,31}$/';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array{IngestToken, string} the token and its plain value (not stored)
     *
     * @throws \InvalidArgumentException with a user-facing message
     */
    public function issue(string $name, int $orgUnitId): array
    {
        $name = trim($name);
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('Имя: латиница в нижнем регистре, цифры, «.», «_», «-»; от 2 до 32 символов.');
        }
        if (null !== $this->em->getRepository(IngestToken::class)->findOneBy(['name' => $name])) {
            throw new \InvalidArgumentException(\sprintf('Токен «%s» уже есть.', $name));
        }
        $unit = $this->em->find(OrgUnit::class, $orgUnitId) ?? throw new \InvalidArgumentException('Отдел не найден.');

        [$token, $plain] = IngestToken::issue($name, $unit);
        $this->em->persist($token);
        $this->em->flush();

        return [$token, $plain];
    }

    /**
     * @return list<IngestToken> active first, then by name
     */
    public function list(): array
    {
        $tokens = $this->em->getRepository(IngestToken::class)->findBy([], ['name' => 'ASC']);
        usort($tokens, static fn (IngestToken $a, IngestToken $b): int => [$b->isActive(), $a->getName()] <=> [$a->isActive(), $b->getName()]);

        return $tokens;
    }

    public function find(int $id): ?IngestToken
    {
        return $this->em->find(IngestToken::class, $id);
    }

    public function findByName(string $name): ?IngestToken
    {
        return $this->em->getRepository(IngestToken::class)->findOneBy(['name' => $name]);
    }

    public function revoke(IngestToken $token): void
    {
        $token->revoke();
        $this->em->flush();
    }
}

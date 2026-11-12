<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Auth\User;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Enum\OrgRole;
use App\Service\Org\OrgStructureManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Org structure from the console (the same logic as the admin screen, see OrgStructureManager):
 *   create-unit <name> <parent-id> [--sort=]   new department under a parent
 *   move-unit <unit-id> <parent-id>            move a department with its branch under another parent
 *   set-head <unit-id> <email>                 head of a department (replaces the previous head)
 *   add-member <unit-id> <email> <role>        member / viewer / admin / head membership
 *   remove-member <unit-id> <email>
 *   tree                                       units with heads and members
 */
#[AsCommand(name: 'app:org', description: 'Org structure: create-unit | move-unit | set-head | add-member | remove-member | tree')]
final readonly class OrgCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrgStructureManager $structure,
    ) {
    }

    /**
     * @param list<string> $args
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'create-unit, move-unit, set-head, add-member, remove-member or tree')]
        string $action,
        #[Argument(description: 'Arguments of the action')]
        array $args = [],
        #[Option(description: 'Sort position among siblings (create-unit)')]
        int $sort = 0,
    ): int {
        try {
            match ($action) {
                'create-unit' => $this->createUnit($io, $args[0] ?? '', $this->unit($args[1] ?? ''), $sort),
                'move-unit' => $this->moveUnit($io, $this->unit($args[0] ?? ''), $this->unit($args[1] ?? '')),
                'set-head' => $this->setHead($io, $this->unit($args[0] ?? ''), $this->user($args[1] ?? '')),
                'add-member' => $this->addMember($io, $this->unit($args[0] ?? ''), $this->user($args[1] ?? ''), $this->role($args[2] ?? '')),
                'remove-member' => $this->removeMember($io, $this->unit($args[0] ?? ''), $this->user($args[1] ?? '')),
                'tree' => $this->printTree($io),
                default => throw new \InvalidArgumentException(\sprintf('Unknown action "%s".', $action)),
            };
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        return Command::SUCCESS;
    }

    private function createUnit(SymfonyStyle $io, string $name, OrgUnit $parent, int $sort): void
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Usage: app:org create-unit <name> <parent-id>');
        }
        $unit = $this->structure->createUnit($name, $parent, $sort);
        $io->success(\sprintf('Unit "%s" created under "%s": id %d.', $unit->getName(), $parent->getName(), $unit->getId()));
    }

    private function moveUnit(SymfonyStyle $io, OrgUnit $unit, OrgUnit $parent): void
    {
        $from = $unit->getParent()?->getName() ?? '—';
        $this->structure->move($unit, $parent);
        $io->success(\sprintf('"%s" moved from "%s" to "%s" with its branch.', $unit->getName(), $from, $parent->getName()));
    }

    /**
     * The head sees the unit and its whole branch (membership "head"); org_unit.head_user_id shows who it is.
     */
    private function setHead(SymfonyStyle $io, OrgUnit $unit, User $user): void
    {
        $previous = $unit->getHeadUser();
        $this->structure->setHead($unit, $user);
        $io->success(\sprintf('%s heads "%s"%s.', $user->getEmail(), $unit->getName(), null !== $previous && $previous !== $user ? ' instead of '.$previous->getEmail() : ''));
    }

    private function addMember(SymfonyStyle $io, OrgUnit $unit, User $user, OrgRole $role): void
    {
        $this->structure->setMember($unit, $user, $role);
        $io->success(\sprintf('%s is %s of "%s".', $user->getEmail(), $role->value, $unit->getName()));
    }

    private function removeMember(SymfonyStyle $io, OrgUnit $unit, User $user): void
    {
        $this->structure->removeMember($unit, $user);
        $io->success(\sprintf('%s removed from "%s".', $user->getEmail(), $unit->getName()));
    }

    private function printTree(SymfonyStyle $io): void
    {
        $units = $this->em->getRepository(OrgUnit::class)->findBy([], ['sort' => 'ASC', 'id' => 'ASC']);
        $children = [];
        foreach ($units as $unit) {
            $children[$unit->getParent()?->getId() ?? 0][] = $unit;
        }

        $rows = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $children): void {
            foreach ($children[$parentId] ?? [] as $unit) {
                $members = array_map(
                    static fn (OrgMembership $m): string => $m->getUser()->getEmail().' ('.$m->getRole()->value.')',
                    $this->em->getRepository(OrgMembership::class)->findBy(['orgUnit' => $unit]),
                );
                $rows[] = [$unit->getId(), str_repeat('  ', $depth).$unit->getName(), $unit->getHeadUser()?->getEmail() ?? '—', implode(', ', $members)];
                $walk((int) $unit->getId(), $depth + 1);
            }
        };
        $walk(0, 0);

        $io->table(['Id', 'Unit', 'Head', 'Memberships'], $rows);
    }

    private function unit(string $id): OrgUnit
    {
        $unit = ctype_digit($id) ? $this->em->find(OrgUnit::class, (int) $id) : null;

        return $unit ?? throw new \InvalidArgumentException(\sprintf('Org unit "%s" not found (pass its id, see app:org tree).', $id));
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($email))]);

        return $user ?? throw new \InvalidArgumentException(\sprintf('User "%s" not found.', $email));
    }

    private function role(string $role): OrgRole
    {
        return OrgRole::tryFrom($role) ?? throw new \InvalidArgumentException(\sprintf('Role must be one of: %s.', implode(', ', array_map(static fn (OrgRole $r): string => $r->value, OrgRole::cases()))));
    }
}

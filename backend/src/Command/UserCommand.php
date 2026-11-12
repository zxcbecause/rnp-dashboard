<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Auth\User;
use App\Service\Auth\UserAccountManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user', description: 'Local accounts: create <email> <name> [--admin] | reset-password <email> | deactivate|activate <email> | grant-admin|revoke-admin <email> | delete <email> | list')]
final readonly class UserCommand
{
    public function __construct(
        private UserAccountManager $accounts,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'create, reset-password, deactivate, activate, grant-admin, revoke-admin, delete or list')]
        string $action,
        #[Argument(description: 'Email')]
        ?string $email = null,
        #[Argument(description: 'Full name (create)')]
        ?string $name = null,
        #[Option(description: 'Global administrator: sees and manages everything')]
        bool $admin = false,
        #[Option(description: 'Append "email<TAB>password" here instead of printing the password (relative to the backend dir)')]
        ?string $passwordFile = null,
    ): int {
        try {
            return match ($action) {
                'create' => $this->create($io, (string) $email, (string) $name, $admin, $passwordFile),
                'reset-password' => $this->reset($io, $this->user((string) $email), $passwordFile),
                'deactivate', 'activate' => $this->setActive($io, $this->user((string) $email), 'activate' === $action),
                'grant-admin', 'revoke-admin' => $this->setAdmin($io, $this->user((string) $email), 'grant-admin' === $action),
                'delete' => $this->delete($io, $this->user((string) $email)),
                'list' => $this->list($io),
                default => throw new \InvalidArgumentException(\sprintf('Unknown action "%s".', $action)),
            };
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
    }

    private function create(SymfonyStyle $io, string $email, string $name, bool $admin, ?string $file): int
    {
        [$user, $password] = $this->accounts->create($email, $name, $admin);
        $this->deliver($io, $user, $password, $file);

        return Command::SUCCESS;
    }

    private function reset(SymfonyStyle $io, User $user, ?string $file): int
    {
        $this->deliver($io, $user, $this->accounts->resetPassword($user), $file);

        return Command::SUCCESS;
    }

    private function setActive(SymfonyStyle $io, User $user, bool $active): int
    {
        $this->accounts->setActive($user, $active);
        $io->success(\sprintf('%s %s.', $user->getEmail(), $active ? 'activated' : 'deactivated: can no longer log in'));

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io): int
    {
        $users = $this->em->getRepository(User::class)->findBy([], ['email' => 'ASC']);
        $io->table(['Email', 'Name', 'Admin', 'Active'], array_map(
            static fn (User $u): array => [$u->getEmail(), $u->getName(), $u->isAdmin() ? 'yes' : '', $u->isActive() ? 'yes' : 'no'],
            $users,
        ));

        return Command::SUCCESS;
    }

    private function deliver(SymfonyStyle $io, User $user, string $password, ?string $file): void
    {
        if (null === $file) {
            $io->success(\sprintf('Password of %s (shown once):', $user->getEmail()));
            $io->writeln($password);

            return;
        }

        // Restrict the file before any password lands in it.
        if (!file_exists($file)) {
            if (false === @touch($file)) {
                throw new \InvalidArgumentException(\sprintf('Cannot create %s.', $file));
            }
            @chmod($file, 0600);
        }

        $line = \sprintf("%s\t%s\t%s\n", $user->getEmail(), $user->getName(), $password);
        if (false === file_put_contents($file, $line, \FILE_APPEND | \LOCK_EX)) {
            throw new \InvalidArgumentException(\sprintf('Cannot write %s.', $file));
        }
        $io->success(\sprintf('%s: password written to %s.', $user->getEmail(), $file));
    }

    private function user(string $email): User
    {
        return $this->accounts->find($email) ?? throw new \InvalidArgumentException(\sprintf('User %s not found.', $email));
    }

    /**
     * Global administrator: sees the whole tree and manages plans, tokens and sync; org memberships are kept.
     */
    private function setAdmin(SymfonyStyle $io, User $user, bool $admin): int
    {
        $this->accounts->setAdmin($user, $admin);
        $io->success(\sprintf('%s %s.', $user->getEmail(), $admin ? 'is now an administrator' : 'is no longer an administrator'));

        return Command::SUCCESS;
    }

    /**
     * Only accounts without history (see UserAccountManager::delete), e.g. the test ones before going live.
     */
    private function delete(SymfonyStyle $io, User $user): int
    {
        $email = $user->getEmail();
        $result = $this->accounts->delete($user);
        $io->success(\sprintf(
            '%s deleted: %d metric(s) passed to the unit head, %d unit(s) left without this head, %d membership(s) removed.',
            $email,
            $result['metrics'],
            $result['units'],
            $result['memberships'],
        ));

        return Command::SUCCESS;
    }
}

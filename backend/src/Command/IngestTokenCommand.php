<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sync\IngestToken;
use App\Service\Ingest\IngestTokenManager;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:ingest-token', description: 'Manage Push API source tokens: create <name> <org-unit-id> | list | revoke <name>')]
final readonly class IngestTokenCommand
{
    public function __construct(private IngestTokenManager $tokens)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'create, list or revoke')]
        string $action,
        #[Argument(description: 'Token name (≤ 32 chars, e.g. "cs-nps-script")')]
        ?string $name = null,
        #[Argument(description: 'Org unit id the token writes to (its branch); create only')]
        ?int $unit = null,
    ): int {
        try {
            return match ($action) {
                'create' => $this->create($io, (string) $name, (int) $unit),
                'list' => $this->list($io),
                'revoke' => $this->revoke($io, (string) $name),
                default => throw new \InvalidArgumentException(\sprintf('Unknown action "%s": use create, list or revoke.', $action)),
            };
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
    }

    private function create(SymfonyStyle $io, string $name, int $unitId): int
    {
        [$token, $plain] = $this->tokens->issue($name, $unitId);

        $io->success(\sprintf('Token "%s" for "%s" (and the units below). It is shown only once:', $token->getName(), $token->getOrgUnit()->getName()));
        $io->writeln($plain);
        $io->newLine();
        $io->writeln('curl -X POST <API>/api/ingest/metric-values -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -d \'{"values":[{"metric":"<code>","date":"YYYY-MM-DD","value":"1"}]}\'');

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io): int
    {
        $io->table(['Name', 'Org unit', 'Starts with', 'Created', 'Last used', 'Revoked'], array_map(
            static fn (IngestToken $t): array => [
                $t->getName(), $t->getOrgUnit()->getName(), $t->getTokenHint().'…', $t->getCreatedAt()->format('Y-m-d H:i'),
                $t->getLastUsedAt()?->format('Y-m-d H:i') ?? '—', $t->getRevokedAt()?->format('Y-m-d H:i') ?? '',
            ],
            $this->tokens->list(),
        ));

        return Command::SUCCESS;
    }

    private function revoke(SymfonyStyle $io, string $name): int
    {
        $token = $this->tokens->findByName($name) ?? throw new \InvalidArgumentException('Usage: app:ingest-token revoke <existing name>');
        $this->tokens->revoke($token);
        $io->success(\sprintf('Token "%s" revoked.', $name));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Integration\Platform\ExcludedAccommodations;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:platform:exclude-accommodation', description: 'Platform accommodations no metric counts (test listings): add <id> [note] | remove <id> | list')]
final readonly class ExcludeAccommodationCommand
{
    private const string RECALCULATE_HINT = 'Stored facts are unchanged until the metrics are recalculated: php bin/console app:metrics:recalculate --from=<date>';

    public function __construct(private ExcludedAccommodations $excluded)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'add, remove or list')]
        string $action,
        #[Argument(description: 'Platform accommodation id')]
        ?int $id = null,
        #[Argument(description: 'Why it is excluded (add only)')]
        ?string $note = null,
    ): int {
        try {
            return match ($action) {
                'add' => $this->add($io, $id, $note),
                'remove' => $this->remove($io, $id),
                'list' => $this->list($io),
                default => throw new \InvalidArgumentException(\sprintf('Unknown action "%s": use add, remove or list.', $action)),
            };
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
    }

    private function add(SymfonyStyle $io, ?int $id, ?string $note): int
    {
        $added = $this->excluded->add($id ?? throw new \InvalidArgumentException('Usage: app:platform:exclude-accommodation add <id> [note]'), $note);
        $io->success(\sprintf($added ? 'Accommodation %d is excluded from every metric.' : 'Accommodation %d was excluded already; note updated.', $id));
        if ($added) {
            $io->note(self::RECALCULATE_HINT);
        }

        return Command::SUCCESS;
    }

    private function remove(SymfonyStyle $io, ?int $id): int
    {
        if (!$this->excluded->remove($id ?? throw new \InvalidArgumentException('Usage: app:platform:exclude-accommodation remove <id>'))) {
            throw new \InvalidArgumentException(\sprintf('Accommodation %d is not excluded.', $id));
        }
        $io->success(\sprintf('Accommodation %d counts again.', $id));
        $io->note(self::RECALCULATE_HINT);

        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io): int
    {
        $io->table(['Id', 'Name', 'Status', 'Note', 'Excluded at'], array_map(
            static fn (array $row): array => [$row['id'], $row['name'] ?? '— (not synced)', $row['status'] ?? '', $row['note'] ?? '', $row['createdAt']],
            $this->excluded->list(),
        ));

        return Command::SUCCESS;
    }
}

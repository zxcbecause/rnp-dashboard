<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SyncStatus;
use App\Integration\Bitrix\BitrixClient;
use App\Integration\Bitrix\Sync\BitrixSyncHandler;
use App\Integration\Sync\SyncAlreadyRunningException;
use App\Service\Metric\AutoMetricRecalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:sync:bitrix', description: 'Pull owner-pipeline deals, their stage history and call activities from Bitrix24 and recalculate the metrics')]
final readonly class SyncBitrixCommand
{
    public function __construct(
        private BitrixClient $client,
        private BitrixSyncHandler $sync,
        private AutoMetricRecalculator $recalculator,
    ) {
    }

    /**
     * @param list<string> $resource
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only these resources (deal_stages, deals, deal_stage_history, calls)')]
        array $resource = [],
        #[Option(description: 'Ignore the last successful run and re-read everything')]
        bool $full = false,
        #[Option(description: 'Do not recalculate metrics after the sync')]
        bool $skipMetrics = false,
        #[Option(description: 'Recalculate metrics from this date (Y-m-d); default: first day of the month two months ago')]
        ?string $recalcFrom = null,
        #[Option(description: 'Instead of --recalc-from: recalculate from the first day of the month this many months ago (nightly full runs: 12)')]
        ?int $recalcMonths = null,
    ): int {
        if (!$this->client->isConfigured()) {
            // Not an error: the schedule keeps running before the webhook is set in .env.local.
            $io->warning('Bitrix24 is not configured (BITRIX_WEBHOOK_URL in .env.local); skipping.');

            return Command::SUCCESS;
        }

        try {
            $runs = $this->sync->handle($resource, $full);
        } catch (SyncAlreadyRunningException $e) {
            $io->note($e->getMessage());

            return Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->table(['Resource', 'Status', 'Rows', 'Error'], array_map(
            static fn ($run): array => [$run->getScope(), $run->getStatus()->value, $run->getRows(), $run->getError() ?? ''],
            $runs,
        ));

        $failed = array_filter($runs, static fn ($run): bool => SyncStatus::Failed === $run->getStatus());

        if (!$skipMetrics) {
            $exit = (new RecalculateMetricsCommand($this->recalculator))($io, $recalcFrom, $recalcMonths);
            if (Command::SUCCESS !== $exit) {
                return $exit;
            }
        }

        return [] === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}

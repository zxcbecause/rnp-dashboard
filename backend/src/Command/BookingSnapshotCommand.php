<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SyncStatus;
use App\Integration\Platform\BookingDaySnapshotter;
use App\Integration\Platform\PlatformApiClient;
use App\Integration\Platform\Sync\PlatformSyncHandler;
use App\Integration\Sync\SyncAlreadyRunningException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:bookings:snapshot', description: 'Snapshot of the bookings created on a day, as they are now (nightly at 23:55 Madrid, like the Avantio export)')]
final readonly class BookingSnapshotCommand
{
    public function __construct(
        private BookingDaySnapshotter $snapshotter,
        private PlatformApiClient $client,
        private PlatformSyncHandler $sync,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Creation day (Y-m-d); default: today in Madrid')]
        ?string $date = null,
        #[Option(description: 'Scheduler mode: act only between 23:30 and 23:59 Madrid (the job fires at two UTC hours because of daylight saving time)')]
        bool $scheduled = false,
        #[Option(description: 'Do not pull fresh bookings from the platform first')]
        bool $skipSync = false,
    ): int {
        if (null !== $date) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Madrid'));
            if (false === $day || $day->format('Y-m-d') !== $date) {
                $io->error(\sprintf('Invalid date "%s", expected Y-m-d.', $date));

                return Command::INVALID;
            }
        } elseif ($scheduled) {
            $day = $this->snapshotter->scheduledDay();
            if (null === $day) {
                $io->text('Outside the 23:30–23:59 Madrid window; nothing to do.');

                return Command::SUCCESS;
            }
        } else {
            $day = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
        }

        if (!$skipSync) {
            $this->syncBookings($io);
        }

        $rows = $this->snapshotter->take($day);
        $io->success(\sprintf('Snapshot of %s: %d booking(s).', $day->format('Y-m-d'), $rows));

        return Command::SUCCESS;
    }

    /**
     * The snapshot is still taken when the pull fails or another sync holds the lock: the raw data is then at most
     * one regular sync (30 minutes) old.
     */
    private function syncBookings(SymfonyStyle $io): void
    {
        if (!$this->client->isConfigured()) {
            $io->warning('Platform API is not configured; the snapshot uses the bookings already synced.');

            return;
        }

        try {
            foreach ($this->sync->handle(['bookings']) as $run) {
                if (SyncStatus::Failed === $run->getStatus()) {
                    $io->warning(\sprintf('Bookings sync failed (%s); the snapshot uses the bookings already synced.', $run->getError()));
                }
            }
        } catch (SyncAlreadyRunningException $e) {
            $io->note($e->getMessage().' The snapshot uses the bookings already synced.');
        }
    }
}

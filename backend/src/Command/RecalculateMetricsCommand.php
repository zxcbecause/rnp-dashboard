<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Metric\AutoMetricRecalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:metrics:recalculate', description: 'Recalculate weekly facts of platform metrics from the raw snapshots')]
final readonly class RecalculateMetricsCommand
{
    public function __construct(private AutoMetricRecalculator $recalculator)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'From this date (Y-m-d); default: first day of the month two months ago')]
        ?string $from = null,
        #[Option(description: 'Instead of --from: from the first day of the month this many months ago (1–60)')]
        ?int $months = null,
    ): int {
        if (null !== $months && (null !== $from || $months < 1 || $months > 60)) {
            $io->error('--months takes 1 to 60 and cannot be combined with a start date.');

            return Command::INVALID;
        }

        $start = null === $from
            ? (new \DateTimeImmutable(\sprintf('first day of -%d months', $months ?? 2)))->setTime(0, 0)
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $from);

        if (false === $start) {
            $io->error(\sprintf('Invalid date "%s", expected Y-m-d.', $from));

            return Command::INVALID;
        }

        $result = $this->recalculator->recalculate($start);

        $io->text(\sprintf(
            'Metrics recalculated from %s: %d metric(s), %d value(s) written, %d unchanged, %d manual kept.',
            $start->format('Y-m-d'),
            \count($result->metrics),
            $result->written,
            $result->unchanged,
            $result->keptManual,
        ));

        foreach ($result->skipped as $code => $reason) {
            $io->text(\sprintf('  skipped %s: %s', $code, $reason));
        }

        foreach ($result->errors as $code => $error) {
            $io->error(\sprintf('%s: %s', $code, $error));
        }

        return [] === $result->errors ? Command::SUCCESS : Command::FAILURE;
    }
}

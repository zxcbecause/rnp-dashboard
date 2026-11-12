<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Metric\MetricCatalogLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates or updates metrics from a YAML catalog (config/metrics/*.yaml), matched by code. Values and plans are
 * never touched; re-running the same file changes nothing.
 */
#[AsCommand(name: 'app:metric:catalog', description: 'Create or update metrics from a YAML catalog (by code)')]
final class MetricCatalogCommand extends Command
{
    public function __construct(private readonly MetricCatalogLoader $loader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Catalog YAML, e.g. config/metrics/departments-2026-11.yaml')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        $report = $this->loader->load($file, $dryRun);

        $io->table(['Unit', 'Code', 'Name', 'Action'], array_map(static fn (array $r): array => [$r['unit'], $r['code'], $r['name'], $r['action']], $report));
        $created = \count(array_filter($report, static fn (array $r): bool => 'created' === $r['action']));
        $updated = \count(array_filter($report, static fn (array $r): bool => 'updated' === $r['action']));
        $io->success(\sprintf('%s%d created, %d updated, %d unchanged.', $dryRun ? '[dry run] ' : '', $created, $updated, \count($report) - $created - $updated));

        return Command::SUCCESS;
    }
}

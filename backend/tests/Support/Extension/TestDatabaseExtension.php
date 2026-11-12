<?php

declare(strict_types=1);

namespace App\Tests\Support\Extension;

use Codeception\Event\SuiteEvent;
use Codeception\Events;
use Codeception\Extension;

/**
 * Rebuilds the test database from migrations before the functional suite,
 * so the tests always run against the schema the migrations actually produce.
 */
final class TestDatabaseExtension extends Extension
{
    /** @var array<string, string> */
    public static array $events = [
        Events::SUITE_BEFORE => 'beforeSuite',
    ];

    public function beforeSuite(SuiteEvent $event): void
    {
        // The suite name carries the namespace prefix, e.g. "App\Tests.Functional".
        if (!str_ends_with((string) $event->getSuite()?->getName(), 'Functional')) {
            return;
        }

        $console = \dirname(__DIR__, 3).'/bin/console';
        $this->writeln('Rebuilding test database from migrations...');

        foreach ([
            'doctrine:database:drop --if-exists --force',
            'doctrine:database:create --if-not-exists',
            'doctrine:migrations:migrate --no-interaction',
        ] as $command) {
            passthru(\sprintf('php %s %s --env=test 2>&1', escapeshellarg($console), $command), $exitCode);
            if (0 !== $exitCode) {
                throw new \RuntimeException(\sprintf('Test DB setup failed: "%s"', $command));
            }
        }
    }
}

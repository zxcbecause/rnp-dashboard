<?php

declare(strict_types=1);

namespace App\Tests\Functional\Integration;

use App\Service\Metric\Calculation\BitrixMetricCalculator;
use App\Service\Metric\Calculation\BitrixMetricConfig;
use App\Tests\Support\FunctionalTester;
use Doctrine\DBAL\Connection;

/**
 * Lost calls: missed incoming calls with no outgoing or answered call of the same CRM entity within 24 hours.
 */
final class LostCallsCest
{
    public function missedCallsWithoutFollowUpWithinTheWindowAreLost(FunctionalTester $I): void
    {
        $recent = (new \DateTimeImmutable())->modify('-1 hour');
        foreach ([
            // Followed up by an outgoing call the same day.
            [201, 1, true, '2026-10-05 10:00:00', 1],
            [202, 2, false, '2026-10-05 15:00:00', 1],
            // Followed up by an answered incoming call the next morning.
            [203, 1, true, '2026-10-05 10:00:00', 2],
            [204, 1, false, '2026-10-06 09:00:00', 2],
            // Called back, but two days later: lost.
            [205, 1, true, '2026-10-05 10:00:00', 3],
            [206, 2, false, '2026-10-07 10:00:00', 3],
            // A call to another entity does not follow it up: lost.
            [207, 1, true, '2026-10-05 10:00:00', 4],
            [208, 2, false, '2026-10-05 11:00:00', 5],
            // Another missed call of the same entity is no follow-up: both are lost.
            [209, 1, true, '2026-10-06 10:00:00', 6],
            [210, 1, true, '2026-10-06 12:00:00', 6],
            // Missed an hour ago: its 24 hours are not over, not lost yet.
            [211, 1, true, $recent->format('Y-m-d H:i:s'), 7],
        ] as [$id, $direction, $missed, $start, $owner]) {
            $this->db($I)->insert('raw_bitrix_call', [
                'id' => $id, 'direction' => $direction, 'missed' => (int) $missed, 'completed' => 1,
                'start_time' => $start, 'end_time' => $start, 'owner_type_id' => 3, 'owner_id' => 9000 + $owner,
                'responsible_id' => 42, 'payload' => '{}', 'synced_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }

        $lost = BitrixMetricConfig::fromArray(['calculator' => 'calls', 'direction' => 'incoming', 'missed' => true, 'no_follow_up_hours' => 24]);
        $missed = BitrixMetricConfig::fromArray(['calculator' => 'calls', 'direction' => 'incoming', 'missed' => true]);
        $I->assertNotNull($lost);
        $I->assertNotNull($missed);
        $calculator = $I->grabService(BitrixMetricCalculator::class);
        $today = $recent->setTime(0, 0);

        $I->assertSame('4', (string) $calculator->calculate($lost, new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable('2026-10-12')));
        $I->assertSame('6', (string) $calculator->calculate($missed, new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable('2026-10-12')));
        $I->assertSame('0', (string) $calculator->calculate($lost, $today, $today->modify('+2 days')));
        $I->assertSame('1', (string) $calculator->calculate($missed, $today, $today->modify('+2 days')));
    }

    public function followUpWindowNeedsMissedCalls(FunctionalTester $I): void
    {
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => BitrixMetricConfig::fromArray(['calculator' => 'calls', 'no_follow_up_hours' => 24]));
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => BitrixMetricConfig::fromArray(['calculator' => 'calls', 'missed' => true, 'no_follow_up_hours' => 0]));
    }

    private function db(FunctionalTester $I): Connection
    {
        /** @var Connection $db */
        $db = $I->grabService(Connection::class);

        return $db;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\AnalyticsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AnalyticsPeriodsTest extends CIUnitTestCase
{
    public function testDefaultsToTheLastTwentyFourMonthsByMonth(): void
    {
        $f = AnalyticsService::normalise([], '2026-10-20');

        $this->assertSame('2024-11-01', $f['from']);
        $this->assertSame('2026-10-20', $f['to']);
        $this->assertSame('month', $f['gran']);
        $this->assertSame(0, $f['company']);
    }

    public function testBadInputFallsBackToSafeValues(): void
    {
        $f = AnalyticsService::normalise(['from' => 'nope', 'to' => '2030-01-01', 'gran' => 'decade', 'company' => '-4', 'group' => 'x'], '2026-10-20');

        $this->assertSame('2026-10-20', $f['to'], 'the future is capped to today');
        $this->assertSame('2024-11-01', $f['from']);
        $this->assertSame('month', $f['gran']);
        $this->assertSame(0, $f['company']);
        $this->assertSame(0, $f['group']);
    }

    public function testFromAfterToIsReset(): void
    {
        $f = AnalyticsService::normalise(['from' => '2026-12-01', 'to' => '2026-03-31'], '2026-10-20');

        $this->assertSame('2026-03-31', $f['to']);
        $this->assertSame('2024-04-01', $f['from']);
    }

    public function testDayGranularityOnlyWhenAllowed(): void
    {
        $this->assertSame('month', AnalyticsService::normalise(['gran' => 'day'], '2026-10-20')['gran']);
        $this->assertSame('day', AnalyticsService::normalise(['gran' => 'day'], '2026-10-20', true)['gran']);
    }

    public function testPeriodKeys(): void
    {
        $this->assertSame(['2026-01', '2026-02', '2026-03'], AnalyticsService::periods('2026-01-15', '2026-03-02', 'month'));
        $this->assertSame(['2025-Q4', '2026-Q1'], AnalyticsService::periods('2025-11-01', '2026-02-10', 'quarter'));
        $this->assertSame(['2024', '2025', '2026'], AnalyticsService::periods('2024-06-01', '2026-10-20', 'year'));
        $this->assertSame(['2026-10-01', '2026-10-02'], AnalyticsService::periods('2026-10-01', '2026-10-02', 'day'));
    }

    public function testKeysMatchTheSqlBuckets(): void
    {
        $this->assertSame('2026-Q4', AnalyticsService::keyFor('2026-10-20', 'quarter'));
        $this->assertSame('2026-02', AnalyticsService::keyFor('2026-02-28', 'month'));
        $this->assertSame('2026', AnalyticsService::keyFor('2026-02-28', 'year'));
    }

    public function testPriorYear(): void
    {
        $this->assertSame('2025-03', AnalyticsService::priorYear('2026-03'));
        $this->assertSame('2025-Q2', AnalyticsService::priorYear('2026-Q2'));
        $this->assertSame('2025', AnalyticsService::priorYear('2026'));
    }
}

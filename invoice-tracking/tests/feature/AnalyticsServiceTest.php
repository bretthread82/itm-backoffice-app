<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Analytics\AnalyticsService;
use Tests\Support\AnalyticsTestCase;

/**
 * Uses invoicing_test (users and memberships) and invoicing_test_analytics (the facts).
 *
 * @internal
 */
final class AnalyticsServiceTest extends AnalyticsTestCase
{
    private AnalyticsService $svc;
    private int $a;
    private int $b;
    private int $userA;
    private int $userBoth;
    private int $nobody;
    private int $groupUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AnalyticsService($this->analytics);

        $this->a = $this->makeCompany('AAA');
        $this->b = $this->makeCompany('BBB');
        $this->userA     = $this->makeUser('Ann');
        $this->userBoth  = $this->makeUser('Bob');
        $this->nobody    = $this->makeUser('Nobody');
        $this->groupUser = $this->makeUser('Gail');
        $this->join($this->userA, $this->a);
        $this->join($this->userBoth, $this->a);
        $this->join($this->userBoth, $this->b);
        $this->db->table('group_level_users')->insert(['user_id' => $this->groupUser, 'granted_by' => $this->userBoth, 'granted_at' => date('Y-m-d H:i:s')]);

        foreach ([[$this->a, ',1,', 'Standard'], [$this->b, ',2,', 'Standard']] as $i => [$c, $path, $type]) {
            $this->analytics->table('dim_company')->insert(['company_id' => $c, 'code' => $c === $this->a ? 'AAA' : 'BBB', 'name' => 'Co', 'group_id' => $i + 1, 'group_path' => $path, 'active' => 1]);
            $this->analytics->table('dim_invoice_type')->insert(['invoice_type_id' => $c * 10, 'company_id' => $c, 'name' => $type, 'high_priority' => 0]);
        }
        $this->analytics->table('dim_invoice_type')->insert(['invoice_type_id' => $this->a * 10 + 1, 'company_id' => $this->a, 'name' => 'Utilities', 'high_priority' => 0]);
    }

    private int $seq = 0;

    private function fact(int $company, string $submitted, array $over = []): void
    {
        $this->analytics->table('fact_invoice')->insert($over + [
            'invoice_id' => ++$this->seq, 'company_id' => $company, 'invoice_type_id' => $company * 10, 'currency' => 'USD', 'amount' => '100.00',
            'status' => 'paid', 'outstanding' => 0, 'supplier_code' => 'ACM', 'supplier_name' => 'Acme', 'first_submitted_at' => $submitted, 'submitted_at' => $submitted,
            'processed_at' => null, 'paid_at' => null, 'loaded_at' => '2026-10-20 01:30:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function f(array $over = []): array
    {
        return array_merge(AnalyticsService::normalise(['from' => '2026-01-01', 'to' => '2026-06-30'], '2026-10-20'), $over);
    }

    public function testEachUserSeesOnlyTheirCompanies(): void
    {
        $this->fact($this->a, '2026-03-05 10:00:00');
        $this->fact($this->b, '2026-03-06 10:00:00', ['amount' => '900.00']);

        $count = fn (int $uid): int => array_sum(array_map(static fn (array $c): int => $c['submitted'][0] ?? 0, $this->svc->volume($uid, $this->f())['rows']['2026-03'] ?? []));

        $this->assertSame(1, $count($this->userA));
        $this->assertSame(2, $count($this->userBoth));
        $this->assertSame(2, $count($this->groupUser), 'group-level users see every company');
        $this->assertSame(0, $count($this->nobody), 'a user with no company sees nothing');
    }

    public function testNarrowingNeverWidensAccess(): void
    {
        $this->fact($this->b, '2026-03-06 10:00:00');

        $v = $this->svc->volume($this->userA, $this->f(['company' => $this->b]));

        $this->assertSame([], $v['rows'], 'asking for a company you are not in returns nothing');
    }

    public function testVolumeKeepsCurrenciesApartAndSkipsCancelled(): void
    {
        $this->fact($this->a, '2026-03-05 10:00:00');
        $this->fact($this->a, '2026-03-07 10:00:00', ['currency' => 'NZD', 'amount' => '250.50']);
        $this->fact($this->a, '2026-03-08 10:00:00', ['status' => 'cancelled', 'amount' => '999.00']);
        $this->fact($this->a, '2026-03-09 10:00:00', ['processed_at' => '2026-04-02 10:00:00', 'paid_at' => '2026-04-03 10:00:00']);

        $v = $this->svc->volume($this->userA, $this->f());

        $this->assertSame(['NZD', 'USD'], $v['currencies']);
        $this->assertSame([1, 250.5], $v['rows']['2026-03']['NZD']['submitted']);
        $this->assertSame([2, 200.0], $v['rows']['2026-03']['USD']['submitted']);
        $this->assertSame([1, 100.0], $v['rows']['2026-04']['USD']['paid']);
        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'], $v['periods']);
    }

    public function testVolumeIncludesThePriorYearForComparison(): void
    {
        $this->fact($this->a, '2025-03-05 10:00:00', ['amount' => '40.00']);
        $this->fact($this->a, '2026-03-05 10:00:00');

        $v = $this->svc->volume($this->userA, $this->f());

        $this->assertNotContains('2025-03', $v['periods']);
        $this->assertSame([1, 40.0], $v['rows'][AnalyticsService::priorYear('2026-03')]['USD']['submitted']);
    }

    public function testFiltersByGroupTypeAndSupplier(): void
    {
        $this->fact($this->a, '2026-03-05 10:00:00');
        $this->fact($this->a, '2026-03-06 10:00:00', ['invoice_type_id' => $this->a * 10 + 1, 'supplier_code' => 'UTL', 'supplier_name' => 'City Power']);
        $this->fact($this->b, '2026-03-07 10:00:00');

        $n = fn (array $over): int => $this->svc->volume($this->userBoth, $this->f($over))['rows']['2026-03']['USD']['submitted'][0] ?? 0;

        $this->assertSame(3, $n([]));
        $this->assertSame(2, $n(['group' => 1]), 'sub-group 1 holds company A');
        $this->assertSame(1, $n(['type' => 'Utilities']));
        $this->assertSame(1, $n(['supplier' => 'UTL']));
        $this->assertSame(1, $n(['supplier' => 'City Power']), 'name matches too');
    }

    public function testTopSuppliersAreRankedPerCurrency(): void
    {
        $this->fact($this->a, '2026-03-05 10:00:00', ['amount' => '10.00']);
        $this->fact($this->a, '2026-03-06 10:00:00', ['amount' => '500.00', 'supplier_code' => 'BIG', 'supplier_name' => 'Big Co']);
        $this->fact($this->a, '2026-03-07 10:00:00', ['amount' => '5.00', 'currency' => 'NZD']);

        $top = $this->svc->topSuppliers($this->userA, $this->f());

        $this->assertSame('Big Co', $top['USD'][0]['supplier']);
        $this->assertSame(500.0, $top['USD'][0]['amount']);
        $this->assertSame('Acme', $top['USD'][1]['supplier']);
        $this->assertSame(['NZD', 'USD'], array_keys($top));
    }

    public function testCycleTimeAndReworkRates(): void
    {
        $this->fact($this->a, '2026-03-01 09:00:00', ['processed_at' => '2026-03-05 09:00:00', 'days_to_processed' => '4.00', 'days_to_paid' => '6.00', 'on_time' => 1, 'return_count' => 1, 'resubmit_count' => 1]);
        $this->fact($this->a, '2026-03-02 09:00:00', ['processed_at' => '2026-03-12 09:00:00', 'days_to_processed' => '10.00', 'days_to_paid' => null, 'on_time' => 0, 'override_count' => 1]);

        $c = $this->svc->cycle($this->userA, $this->f())['rows']['2026-03'];

        $this->assertSame(2, $c['done']);
        $this->assertSame(7.0, $c['avg_processed']);
        $this->assertSame(6.0, $c['avg_paid'], 'averages only the invoices that were paid');
        $this->assertSame(50.0, $c['on_time_pct']);
        $this->assertSame(50.0, $c['returned_pct']);
        $this->assertSame(50.0, $c['overridden_pct']);
    }

    public function testStepStatsRankSlowestFirstAndCountSlaBreaches(): void
    {
        $this->fact($this->a, '2026-03-01 09:00:00');
        $this->fact($this->a, '2026-03-01 09:00:00');
        $step = fn (int $inv, string $type, string $label, float $hours, int $breach, string $outcome = 'approved') => $this->analytics->table('fact_step')->insert([
            'invoice_id' => $inv, 'company_id' => $this->a, 'cycle' => 1, 'step_order' => 2, 'step_type' => $type, 'assignee_label' => $label, 'entered_at' => '2026-03-02 09:00:00',
            'duration_hours' => $hours, 'outcome' => $outcome, 'sla_days' => 1, 'sla_breached' => $breach, 'override' => 0, 'acted_by_name' => 'Alan',
        ]);
        $step(1, 'approve', 'Manager', 48.0, 1);
        $step(2, 'approve', 'Manager', 24.0, 1, 'returned');
        $step(1, 'process', 'AP team', 2.0, 0);

        $by = $this->svc->stepStats($this->userA, $this->f(), 'type');

        $this->assertSame(['approve', 'process'], array_column($by, 'label'));
        $this->assertSame(36.0, $by[0]['avg_hours']);
        $this->assertSame(48.0, $by[0]['max_hours']);
        $this->assertSame([2, 2, 1], [$by[0]['breached'], $by[0]['with_sla'], $by[0]['sent_back']]);
        $this->assertSame('Manager', $this->svc->stepStats($this->userA, $this->f(), 'assignee')[0]['label']);
        $this->assertSame([], $this->svc->stepStats($this->nobody, $this->f(), 'type'));
    }

    public function testBacklogKeepsTheLastSnapshotOfEachPeriodAndFlagsEstimates(): void
    {
        $row = fn (string $date, int $company, int $n, int $est) => $this->analytics->table('snap_backlog_daily')->insert([
            'snapshot_date' => $date, 'company_id' => $company, 'currency' => 'USD', 'age_band' => 'current', 'invoice_count' => $n, 'amount' => $n * 100, 'reconstructed' => $est,
        ]);
        $row('2026-02-27', $this->a, 1, 1);
        $row('2026-02-28', $this->a, 2, 1);
        $row('2026-02-28', $this->b, 5, 1);
        $row('2026-03-30', $this->a, 4, 0);

        $bl = $this->svc->backlog($this->userBoth, $this->f(['gran' => 'month']));

        $this->assertSame(['2026-02-28', '2026-03-30'], array_column($bl['points'], 'date'));
        $this->assertSame([true, false], array_column($bl['points'], 'estimated'));
        $this->assertSame([7, 700.0], $bl['points'][0]['by']['USD']['current'], 'both companies on the last February day');

        $mine = $this->svc->backlog($this->userA, $this->f(['gran' => 'month']));
        $this->assertSame([2, 200.0], $mine['points'][0]['by']['USD']['current']);
        $this->assertSame(['2026-02-27', '2026-02-28', '2026-03-30'], array_column($this->svc->backlog($this->userA, $this->f(['gran' => 'day']))['points'], 'date'));
    }

    public function testStatusAndTypeNames(): void
    {
        $this->assertFalse($this->svc->status()['has_data']);
        $this->fact($this->a, '2026-03-05 10:00:00');
        $this->analytics->table('etl_state')->insert(['source' => 'invoices', 'watermark' => null, 'last_run' => '2026-10-20 01:30:00', 'rows_processed' => 1]);

        $s = $this->svc->status();
        $this->assertTrue($s['has_data']);
        $this->assertSame('2026-10-20 01:30:00', $s['last_run']);
        $this->assertSame('2026-03-05', $s['since']);
        $this->assertSame(['Standard', 'Utilities'], $this->svc->typeNames($this->userA));
        $this->assertSame([], $this->svc->typeNames($this->nobody));
    }
}

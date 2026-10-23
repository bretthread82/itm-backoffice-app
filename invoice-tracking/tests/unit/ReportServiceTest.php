<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdminAccess;
use App\Services\ReportService;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class ReportServiceTest extends AppTestCase
{
    private const TODAY = '2026-10-10';

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new ReportService($this->db);
    }

    /** Invoice with the given money, currency, due date and status. */
    private function inv(int $company, int $by, string $status, string $amount, string $currency, ?string $due, string $no = 'N'): int
    {
        $id = $this->makeInvoice($company, $by, $status);
        $this->db->table('invoices')->where('id', $id)->update(['amount' => $amount, 'currency' => $currency, 'due_date' => $due, 'invoice_number' => $no, 'supplier' => 'Sup']);

        return $id;
    }

    /** A date N days before TODAY. */
    private function ago(int $days): string
    {
        return date('Y-m-d', strtotime(self::TODAY) - $days * 86400);
    }

    // -------------------------------------------------------------- aging boundaries

    public function testBandBoundaries(): void
    {
        foreach ([0 => 'current', 1 => '1-30', 30 => '1-30', 31 => '31-60', 60 => '31-60', 61 => '61-90', 90 => '61-90', 91 => '90+', 400 => '90+'] as $days => $band) {
            $this->assertSame($band, ReportService::bandFor($days), "{$days} days");
        }
    }

    public function testCriticalStartsAtSixtyOne(): void
    {
        $this->assertFalse(ReportService::isCritical(60));
        $this->assertTrue(ReportService::isCritical(61));
    }

    public function testDaysPastDue(): void
    {
        $this->assertSame(0, ReportService::daysPastDue(self::TODAY, self::TODAY));        // due today: not late
        $this->assertSame(0, ReportService::daysPastDue('2026-10-11', self::TODAY));       // due tomorrow
        $this->assertSame(1, ReportService::daysPastDue('2026-10-09', self::TODAY));
        $this->assertSame(61, ReportService::daysPastDue($this->ago(61), self::TODAY));
        $this->assertSame(0, ReportService::daysPastDue(null, self::TODAY));
        $this->assertSame(0, ReportService::daysPastDue('', self::TODAY));
    }

    // -------------------------------------------------------------- what counts as outstanding

    public function testOnlySubmittedAndUnfinishedInvoicesAreOutstanding(): void
    {
        $co = $this->makeCompany('ACME');
        $u  = $this->makeUser('Sue');
        $this->join($u, $co);

        foreach (['draft', 'in_approval', 'approved', 'returned', 'rejected', 'paid', 'cancelled'] as $i => $status) {
            $this->inv($co, $u, $status, '100', 'USD', $this->ago(5), "S{$i}");
        }

        $rows = $this->reports->outstanding($u, [], self::TODAY);
        $this->assertEqualsCanonicalizing(['in_approval', 'approved', 'returned'], array_column($rows, 'status'));

        $counts = $this->reports->countsByStatus($u);
        $this->assertSame(7, array_sum($counts));
        $this->assertSame(5, ReportService::totalSubmitted($counts)); // not draft, not cancelled
    }

    public function testRowsCarryDaysPastDueBandAndAreMostOverdueFirst(): void
    {
        $co = $this->makeCompany('ACME');
        $u  = $this->makeUser('Sue');
        $this->join($u, $co);
        $a = $this->inv($co, $u, 'in_approval', '10', 'USD', $this->ago(5), 'A');
        $b = $this->inv($co, $u, 'in_approval', '10', 'USD', $this->ago(75), 'B');
        $c = $this->inv($co, $u, 'in_approval', '10', 'USD', '2026-12-01', 'C');

        $rows = $this->reports->outstanding($u, [], self::TODAY);

        $this->assertSame([$b, $a, $c], array_map('intval', array_column($rows, 'id')));
        $this->assertSame([75, 5, 0], array_column($rows, 'days_past_due'));
        $this->assertSame(['61-90', '1-30', 'current'], array_column($rows, 'band'));
    }

    // -------------------------------------------------------------- aggregation

    public function testAggregateTotalsBandsAndCurrenciesWithoutMixingThem(): void
    {
        $rows = [
            $this->row(1, 'ACME', 'USD', '100.00', 0),
            $this->row(2, 'ACME', 'USD', '50.50', 10),
            $this->row(3, 'ACME', 'BBD', '200.00', 61),
            $this->row(4, 'BETA', 'USD', '1000.00', 120),
        ];

        $a = ReportService::aggregate($rows);

        $this->assertSame(4, $a['total']['count']);
        $this->assertSame(['USD' => 1150.5, 'BBD' => 200.0], $a['total']['amount']);
        $this->assertSame(3, $a['overdue']['count']);
        $this->assertSame(2, $a['critical']['count']);                    // 61 and 120
        $this->assertSame(['BBD' => 200.0, 'USD' => 1000.0], $a['critical']['amount']);

        $this->assertSame(1, $a['bands']['current']['count']);
        $this->assertSame(1, $a['bands']['1-30']['count']);
        $this->assertSame(0, $a['bands']['31-60']['count']);
        $this->assertSame(1, $a['bands']['61-90']['count']);
        $this->assertSame(1, $a['bands']['90+']['count']);

        $counts = array_map(static fn (array $c): int => $c['count'], $a['companies']);
        ksort($counts); // order is "most urgent first", checked in testCompaniesAreOrderedMostUrgentFirst
        $this->assertSame(['ACME' => 3, 'BETA' => 1], $counts);
        $this->assertSame(1, $a['companies']['ACME']['critical']);
        $this->assertSame(120, $a['companies']['BETA']['worst']);
    }

    public function testCompaniesAreOrderedMostUrgentFirst(): void
    {
        $a = ReportService::aggregate([
            $this->row(1, 'CALM', 'USD', '9999', 0),
            $this->row(2, 'CALM', 'USD', '9999', 0),
            $this->row(3, 'LATE', 'USD', '1', 200),
            $this->row(4, 'MID', 'USD', '1', 20),
        ]);

        $this->assertSame(['LATE', 'MID', 'CALM'], array_keys($a['companies']));
    }

    public function testOldestListHasOnlyOverdueSortedAndCapped(): void
    {
        $rows = [];
        foreach ([0, 5, 90, 30, 200, 61] as $i => $d) {
            $rows[] = $this->row($i + 1, 'ACME', 'USD', '10', $d);
        }

        $a = ReportService::aggregate($rows, 3);

        $this->assertSame([200, 90, 61], array_column($a['oldest'], 'days_past_due'));
    }

    public function testFormatAmounts(): void
    {
        $this->assertSame('—', ReportService::formatAmounts([]));
        $this->assertSame('BBD 5.00 · USD 1,234.50', ReportService::formatAmounts(['USD' => 1234.5, 'BBD' => 5.0]));
    }

    // -------------------------------------------------------------- scoping and filters

    public function testNumbersOnlyIncludeWhatTheUserMaySee(): void
    {
        $acme = $this->makeCompany('ACME');
        $beta = $this->makeCompany('BETA');
        $by   = $this->makeUser('Sub');
        $this->inv($acme, $by, 'in_approval', '100', 'USD', $this->ago(10), 'A1');
        $this->inv($beta, $by, 'in_approval', '900', 'USD', $this->ago(100), 'B1');

        $member = $this->makeUser('Member of ACME');
        $this->join($member, $acme);
        $nobody = $this->makeUser('Nobody');
        $group  = $this->makeUser('Group level');
        (new AdminAccess($this->db))->grantGroupLevel($this->makeUser('Admin', true), $group);

        $mine = ReportService::aggregate($this->reports->outstanding($member, [], self::TODAY));
        $this->assertSame(1, $mine['total']['count']);
        $this->assertSame(['USD' => 100.0], $mine['total']['amount']);
        $this->assertSame(['ACME'], array_keys($mine['companies']));

        $this->assertSame([], $this->reports->outstanding($nobody, [], self::TODAY));
        $this->assertSame([], $this->reports->countsByStatus($nobody));

        $all = ReportService::aggregate($this->reports->outstanding($group, [], self::TODAY));
        $this->assertSame(2, $all['total']['count']);
        $this->assertSame(['USD' => 1000.0], $all['total']['amount']);
        $this->assertSame(1, $all['critical']['count']);
    }

    public function testDueWithinCountsOnlyInvoicesDueInTheWindow(): void
    {
        $rows = [
            $this->row(1, 'A', 'USD', '10.00', 0) + ['due_date' => '2026-10-10'],   // today: in
            $this->row(2, 'A', 'USD', '20.00', 0) + ['due_date' => '2026-11-09'],   // day 30: in
            $this->row(3, 'A', 'USD', '40.00', 0) + ['due_date' => '2026-11-10'],   // day 31: out
            $this->row(4, 'A', 'USD', '80.00', 5) + ['due_date' => '2026-10-05'],   // already late: out
            $this->row(5, 'A', 'BBD', '5.00', 0) + ['due_date' => null],            // no date: out
        ];

        $due = ReportService::dueWithin($rows, 30, self::TODAY);

        $this->assertSame(2, $due['count']);
        $this->assertSame(['USD' => 30.0], $due['amount']);
    }

    public function testMonthlyTrendCountsSubmittedCompletedAndStillOutstandingPerMonth(): void
    {
        $acme = $this->makeCompany('ACME');
        $by   = $this->makeUser('Sub');
        $a    = $this->inv($acme, $by, 'in_approval', '100', 'USD', '2026-09-01', 'T1');   // submitted Sept, overdue
        $b    = $this->inv($acme, $by, 'paid', '100', 'USD', '2026-09-01', 'T2');
        $c    = $this->inv($acme, $by, 'draft', '100', 'USD', null, 'T3');                 // drafts never count
        $this->db->table('invoices')->where('id', $a)->update(['submitted_at' => '2026-09-05 10:00:00']);
        $this->db->table('invoices')->where('id', $b)->update(['submitted_at' => '2026-08-20 10:00:00', 'completed_at' => '2026-09-12 10:00:00']);
        $this->db->table('invoices')->where('id', $c)->update(['submitted_at' => '2026-09-06 10:00:00']);
        $member = $this->makeUser('Member');
        $this->join($member, $acme);

        $trend = array_column($this->reports->monthlyTrend($member, [], 3, self::TODAY), null, 'month');

        $this->assertSame(['2026-08', '2026-09', '2026-10'], array_keys($trend));
        $this->assertSame(1, $trend['2026-08']['submitted']);
        $this->assertSame(1, $trend['2026-09']['submitted']);
        $this->assertSame(1, $trend['2026-09']['completed']);
        $this->assertSame(1, $trend['2026-09']['pending']);
        $this->assertSame(1, $trend['2026-09']['overdue']);
        $this->assertSame(0, $this->reports->monthlyTrend($this->makeUser('Nobody'), [], 1, self::TODAY)[0]['submitted']);
    }

    public function testCompletionStatsAverageDaysAndOnTimeRate(): void
    {
        $acme = $this->makeCompany('ACME');
        $by   = $this->makeUser('Sub');
        $early = $this->inv($acme, $by, 'paid', '1', 'USD', '2026-09-30', 'C1');
        $late  = $this->inv($acme, $by, 'paid', '1', 'USD', '2026-09-10', 'C2');
        $this->db->table('invoices')->where('id', $early)->update(['submitted_at' => '2026-09-01 00:00:00', 'completed_at' => '2026-09-11 00:00:00']);   // 10 days, on time
        $this->db->table('invoices')->where('id', $late)->update(['submitted_at' => '2026-09-01 00:00:00', 'completed_at' => '2026-09-21 00:00:00']);    // 20 days, late
        $member = $this->makeUser('Member');
        $this->join($member, $acme);

        $stats = $this->reports->completionStats($member);

        $this->assertSame(2, $stats['completed']);
        $this->assertSame(15.0, $stats['avg_days']);
        $this->assertSame(2, $stats['with_due']);
        $this->assertSame(1, $stats['on_time']);
        $this->assertSame(0, $this->reports->completionStats($this->makeUser('Nobody'))['completed']);
    }

    public function testActivityFeedIsScopedToVisibleCompanies(): void
    {
        $acme = $this->makeCompany('ACME');
        $beta = $this->makeCompany('BETA');
        $by   = $this->makeUser('Sub');
        $mine = $this->inv($acme, $by, 'in_approval', '1', 'USD', null, 'MINE-1');
        $theirs = $this->inv($beta, $by, 'in_approval', '1', 'USD', null, 'THEIRS-1');
        foreach ([$mine, $theirs] as $id) {
            $this->db->table('invoice_actions')->insert(['invoice_id' => $id, 'acted_by' => $by, 'action' => 'submit', 'acted_at' => date('Y-m-d H:i:s')]);
        }
        $member = $this->makeUser('Member');
        $this->join($member, $acme);

        $feed = (new \App\Services\ActivityFeed($this->db))->recent($member);

        $this->assertCount(1, $feed);
        $this->assertStringContainsString('MINE-1', $feed[0]['text']);
        $this->assertSame([], (new \App\Services\ActivityFeed($this->db))->recent($this->makeUser('Nobody')));
    }

    public function testCompanyFilterCannotWidenAccess(): void
    {
        $acme = $this->makeCompany('ACME');
        $beta = $this->makeCompany('BETA');
        $by   = $this->makeUser('Sub');
        $this->inv($beta, $by, 'in_approval', '900', 'USD', $this->ago(100), 'B1');
        $member = $this->makeUser('Member of ACME');
        $this->join($member, $acme);

        $this->assertSame([], $this->reports->outstanding($member, ['company' => $beta], self::TODAY));
    }

    public function testSubGroupFilterIncludesChildGroupsButStaysWithinAccess(): void
    {
        $this->db->table('company_groups')->insert(['name' => 'Parent']);
        $parent = (int) $this->db->insertID();
        $this->db->table('company_groups')->insert(['name' => 'Child', 'parent_id' => $parent]);
        $child = (int) $this->db->insertID();
        $this->db->table('company_groups')->insert(['name' => 'Other']);
        $other = (int) $this->db->insertID();

        $inParent = $this->makeCompany('PAR', $parent);
        $inChild  = $this->makeCompany('CHI', $child);
        $inOther  = $this->makeCompany('OTH', $other);
        $by       = $this->makeUser('Sub');
        foreach ([$inParent, $inChild, $inOther] as $i => $co) {
            $this->inv($co, $by, 'in_approval', '10', 'USD', $this->ago(1), "G{$i}");
        }

        $user = $this->makeUser('Member of all three');
        foreach ([$inParent, $inChild, $inOther] as $co) {
            $this->join($user, $co);
        }

        $codes = array_column($this->reports->outstanding($user, ['group' => $parent], self::TODAY), 'company_code');
        $this->assertEqualsCanonicalizing(['PAR', 'CHI'], $codes);
        $this->assertEqualsCanonicalizing([$parent, $child], $this->reports->groupIds($parent));

        $aggregate = ReportService::aggregate($this->reports->outstanding($user, [], self::TODAY));
        $this->assertSame(['Child', 'Other', 'Parent'], array_map(static fn (array $g): string => $g['name'], array_values(self::sortedBy($aggregate['groups'], 'name'))));
    }

    public function testVisibleCompaniesAreScoped(): void
    {
        $acme = $this->makeCompany('ACME');
        $this->makeCompany('BETA');
        $u = $this->makeUser('Member');
        $this->join($u, $acme);

        $this->assertSame(['ACME'], array_column($this->reports->visibleCompanies($u), 'code'));
    }

    public function testCurrentStepAndDaysWaitingAreIncluded(): void
    {
        $co = $this->makeCompany('ACME');
        $u  = $this->makeUser('Sue');
        $this->join($u, $co);
        $id = $this->inv($co, $u, 'in_approval', '10', 'USD', $this->ago(3), 'W');
        $snapshot = json_encode(['steps' => [['order' => 2, 'step_type' => 'approve', 'role' => 'Accountant', 'label' => 'Alan', 'assignee_kind' => 'user', 'user_id' => $u]]]);
        $this->db->table('invoices')->where('id', $id)->update(['route_snapshot' => $snapshot, 'current_step_order' => 2, 'submitted_at' => $this->ago(9) . ' 08:00:00']);

        $row = $this->reports->outstanding($u, [], self::TODAY)[0];

        $this->assertSame('Accountant (Alan)', $row['step_label']);
        $this->assertGreaterThanOrEqual(9, $row['days_waiting']);
    }

    // -------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function row(int $id, string $company, string $currency, string $amount, int $days): array
    {
        return [
            'id' => $id, 'company_code' => $company, 'company_name' => $company . ' Ltd', 'company_id' => $id, 'group_name' => null,
            'currency' => $currency, 'amount' => $amount, 'days_past_due' => $days, 'invoice_number' => 'N' . $id, 'supplier' => 'S',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private static function sortedBy(array $items, string $key): array
    {
        uasort($items, static fn (array $a, array $b): int => $a[$key] <=> $b[$key]);

        return $items;
    }
}

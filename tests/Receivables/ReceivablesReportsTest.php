<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\ArReportService;
use App\Core\Receivables\PaymentService;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * The receivables reports: outstanding and ageing, outstanding by customer and collections by month. Numbers are checked
 * against hand-worked figures, scoped to the viewer's companies, and never added across currencies.
 *
 * @internal
 */
final class ReceivablesReportsTest extends ReceivablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private ArReportService $reports;
    private int $company;
    private int $other;
    private int $reader;
    private int $outsider;
    private int $raiser;
    private int $collector;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->reports   = new ArReportService($this->db);
        $this->company   = $this->makeCompany('ACME');
        $this->other     = $this->makeCompany('BETA');
        $this->reader    = $this->makeUserNamed('Rhea Reader', 'rhea@example.test');
        $this->outsider  = $this->makeUserNamed('Olga Outsider', 'olga@example.test');
        $this->raiser    = $this->makeUserNamed('Rae Raiser', 'rae@example.test');
        $this->collector = $this->makeUserNamed('Cal Collector', 'cal@example.test');

        $this->grant($this->reader, $this->company, []);
        $this->grant($this->outsider, $this->other, []);
        $this->grant($this->raiser, $this->company, ['submit']);
        $this->grant($this->collector, $this->company, ['collect']);
        foreach ([$this->reader, $this->outsider, $this->collector] as $u) {
            $this->db->table('user_roles')->insert(['user_id' => $u, 'role_id' => $this->roleId('receivables_user')]);
            service('permissions')->forget($u);
        }
        $this->today = service('timezone')->todayFor($this->company);
    }

    private function ago(int $days): string
    {
        return date('Y-m-d', strtotime($this->today . " -{$days} days"));
    }

    /** @param array<string, mixed> $over */
    private function owed(int $dueAgo, string $amount = '100.00', array $over = []): int
    {
        return $this->makeIssued($this->company, $this->raiser, $over + ['due_date' => $this->ago($dueAgo), 'amount' => $amount]);
    }

    // ------------------------------------------------------------------ outstanding and ageing

    public function testOutstandingIsTheBalanceWithDaysPastDueAndTheBand(): void
    {
        $late    = $this->owed(45, '200.00', ['status' => 'part_paid', 'amount_paid' => '50.00']);
        $current = $this->owed(-5, '80.00');
        $this->owed(10, '999.00', ['status' => 'paid', 'amount_paid' => '999.00']);
        $this->owed(10, '999.00', ['status' => 'draft']);

        $rows = $this->reports->outstanding($this->reader);

        $this->assertSame([$late, $current], array_map('intval', array_column($rows, 'id')), 'Most overdue first; paid and unissued are not outstanding.');
        $this->assertSame('150.00', $rows[0]['balance']);
        $this->assertSame(45, $rows[0]['days_past_due']);
        $this->assertSame('31-60', $rows[0]['band']);
        $this->assertSame(0, $rows[1]['days_past_due']);
        $this->assertSame('current', $rows[1]['band']);
    }

    public function testAgeBandsAreCutAtTheRightDays(): void
    {
        foreach ([1 => '1-30', 30 => '1-30', 31 => '31-60', 60 => '31-60', 61 => '61-90', 90 => '61-90', 91 => '90+'] as $days => $band) {
            $id  = $this->owed($days);
            $row = array_values(array_filter($this->reports->outstanding($this->reader), static fn (array $r): bool => (int) $r['id'] === $id))[0];

            $this->assertSame($band, $row['band'], "{$days} days past due");
        }
    }

    public function testAggregateTotalsPerBandAndCurrencyNeverAddingAcrossCurrencies(): void
    {
        $this->owed(5, '100.00');                                      // USD 1-30
        $this->owed(70, '300.00', ['amount_paid' => '100.00']);        // USD 61-90, balance 200, critical
        $this->owed(5, '40.00', ['currency' => 'EUR']);                // EUR 1-30
        $this->owed(-3, '10.00');                                      // USD not yet due

        $agg = ArReportService::aggregate($this->reports->outstanding($this->reader));

        $this->assertSame(4, $agg['total']['count']);
        $this->assertSame(['EUR' => 40.0, 'USD' => 310.0], $this->sorted($agg['total']['amount']));
        $this->assertSame(3, $agg['overdue']['count']);
        $this->assertSame(['EUR' => 40.0, 'USD' => 300.0], $this->sorted($agg['overdue']['amount']));
        $this->assertSame(1, $agg['critical']['count']);
        $this->assertSame(['USD' => 200.0], $agg['critical']['amount']);
        $this->assertSame(['count' => 2, 'amount' => ['USD' => 100.0, 'EUR' => 40.0]], $agg['bands']['1-30'] + []);
        $this->assertSame(1, $agg['bands']['61-90']['count']);
        $this->assertSame(1, $agg['bands']['current']['count']);
        $this->assertSame(0, $agg['bands']['90+']['count']);
        $this->assertSame(70, $agg['companies']['ACME']['worst']);
        $this->assertSame(1, $agg['companies']['ACME']['critical']);
        $this->assertSame(70, $agg['oldest'][0]['days_past_due']);
    }

    /** @param array<string, float> $m @return array<string, float> */
    private function sorted(array $m): array
    {
        ksort($m);

        return $m;
    }

    public function testOnlyTheViewersCompaniesAreCounted(): void
    {
        $this->owed(5, '100.00');
        $this->makeIssued($this->other, $this->raiser, ['due_date' => $this->ago(5), 'amount' => '777.00']);

        $mine   = ArReportService::aggregate($this->reports->outstanding($this->reader));
        $theirs = ArReportService::aggregate($this->reports->outstanding($this->outsider));

        $this->assertSame(['USD' => 100.0], $mine['total']['amount']);
        $this->assertSame(['USD' => 777.0], $theirs['total']['amount']);
        $this->assertSame(['USD' => 777.0], ArReportService::aggregate($this->reports->outstanding($this->outsider, ['company' => $this->other]))['total']['amount']);
        $this->assertSame([], $this->reports->outstanding($this->reader, ['company' => $this->other]), 'A company you cannot see stays empty even if asked for.');
    }

    public function testTheSubGroupFilterIncludesChildGroups(): void
    {
        $this->db->table('company_groups')->insert(['name' => 'Parent group']);
        $parent = (int) $this->db->insertID();
        $this->db->table('company_groups')->insert(['name' => 'Child group', 'parent_id' => $parent]);
        $child = (int) $this->db->insertID();
        $this->db->table('companies')->where('id', $this->company)->update(['group_id' => $child]);
        $this->owed(5, '100.00');

        $this->assertCount(1, $this->reports->outstanding($this->reader, ['group' => $parent]));
        $this->assertSame([], $this->reports->outstanding($this->reader, ['group' => $parent + 1000]));
        $this->assertSame('Child group', $this->reports->outstanding($this->reader)[0]['group_name']);
    }

    // ------------------------------------------------------------------ by customer

    public function testOutstandingByCustomerGroupsBySharedCustomerThenByTypedName(): void
    {
        $acme = service('customers')->create(['code' => 'C1', 'name' => 'Harbour Foods']);
        $this->owed(5, '100.00', ['customer_id' => $acme, 'customer' => 'Harbour Foods']);
        $this->owed(70, '50.00', ['customer_id' => $acme, 'customer' => 'Harbour Foods (old name)']);
        $this->owed(5, '30.00', ['customer' => 'Walk-in']);
        $this->owed(8, '20.00', ['customer' => 'walk-in']);
        $this->owed(5, '15.00', ['customer' => 'Harbour Foods', 'currency' => 'EUR']);   // same name typed, but not linked: a separate line

        $by = ArReportService::outstandingByCustomer($this->reports->outstanding($this->reader));

        $this->assertCount(3, $by);
        $this->assertSame('Harbour Foods', $by[0]['customer'], 'The one with a critical invoice is first.');
        $this->assertSame('C1', $by[0]['code']);
        $this->assertSame(2, $by[0]['count']);
        $this->assertSame(['USD' => 150.0], $by[0]['amount']);
        $this->assertSame(1, $by[0]['critical']);
        $this->assertSame(70, $by[0]['worst']);

        $walkIn = array_values(array_filter($by, static fn (array $c): bool => strcasecmp($c['customer'], 'Walk-in') === 0))[0];
        $this->assertSame(2, $walkIn['count'], 'Typed names are grouped ignoring case.');
        $this->assertSame(['USD' => 50.0], $walkIn['overdue_amount']);
    }

    public function testInvoicesWithoutACustomerAreShownAsSuch(): void
    {
        $this->owed(5, '10.00', ['customer' => null]);

        $by = ArReportService::outstandingByCustomer($this->reports->outstanding($this->reader));

        $this->assertSame(ArReportService::NO_CUSTOMER, $by[0]['customer']);
    }

    // ------------------------------------------------------------------ collections

    public function testCollectionsByMonthCountsInvoicedAndReceivedNetOfReversals(): void
    {
        $thisMonth = date('Y-m', strtotime($this->today));
        $lastMonth = date('Y-m', strtotime(date('Y-m-01', strtotime($this->today)) . ' -1 month'));
        $invoice   = $this->owed(-30, '500.00', ['issued_at' => $lastMonth . '-10 09:00:00']);
        $this->owed(-30, '60.00', ['issued_at' => $thisMonth . '-02 09:00:00', 'currency' => 'EUR']);
        $this->owed(-30, '999.00', ['issued_at' => date('Y-m-d H:i:s', strtotime($this->today . ' -3 years'))]);     // outside the window
        $this->owed(-30, '999.00', ['status' => 'cancelled', 'issued_at' => $lastMonth . '-11 09:00:00']);           // cancelled is not invoiced

        $payments = new PaymentService($this->db);
        $first    = $payments->record($invoice, $this->collector, ['amount' => '200.00', 'received_date' => $this->today]);
        $payments->record($invoice, $this->collector, ['amount' => '100.00', 'received_date' => $this->today]);
        $payments->reverse($first, $this->collector, 'Bounced');

        $data   = $this->reports->collectionsByMonth($this->reader);
        $months = array_column($data['months'], null, 'month');

        $this->assertCount(12, $data['months']);
        $this->assertSame(end($data['months'])['month'], $thisMonth);
        $this->assertSame(['EUR', 'USD'], $data['currencies']);

        $this->assertSame(1, $months[$lastMonth]['invoiced_count']);
        $this->assertSame(['USD' => 500.0], $months[$lastMonth]['invoiced']);
        $this->assertSame(['EUR' => 60.0], $months[$thisMonth]['invoiced']);
        $this->assertSame(['USD' => 100.0], $months[$thisMonth]['received'], '200 + 100 - 200 reversed.');
        $this->assertSame(2, $months[$thisMonth]['received_count'], 'Two receipts; the reversal is not a payment.');
    }

    public function testCollectionsAreScopedToTheViewer(): void
    {
        $other = $this->makeIssued($this->other, $this->raiser, ['due_date' => $this->ago(-30), 'amount' => '300.00', 'issued_at' => date('Y-m-d H:i:s')]);
        $this->grant($this->collector, $this->other, ['collect']);
        (new PaymentService($this->db))->record($other, $this->collector, ['amount' => '300.00', 'received_date' => $this->today]);

        $mine = $this->reports->collectionsByMonth($this->reader);
        $this->assertSame([], $mine['currencies']);

        $theirs = $this->reports->collectionsByMonth($this->outsider);
        $this->assertSame(['USD'], $theirs['currencies']);
        $this->assertSame(['USD' => 300.0], end($theirs['months'])['received']);
    }

    public function testPaymentStatsAverageDaysToPayAndOnTimeShare(): void
    {
        $issued = date('Y-m-d H:i:s', strtotime($this->today . ' -20 days 10:00:00'));
        // Paid after 10 days, on time (due in 15 days); paid after 20 days, late (due 5 days after issue).
        $this->owed(0, '100.00', ['status' => 'paid', 'amount_paid' => '100.00', 'issued_at' => $issued, 'paid_at' => date('Y-m-d H:i:s', strtotime($issued . ' +10 days')), 'due_date' => date('Y-m-d', strtotime($issued . ' +15 days'))]);
        $this->owed(0, '100.00', ['status' => 'paid', 'amount_paid' => '100.00', 'issued_at' => $issued, 'paid_at' => date('Y-m-d H:i:s', strtotime($issued . ' +20 days')), 'due_date' => date('Y-m-d', strtotime($issued . ' +5 days'))]);
        $this->owed(0, '100.00');   // not paid: ignored

        $stats = $this->reports->paymentStats($this->reader);

        $this->assertSame(['paid' => 2, 'avg_days' => 15.0, 'with_due' => 2, 'on_time' => 1], $stats);
        $this->assertSame(['paid' => 0, 'avg_days' => null, 'with_due' => 0, 'on_time' => 0], $this->reports->paymentStats($this->outsider));
    }

    // ------------------------------------------------------------------ the pages

    private function as(int $userId, string $path)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();
        db_connect()->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        return $this->withSession(['user' => ['id' => $userId]])->get($path);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function pages(): iterable
    {
        foreach (['receivables/reports', 'receivables/reports/customers', 'receivables/reports/collections'] as $p) {
            yield $p => [$p];
        }
    }

    /**
     * @dataProvider pages
     */
    public function testReportPagesRenderForSomeoneWithReportAccess(string $path): void
    {
        $this->owed(12, '120.00', ['invoice_number' => 'AR-RPT', 'customer' => 'Harbour Foods']);

        $result = $this->as($this->reader, $path);

        $this->assertTrue($result->isOK(), "GET /{$path} returned " . $result->response()->getStatusCode());
        $result->assertSee('Outstanding &amp; ageing');
        $result->assertSee('By customer');
        $result->assertSee('Collections');
        $this->assertStringNotContainsString('style="', (string) $result->response()->getBody(), 'The strict CSP blocks inline styles.');
    }

    public function testTheAgeingPageShowsTheFigures(): void
    {
        $this->owed(70, '300.00', ['invoice_number' => 'AR-OLD']);

        $page = $this->as($this->reader, 'receivables/reports');

        $page->assertSee('AR-OLD');
        $page->assertSee('USD 300.00');
        $page->assertSee('Critical');
        $this->as($this->outsider, 'receivables/reports')->assertDontSee('AR-OLD');
    }

    public function testTheCustomerPageListsWhoOwes(): void
    {
        $this->owed(5, '55.00', ['customer' => 'Harbour Foods']);

        $this->as($this->reader, 'receivables/reports/customers')->assertSee('Harbour Foods');
        $this->as($this->outsider, 'receivables/reports/customers')->assertDontSee('Harbour Foods');
    }

    public function testTheCollectionsPageDrawsAChartInTheChosenCurrency(): void
    {
        $this->owed(-30, '90.00', ['issued_at' => date('Y-m-d H:i:s'), 'currency' => 'EUR']);
        $this->owed(-30, '40.00', ['issued_at' => date('Y-m-d H:i:s')]);

        $page = $this->as($this->reader, 'receivables/reports/collections?currency=EUR');
        $page->assertSee('in EUR');
        $this->assertStringContainsString('collections-chart', (string) $page->response()->getBody());
        $this->assertStringContainsString('receivables-reports.js', (string) $page->response()->getBody());

        $fallback = $this->as($this->reader, 'receivables/reports/collections?currency=XXX');
        $this->assertTrue($fallback->isOK(), 'An unknown currency falls back to the first one.');

        $empty = $this->as($this->outsider, 'receivables/reports/collections');
        $empty->assertSee('Nothing has been invoiced or received');
    }

    public function testReportsNeedThePermission(): void
    {
        $nobody = $this->makeUserNamed('No Body', 'nobody@example.test');
        $this->grant($nobody, $this->company, []);

        foreach (['receivables/reports', 'receivables/reports/customers', 'receivables/reports/collections', 'receivables/reports/outstanding.csv'] as $path) {
            $this->assertNotSame(200, $this->as($nobody, $path)->response()->getStatusCode(), "GET /{$path} must be gated");
        }
    }

    public function testACompanyFilterFromAnotherCompanyIsIgnored(): void
    {
        $this->owed(5, '100.00', ['invoice_number' => 'AR-MINE']);
        $this->makeIssued($this->other, $this->raiser, ['due_date' => $this->ago(5), 'invoice_number' => 'AR-THEIRS']);

        $page = $this->as($this->reader, 'receivables/reports?company=' . $this->other);

        $page->assertDontSee('AR-THEIRS');
        $page->assertSee('USD 100.00');
    }

    // ------------------------------------------------------------------ CSV

    public function testTheOutstandingCsvListsTheBalances(): void
    {
        $this->owed(40, '250.00', ['invoice_number' => 'AR-CSV', 'customer' => 'Harbour Foods', 'amount_paid' => '50.00', 'status' => 'part_paid']);
        $this->makeIssued($this->other, $this->raiser, ['due_date' => $this->ago(5), 'invoice_number' => 'AR-HIDDEN']);

        $result = $this->as($this->reader, 'receivables/reports/outstanding.csv');
        $csv    = (string) $result->response()->getBody();

        $this->assertStringContainsString('text/csv', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('AR-CSV', $csv);
        $this->assertStringContainsString('Harbour Foods', $csv);
        $this->assertStringContainsString('200', $csv, 'The still-owed column holds the balance.');
        $this->assertStringContainsString('31 to 60 days', $csv);
        $this->assertStringNotContainsString('AR-HIDDEN', $csv);
        $this->assertSame(1, $this->db->table('audit_log')->where('action', 'ar.report_exported')->countAllResults());
    }

    public function testTheCustomerCsvGivesTheInvoiceCountOncePerCustomer(): void
    {
        $this->owed(5, '10.00', ['customer' => 'Harbour Foods']);
        $this->owed(5, '20.00', ['customer' => 'Harbour Foods', 'currency' => 'EUR']);   // a separate line (not linked), so two customers

        $csv = (string) $this->as($this->reader, 'receivables/reports/customers.csv')->response()->getBody();
        $rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv))));

        $this->assertSame('Customer code', $rows[0][0]);
        $this->assertCount(3, $rows, 'A header and one row per customer and currency.');
    }

    public function testTheCollectionsCsvHasOneRowPerMonthAndCurrency(): void
    {
        $this->owed(-30, '90.00', ['issued_at' => date('Y-m-d H:i:s')]);

        $csv = (string) $this->as($this->reader, 'receivables/reports/collections.csv')->response()->getBody();

        $this->assertStringContainsString('Invoiced (issued that month)', $csv);
        $this->assertStringContainsString(date('Y-m') . ',USD,90', $csv);
    }

    public function testTheSidebarOffersTheReports(): void
    {
        $this->as($this->reader, 'receivables')->assertSee('receivables/reports');
    }
}

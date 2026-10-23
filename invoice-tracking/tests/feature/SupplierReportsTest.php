<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ReportService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class SupplierReportsTest extends FeatureTestCase
{
    private int $acme;
    private int $beta;
    private User $acmeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acmeUser = $this->shieldUser('acme@example.test', 'Acme Member');
        $this->acme     = $this->makeCompany('ACME');
        $this->beta     = $this->makeCompany('BETA');
        $this->join((int) $this->acmeUser->id, $this->acme, true);
    }

    private function invoice(int $company, string $supplier, string $amount, string $currency, string $submittedAt, string $status = 'in_approval', int $daysPastDue = 0): void
    {
        $id = $this->makeInvoice($company, (int) $this->acmeUser->id, $status);
        $this->db->table('invoices')->where('id', $id)->update([
            'supplier' => $supplier, 'amount' => $amount, 'currency' => $currency, 'submitted_at' => $submittedAt . ' 10:00:00',
            'due_date' => date('Y-m-d', time() - $daysPastDue * 86400),
        ]);
    }

    public function testPeriodsAreCalendarAlignedAndOldestFirst(): void
    {
        $w = ReportService::periods('week', '2026-10-14', 2);      // a Wednesday
        $this->assertSame(['2026-10-05', '2026-10-11'], [$w[0]['from'], $w[0]['to']]);
        $this->assertSame(['2026-10-12', '2026-10-18'], [$w[1]['from'], $w[1]['to']]);

        $m = ReportService::periods('month', '2026-03-31', 2);
        $this->assertSame(['2026-02-01', '2026-02-28'], [$m[0]['from'], $m[0]['to']]);
        $this->assertSame('Mar 2026', $m[1]['label']);

        $q = ReportService::periods('quarter', '2026-02-10', 2);
        $this->assertSame(['2025-10-01', '2025-12-31', 'Q4 2025'], [$q[0]['from'], $q[0]['to'], $q[0]['label']]);
        $this->assertSame(['2026-01-01', '2026-03-31', 'Q1 2026'], [$q[1]['from'], $q[1]['to'], $q[1]['label']]);

        $y = ReportService::periods('year', '2026-06-01', 2);
        $this->assertSame(['2025-01-01', '2025-12-31'], [$y[0]['from'], $y[0]['to']]);
    }

    public function testTotalsAreGroupedBySupplierPeriodAndCurrencyAndStayInScope(): void
    {
        $this->invoice($this->acme, 'Acme Supplies', '100.00', 'USD', '2026-10-06');
        $this->invoice($this->acme, 'Acme Supplies', '50.00', 'USD', '2026-10-13');
        $this->invoice($this->acme, 'Acme Supplies', '10.00', 'BBD', '2026-10-13');
        $this->invoice($this->acme, '', '5.00', 'USD', '2026-10-13');
        $this->invoice($this->acme, 'Acme Supplies', '999.00', 'USD', '2026-10-13', 'draft');      // not counted
        $this->invoice($this->beta, 'Hidden Co', '777.00', 'USD', '2026-10-13');                    // not theirs

        $t = (new ReportService())->totalsBySupplier((int) $this->acmeUser->id, [], 'week', '2026-10-14');

        $names = array_column($t['suppliers'], 'supplier');
        $this->assertSame(['Acme Supplies', ReportService::NO_SUPPLIER], $names);
        $last = count($t['periods']) - 1;
        $acme = $t['suppliers'][0];
        $this->assertSame(1, $acme['cells'][$last - 1]['count']);
        $this->assertSame(['USD' => 100.0], $acme['cells'][$last - 1]['amount']);
        $this->assertSame(2, $acme['cells'][$last]['count']);
        $this->assertEqualsCanonicalizing(['BBD' => 10.0, 'USD' => 50.0], $acme['cells'][$last]['amount']);
        $this->assertSame(3, $t['totals'][$last]['count']);
    }

    public function testOutstandingBySupplierCountsOnlyOutstandingInvoices(): void
    {
        $this->invoice($this->acme, 'Acme Supplies', '100.00', 'USD', '2026-10-06', 'in_approval', 70);
        $this->invoice($this->acme, 'Acme Supplies', '20.00', 'USD', '2026-10-06', 'returned', 5);
        $this->invoice($this->acme, 'Acme Supplies', '900.00', 'USD', '2026-10-06', 'paid', 5);
        $this->invoice($this->acme, 'Other Co', '1.00', 'USD', '2026-10-06', 'in_approval', 0);

        $rows = ReportService::outstandingBySupplier((new ReportService())->outstanding((int) $this->acmeUser->id));

        $this->assertSame('Acme Supplies', $rows[0]['supplier']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame(['USD' => 120.0], $rows[0]['amount']);
        $this->assertSame(2, $rows[0]['overdue']);
        $this->assertSame(1, $rows[0]['critical']);
        $this->assertSame(70, $rows[0]['worst']);
    }

    public function testScreenAndCsvAreAvailableToASignedInUser(): void
    {
        $this->db->table('suppliers')->insert(['code' => 'A1', 'name' => 'Acme Supplies', 'active' => 1]);
        $this->invoice($this->acme, 'Acme Supplies', '100.00', 'USD', date('Y-m-d'));
        $this->invoice($this->beta, 'Hidden Co', '777.00', 'USD', date('Y-m-d'));

        foreach (['week', 'month', 'quarter', 'year', 'bogus'] as $period) {
            $res = $this->actingAs($this->acmeUser)->get('reports/suppliers?period=' . $period);
            $res->assertStatus(200);
            $res->assertSee('A1 · Acme Supplies');
            $res->assertDontSee('Hidden Co');
        }

        $csv = $this->actingAs($this->acmeUser)->get('reports/suppliers.csv?report=totals&period=month');
        $csv->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $body = (string) $csv->response()->getBody();
        $this->assertStringContainsString('A1,Acme Supplies', str_replace('"', '', $body));
        $this->assertStringNotContainsString('Hidden Co', $body);

        $out = (string) $this->actingAs($this->acmeUser)->get('reports/suppliers.csv?report=outstanding')->response()->getBody();
        $this->assertStringContainsString('Acme Supplies', $out);
        $this->assertStringNotContainsString('Hidden Co', $out);
    }
}

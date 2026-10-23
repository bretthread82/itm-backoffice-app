<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdminAccess;
use App\Services\InvoiceFiles;
use App\Services\WorkflowEngine;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FakeMailTransport;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class ReportScreensTest extends FeatureTestCase
{
    private int $acme;
    private int $beta;
    private User $admin;
    private User $acmeUser;
    private User $betaUser;
    private User $gary;
    private User $nobody;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('mailer', new FakeMailTransport());

        $this->admin    = $this->shieldUser('admin@example.test', 'Admin', true);
        $this->acmeUser = $this->shieldUser('acme@example.test', 'Acme Member');
        $this->betaUser = $this->shieldUser('beta@example.test', 'Beta Member');
        $this->gary     = $this->shieldUser('gary@example.test', 'Gary Group');
        $this->nobody   = $this->shieldUser('nobody@example.test', 'No Companies');

        $this->acme = $this->makeCompany('ACME');
        $this->beta = $this->makeCompany('BETA');
        $this->join((int) $this->acmeUser->id, $this->acme, true);
        $this->join((int) $this->betaUser->id, $this->beta, true);
        (new AdminAccess($this->db))->grantGroupLevel((int) $this->admin->id, (int) $this->gary->id);
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        Services::resetSingle('mailer');
        parent::tearDown();
    }

    private function raw(TestResponse $r): string
    {
        return (string) $r->response()->getBody();
    }

    /** Outstanding invoice due $daysAgo days before today (negative = in the future). */
    private function invoice(int $company, int $daysAgo, string $amount, string $currency = 'USD', string $no = 'N', string $supplier = 'Supplier', string $status = 'in_approval'): int
    {
        $id = $this->makeInvoice($company, (int) $this->acmeUser->id, $status);
        $this->db->table('invoices')->where('id', $id)->update([
            'amount' => $amount, 'currency' => $currency, 'invoice_number' => $no, 'supplier' => $supplier,
            'due_date' => date('Y-m-d', time() - $daysAgo * 86400),
        ]);

        return $id;
    }

    // -------------------------------------------------------------- dashboard

    public function testDashboardShowsTotalsAndFlagsCriticalInvoices(): void
    {
        $this->invoice($this->acme, 0, '100.00', 'USD', 'OK-1');
        $this->invoice($this->acme, 10, '50.00', 'USD', 'LATE-1');
        $this->invoice($this->acme, 75, '500.00', 'USD', 'CRIT-1');
        $this->invoice($this->acme, 5, '999.00', 'USD', 'DONE', 'S', 'paid');   // not outstanding
        $this->invoice($this->beta, 100, '7777.00', 'USD', 'OTHER-CO');              // not theirs

        $page = $this->actingAs($this->acmeUser)->get('/');
        $body = $page->getBody();
        $page->assertStatus(200);

        $this->assertStringContainsString('Total Invoiced', $body);
        $this->assertStringContainsString('Outstanding', $body);
        $this->assertStringContainsString('USD 650.00', $body);                      // 100 + 50 + 500
        $this->assertStringContainsString('critical (61+ days)', $body);
        $this->assertStringContainsString('CRIT-1', $body);                          // in the most-overdue list
        $this->assertStringContainsString('table-danger', $body);                      // critical row highlighted
        $this->assertStringNotContainsString('OTHER-CO', $body);
        $this->assertStringNotContainsString('7,777', $body);
        $this->assertStringNotContainsString('USD 999.00', $body);
    }

    public function testDashboardWithNothingCriticalIsNotHighlighted(): void
    {
        $this->invoice($this->acme, 3, '10.00', 'USD', 'A');

        $body = $this->actingAs($this->acmeUser)->get('/')->getBody();

        $this->assertStringNotContainsString('table-danger', $body);
        $this->assertStringContainsString('No outstanding invoice is past its due date.', $this->actingAs($this->betaUser)->get('/')->getBody());
    }

    public function testGroupLevelUserSeesEveryCompanyTotal(): void
    {
        $this->invoice($this->acme, 1, '100.00');
        $this->invoice($this->beta, 70, '900.00');

        $body = $this->actingAs($this->gary)->get('/')->getBody();

        $this->assertStringContainsString('USD 1,000.00', $body);
        $this->assertStringContainsString('All companies', $body);
        foreach (['ACME', 'BETA'] as $code) {
            $this->assertStringContainsString($code, $body);
        }
    }

    public function testMultipleCurrenciesAreShownSeparately(): void
    {
        $this->invoice($this->acme, 1, '100.00', 'USD');
        $this->invoice($this->acme, 1, '40.00', 'BBD');

        $body = html_entity_decode($this->actingAs($this->acmeUser)->get('/')->getBody()); // esc() writes the dot as &middot;

        $this->assertStringContainsString('BBD 40.00 · USD 100.00', $body);
        $this->assertStringNotContainsString('140.00', $body);
    }

    public function testUserWithNoCompaniesIsToldSo(): void
    {
        $this->invoice($this->acme, 1, '100.00');

        $body = $this->actingAs($this->nobody)->get('/')->getBody();

        $this->assertStringContainsString('not a member of any company', $body);
        $this->assertStringNotContainsString('USD 100.00', $body);
    }

    // -------------------------------------------------------------- reports page

    public function testReportsPageShowsAgingByCompanyAndFilters(): void
    {
        $this->invoice($this->acme, 0, '10.00');
        $this->invoice($this->acme, 20, '10.00');
        $this->invoice($this->acme, 45, '10.00');
        $this->invoice($this->acme, 80, '10.00');
        $this->invoice($this->acme, 200, '10.00');
        $this->invoice($this->beta, 5, '10.00');

        $body = $this->actingAs($this->gary)->get('reports')->getBody();
        foreach (['Not yet due', '1 to 30 days', '31 to 60 days', '61 to 90 days', 'Over 90 days', 'Outstanding by company', 'Most overdue', 'Outstanding by sub-group'] as $needle) {
            $this->assertStringContainsString($needle, $body, $needle);
        }

        $only = $this->actingAs($this->gary)->get('reports', ['company' => (string) $this->beta])->getBody();
        $this->assertStringContainsString('BETA', $only);
        $this->assertStringNotContainsString('title="ACME', $only); // ACME's row is not in the table
    }

    public function testReportsCompanyFilterCannotExposeAnotherCompany(): void
    {
        $this->invoice($this->beta, 100, '4321.00', 'USD', 'BETA-SECRET');

        $body = $this->actingAs($this->acmeUser)->get('reports', ['company' => (string) $this->beta])->getBody();

        $this->assertStringNotContainsString('BETA-SECRET', $body);
        $this->assertStringNotContainsString('4,321', $body);
    }

    public function testSubGroupFilterOnReports(): void
    {
        $this->db->table('company_groups')->insert(['name' => 'Retail']);
        $retail = (int) $this->db->insertID();
        $this->db->table('companies')->where('id', $this->acme)->update(['group_id' => $retail]);
        $this->invoice($this->acme, 1, '10.00', 'USD', 'IN-GROUP');
        $this->invoice($this->beta, 1, '10.00', 'USD', 'OUT-OF-GROUP');

        $csv = $this->raw($this->actingAs($this->gary)->get('reports/outstanding.csv', ['group' => (string) $retail]));

        $this->assertStringContainsString('IN-GROUP', $csv);
        $this->assertStringNotContainsString('OUT-OF-GROUP', $csv);
    }

    public function testReportsRequireSignIn(): void
    {
        $this->get('reports')->assertRedirectTo(site_url('login'));
        $this->get('reports/outstanding.csv')->assertRedirectTo(site_url('login'));
    }

    // -------------------------------------------------------------- CSV

    public function testOutstandingCsvHasTheRightHeadersRowsAndOnlyMyInvoices(): void
    {
        $this->invoice($this->acme, 70, '250.50', 'USD', 'CSV-1', 'Power Co');
        $this->invoice($this->acme, 0, '10.00', 'USD', 'CSV-2', 'Water Co', 'returned');
        $this->invoice($this->acme, 1, '10.00', 'USD', 'DONE-1', 'Done Co', 'paid');
        $this->invoice($this->beta, 1, '10.00', 'USD', 'BETA-1', 'Not Mine');

        $res = $this->actingAs($this->acmeUser)->get('reports/outstanding.csv');
        $res->assertStatus(200);
        $this->assertStringStartsWith('text/csv', $res->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="outstanding-invoices-', $res->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));

        $csv   = $this->raw($res);
        $lines = array_values(array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $this->assertStringStartsWith('"Company code",Company,Sub-group,"Invoice number",Supplier,Amount,Currency,"Due date","Days past due","Age band","Critical (61+)",Status,With,"Days waiting"', $lines[0]);
        $this->assertCount(3, $lines); // header + CSV-1 + CSV-2
        $this->assertStringContainsString('CSV-1', $lines[1]);               // most overdue first
        $this->assertStringContainsString('70', $lines[1]);
        $this->assertStringContainsString('250.5', $lines[1]);
        $this->assertStringContainsString('"61 to 90 days",Yes', $lines[1]);
        $this->assertStringContainsString('CSV-2', $lines[2]);
        $this->assertStringContainsString('Returned', $lines[2]);
        $this->assertStringNotContainsString('BETA-1', $csv);
        $this->assertStringNotContainsString('DONE-1', $csv);
    }

    public function testSupplierNamesCannotInjectSpreadsheetFormulas(): void
    {
        $this->invoice($this->acme, 1, '10.00', 'USD', 'EVIL-1', '=HYPERLINK("http://evil.example","click")');

        $csv = $this->raw($this->actingAs($this->acmeUser)->get('reports/outstanding.csv'));

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString('"=HYPERLINK', $csv);
    }

    public function testExportsAreAudited(): void
    {
        $this->invoice($this->acme, 1, '10.00');
        $this->actingAs($this->acmeUser)->get('reports/outstanding.csv');

        $row = $this->db->table('audit_log')->where('event', 'report.exported')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame((int) $this->acmeUser->id, (int) $row['user_id']);
    }

    // -------------------------------------------------------------- the invoice list's new filters

    public function testInvoiceListAgeAndOutstandingFilters(): void
    {
        $days = [0 => 'D0', 1 => 'D1', 30 => 'D30', 31 => 'D31', 60 => 'D60', 61 => 'D61', 90 => 'D90', 91 => 'D91', -5 => 'FUTURE'];
        foreach ($days as $d => $no) {
            $this->invoice($this->acme, $d, '10.00', 'USD', $no);
        }
        $this->invoice($this->acme, 200, '10.00', 'USD', 'FINISHED', 'S', 'paid');

        $shows = function (array $query) use ($days): array {
            $body = $this->actingAs($this->acmeUser)->get('invoices', $query)->getBody();

            return array_values(array_filter($days, static fn (string $no): bool => str_contains($body, '>' . $no . '<')));
        };

        $this->assertEqualsCanonicalizing(['D1', 'D30', 'D31', 'D60', 'D61', 'D90', 'D91'], $shows(['age' => 'overdue']));
        $this->assertEqualsCanonicalizing(['D0', 'FUTURE'], $shows(['age' => 'current']));
        $this->assertEqualsCanonicalizing(['D1', 'D30'], $shows(['age' => '1-30']));
        $this->assertEqualsCanonicalizing(['D31', 'D60'], $shows(['age' => '31-60']));
        $this->assertEqualsCanonicalizing(['D61', 'D90'], $shows(['age' => '61-90']));
        $this->assertEqualsCanonicalizing(['D91'], $shows(['age' => '90+']));
        $this->assertEqualsCanonicalizing(['D61', 'D90', 'D91'], $shows(['age' => 'critical']));

        $body = $this->actingAs($this->acmeUser)->get('invoices', ['status' => 'outstanding'])->getBody();
        $this->assertStringNotContainsString('>FINISHED<', $body);
        $this->assertStringContainsString('>D0<', $body);

        // An unknown value is ignored, not an error.
        $this->actingAs($this->acmeUser)->get('invoices', ['age' => 'banana'])->assertStatus(200);
    }

    // -------------------------------------------------------------- group-level activity (admin)

    /** A group-level override on a real submitted invoice. Returns the invoice id. */
    private function overrideOnce(string $reason = 'Approver is on leave.'): int
    {
        $approver = $this->shieldUser('appr@example.test', 'Alan Approver');
        $this->join((int) $approver->id, $this->acme);

        $route = $this->makeRoute($this->acme, [
            ['type' => 'submit', 'user' => (int) $this->acmeUser->id, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => (int) $approver->id, 'role' => 'Accountant', 'label' => 'Alan Approver'],
        ]);
        $engine = new WorkflowEngine($this->db);
        $id     = $engine->createDraft((int) $this->acmeUser->id, [
            'company_id' => $this->acme, 'invoice_type_id' => $route['type'], 'supplier' => 'Override Co', 'invoice_number' => 'OVR-1',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '321.00',
        ]);
        (new InvoiceFiles($this->db))->store($id, (int) $this->acmeUser->id, $this->pdfFile(), 'a.pdf');
        $engine->submit($id, (int) $this->acmeUser->id);
        $engine->approve($id, (int) $this->gary->id, $reason);

        return $id;
    }

    public function testOnlyAdminsCanOpenTheGroupLevelActivityReport(): void
    {
        foreach (['admin/group-level-activity', 'admin/group-level-activity.csv'] as $uri) {
            $this->actingAs($this->gary)->get($uri)->assertRedirectTo(site_url('/'));      // group-level is not admin
            $this->actingAs($this->acmeUser)->get($uri)->assertRedirectTo(site_url('/'));
        }
        $this->actingAs($this->admin)->get('admin/group-level-activity')->assertStatus(200);
    }

    public function testSignedOutUsersCannotOpenTheGroupLevelActivityReport(): void
    {
        foreach (['admin/group-level-activity', 'admin/group-level-activity.csv'] as $uri) {
            $this->get($uri)->assertRedirectTo(site_url('login'));
        }
    }

    public function testActivityReportListsTheOverrideWithWhoWhyAndForWhom(): void
    {
        $id = $this->overrideOnce('Approver is on leave.');

        $body = $this->actingAs($this->admin)->get('admin/group-level-activity')->getBody();

        $this->assertStringContainsString('Gary Group', $body);
        $this->assertStringContainsString('Approver is on leave.', $body);
        $this->assertStringContainsString('OVR-1', $body);
        $this->assertStringContainsString('Accountant (Alan Approver)', $body);
        $this->assertStringContainsString('for Alan Approver', $body);
        $this->assertStringContainsString('invoices/' . $id, $body);
        // The change log shows who granted Gary access.
        $this->assertStringContainsString('gave group-level access', $body);
    }

    public function testActivityReportFilters(): void
    {
        $this->overrideOnce('First reason');
        $other = $this->shieldUser('gary2@example.test', 'Other Group User');
        (new AdminAccess($this->db))->grantGroupLevel((int) $this->admin->id, (int) $other->id);

        $admin = $this->actingAs($this->admin);
        $this->assertStringContainsString('First reason', $admin->get('admin/group-level-activity', ['user' => (string) $this->gary->id])->getBody());
        $this->assertStringNotContainsString('First reason', $admin->get('admin/group-level-activity', ['user' => (string) $other->id])->getBody());
        $this->assertStringNotContainsString('First reason', $admin->get('admin/group-level-activity', ['company' => (string) $this->beta])->getBody());
        $this->assertStringContainsString('First reason', $admin->get('admin/group-level-activity', ['company' => (string) $this->acme])->getBody());

        $this->assertStringContainsString('First reason', $admin->get('admin/group-level-activity', ['from' => date('Y-m-d', time() - 86400), 'to' => date('Y-m-d', time() + 86400)])->getBody());
        $this->assertStringNotContainsString('First reason', $admin->get('admin/group-level-activity', ['from' => date('Y-m-d', time() + 86400)])->getBody());
        $this->assertStringNotContainsString('First reason', $admin->get('admin/group-level-activity', ['to' => date('Y-m-d', time() - 2 * 86400)])->getBody());

        // Junk dates are ignored rather than breaking the page.
        $admin->get('admin/group-level-activity', ['from' => 'not-a-date', 'to' => '31/12/2026'])->assertStatus(200);
    }

    public function testActivityCsvContainsTheReasonAndIsAudited(): void
    {
        $this->overrideOnce('=Reason that starts with equals');

        $res = $this->actingAs($this->admin)->get('admin/group-level-activity.csv');
        $res->assertStatus(200);
        $this->assertStringContainsString('group-level-activity-', $res->response()->getHeaderLine('Content-Disposition'));

        $csv = $this->raw($res);
        $this->assertStringContainsString('Gary Group', $csv);
        $this->assertStringContainsString('Alan Approver', $csv);
        $this->assertStringContainsString("'=Reason that starts with equals", $csv);   // formula-safe
        $this->assertStringContainsString('When,"Group-level user",Action', $csv);

        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'report.exported')->countAllResults());
    }

    public function testNormalApprovalsDoNotAppearInTheGroupLevelReport(): void
    {
        $approver = $this->shieldUser('appr@example.test', 'Alan Approver');
        $this->join((int) $approver->id, $this->acme);
        $route  = $this->makeRoute($this->acme, [
            ['type' => 'submit', 'user' => (int) $this->acmeUser->id, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => (int) $approver->id, 'role' => 'Accountant'],
        ]);
        $engine = new WorkflowEngine($this->db);
        $id     = $engine->createDraft((int) $this->acmeUser->id, [
            'company_id' => $this->acme, 'invoice_type_id' => $route['type'], 'supplier' => 'Normal Co', 'invoice_number' => 'NORM-1',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '5',
        ]);
        (new InvoiceFiles($this->db))->store($id, (int) $this->acmeUser->id, $this->pdfFile(), 'a.pdf');
        $engine->submit($id, (int) $this->acmeUser->id);
        $engine->approve($id, (int) $approver->id);

        $body = $this->actingAs($this->admin)->get('admin/group-level-activity')->getBody();

        $this->assertStringNotContainsString('NORM-1', $body);
        $this->assertStringContainsString('No group-level actions match.', $body);
    }
}

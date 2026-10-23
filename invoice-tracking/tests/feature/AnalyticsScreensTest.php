<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\AdminAccess;
use App\Services\Analytics\AnalyticsMigrator;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\FeatureTestCase;

/**
 * Uses invoicing_test (app) and invoicing_test_analytics (analytics).
 *
 * @internal
 */
final class AnalyticsScreensTest extends FeatureTestCase
{
    private const PAGES = ['admin/analytics', 'admin/analytics/cycle-time', 'admin/analytics/backlog'];

    private BaseConnection $analytics;
    private int $acme;
    private int $beta;
    private User $admin;
    private User $acmeManager;
    private User $plain;

    protected function setUp(): void
    {
        parent::setUp();
        AnalyticsMigrator::wipe();
        (new AnalyticsMigrator())->upgrade();
        $this->analytics = db_connect(AnalyticsMigrator::GROUP);

        $this->admin       = $this->shieldUser('admin@example.test', 'Admin', true);
        $this->acmeManager = $this->manager('mgr@example.test', 'Acme Manager');
        $this->plain       = $this->shieldUser('plain@example.test', 'Plain');
        $this->acme        = $this->makeCompany('ACME');
        $this->beta        = $this->makeCompany('BETA');
        $this->join((int) $this->acmeManager->id, $this->acme);
        $this->join((int) $this->plain->id, $this->acme);

        foreach ([$this->acme => 'ACME', $this->beta => 'BETA'] as $id => $code) {
            $this->analytics->table('dim_company')->insert(['company_id' => $id, 'code' => $code, 'name' => $code . ' Ltd', 'group_id' => null, 'group_path' => null, 'active' => 1]);
            $this->analytics->table('dim_invoice_type')->insert(['invoice_type_id' => $id * 10, 'company_id' => $id, 'name' => 'Standard', 'high_priority' => 0]);
        }
    }

    private function manager(string $email, string $name): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $name, 'is_admin' => 0, 'is_manager' => 1, 'active' => 1]));

        return $users->findById($users->getInsertID());
    }

    private function raw(TestResponse $r): string
    {
        return (string) $r->response()->getBody();
    }

    private int $seq = 0;

    /** A finished invoice submitted 40 days ago. */
    private function fact(int $company, string $amount, string $supplier): void
    {
        $when = date('Y-m-d H:i:s', strtotime('-40 days'));
        $this->analytics->table('fact_invoice')->insert([
            'invoice_id' => ++$this->seq, 'company_id' => $company, 'invoice_type_id' => $company * 10, 'currency' => 'USD', 'amount' => $amount, 'status' => 'paid', 'outstanding' => 0,
            'supplier_name' => $supplier, 'first_submitted_at' => $when, 'submitted_at' => $when, 'processed_at' => $when, 'paid_at' => $when, 'days_to_processed' => '3.00', 'days_to_paid' => '5.00',
            'loaded_at' => date('Y-m-d H:i:s'),
        ]);
        $this->analytics->table('fact_step')->insert([
            'invoice_id' => $this->seq, 'company_id' => $company, 'cycle' => 1, 'step_order' => 2, 'step_type' => 'approve', 'assignee_label' => $supplier . ' approver',
            'entered_at' => $when, 'left_at' => $when, 'duration_hours' => '30.00', 'outcome' => 'approved', 'acted_by_name' => 'Alan', 'override' => 0, 'sla_breached' => 0,
        ]);
    }

    private function load(): void
    {
        $this->fact($this->acme, '7777.00', 'Acme Supplies');
        $this->fact($this->beta, '8888.00', 'Beta Supplies');
        $this->analytics->table('snap_backlog_daily')->insert(['snapshot_date' => date('Y-m-d', strtotime('-5 days')), 'company_id' => $this->beta, 'currency' => 'USD', 'age_band' => '1-30', 'invoice_count' => 3, 'amount' => 4321, 'reconstructed' => 0]);
        $this->analytics->table('etl_state')->insert(['source' => 'invoices', 'watermark' => null, 'last_run' => date('Y-m-d H:i:s'), 'rows_processed' => 2]);
    }

    public function testPlainUsersAreSentAwayAndManagersAndAdministratorsGetIn(): void
    {
        foreach (self::PAGES as $uri) {
            $this->actingAs($this->plain)->get($uri)->assertRedirectTo(site_url('/'));
            $this->actingAs($this->acmeManager)->get($uri)->assertStatus(200);
            $this->actingAs($this->admin)->get($uri)->assertStatus(200);
        }
        foreach (['volume', 'cycle-time', 'backlog'] as $csv) {
            $this->actingAs($this->plain)->get("admin/analytics/{$csv}.csv")->assertRedirectTo(site_url('/'));
        }
    }

    public function testEmptyAnalyticsExplainsHowToLoadIt(): void
    {
        $body = $this->raw($this->actingAs($this->acmeManager)->get('admin/analytics'));

        $this->assertStringContainsString('No analytics data has been loaded yet', $body);
        $this->assertStringContainsString('analytics:backfill', $body);
    }

    public function testAManagerSeesOnlyTheirOwnCompaniesAndAGroupLevelUserSeesAll(): void
    {
        $this->load();

        $mine = $this->raw($this->actingAs($this->acmeManager)->get('admin/analytics'));
        $this->assertStringContainsString('7,777.00', $mine);
        $this->assertStringNotContainsString('8,888.00', $mine);
        $this->assertStringNotContainsString('Beta Supplies', $mine);
        $this->assertStringContainsString('Data as of', $mine);

        $gary = $this->manager('gary@example.test', 'Gary Group');
        (new AdminAccess($this->db))->grantGroupLevel((int) $this->admin->id, (int) $gary->id);
        $all = $this->raw($this->actingAs($gary)->get('admin/analytics'));
        $this->assertStringContainsString('7,777.00', $all);
        $this->assertStringContainsString('8,888.00', $all);
    }

    public function testAskingForACompanyYouAreNotInShowsNothing(): void
    {
        $this->load();

        $body = $this->raw($this->actingAs($this->acmeManager)->get('admin/analytics?company=' . $this->beta));

        $this->assertStringNotContainsString('8,888.00', $body);
        $this->assertStringNotContainsString('Beta Supplies', $body);
    }

    public function testCycleAndBacklogPagesRenderTheirData(): void
    {
        $this->load();

        $cycle = $this->raw($this->actingAs($this->acmeManager)->get('admin/analytics/cycle-time'));
        $this->assertStringContainsString('Where invoices spend their time', $cycle);
        $this->assertStringContainsString('30 h', $cycle, 'under 48 hours is shown in hours');

        $this->assertStringContainsString('No backlog snapshots fall in this date range', $this->raw($this->actingAs($this->acmeManager)->get('admin/analytics/backlog')), 'the only snapshot belongs to another company');
        $gary = $this->manager('gary@example.test', 'Gary Group');
        (new AdminAccess($this->db))->grantGroupLevel((int) $this->admin->id, (int) $gary->id);
        $backlog = $this->raw($this->actingAs($gary)->get('admin/analytics/backlog'));
        $this->assertStringContainsString('Outstanding invoices by age', $backlog);
        $this->assertStringContainsString('4,321.00', $backlog);
    }

    public function testCsvExportsAreScopedAndAudited(): void
    {
        $this->load();

        $r = $this->actingAs($this->acmeManager)->get('admin/analytics/volume.csv');
        $r->assertStatus(200);
        $this->assertStringContainsString('text/csv', $r->response()->getHeaderLine('Content-Type'));
        $csv = $this->raw($r);
        $this->assertStringContainsString('7777', $csv);
        $this->assertStringNotContainsString('8888', $csv);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'report.exported')->like('detail', 'analytics-volume')->countAllResults());

        $this->assertStringContainsString('Avg days to processed', $this->raw($this->actingAs($this->acmeManager)->get('admin/analytics/cycle-time.csv')));
        $this->assertStringContainsString('Age band', $this->raw($this->actingAs($this->admin)->get('admin/analytics/backlog.csv')));
    }

    public function testAnUnavailableAnalyticsDatabaseShowsANoticeAndNothingElseBreaks(): void
    {
        $this->load();
        $this->analytics->query('DROP TABLE fact_invoice');
        $this->analytics->resetDataCache();

        foreach (self::PAGES as $uri) {
            $r = $this->actingAs($this->acmeManager)->get($uri);
            $r->assertStatus(200);
            $this->assertStringContainsString('could not be reached', $this->raw($r));
        }
        $this->actingAs($this->acmeManager)->get('admin/companies')->assertStatus(200);
    }

    public function testReportsAndAnalysisShareOneTabStripThatOnlyManagersSeeInFull(): void
    {
        foreach (['reports', 'reports/suppliers', 'admin/analytics', 'admin/analytics/cycle-time', 'admin/analytics/backlog'] as $uri) {
            $body = $this->raw($this->actingAs($this->acmeManager)->get($uri));
            foreach (['Outstanding &amp; ageing', 'By supplier', 'Long-term analysis'] as $label) {
                $this->assertStringContainsString($label, $body, "{$uri} shows the {$label} tab");
            }
            // The analysis tab is a real link to the analysis, not a label.
            $this->assertMatchesRegularExpression('~<a class="nav-link[^"]*"[^>]*href="[^"]*admin(?:/|&\#x2F;)analytics[^"]*"><i class="bi bi-graph-up-arrow me-1"></i>Long-term analysis</a>~', $body, $uri);
        }

        // The three views appear as pills only while on the analysis tab.
        $reports = $this->raw($this->actingAs($this->acmeManager)->get('reports'));
        $this->assertStringNotContainsString('Backlog history', $reports);
        foreach (['admin/analytics', 'admin/analytics/cycle-time', 'admin/analytics/backlog'] as $uri) {
            $body = $this->raw($this->actingAs($this->acmeManager)->get($uri));
            foreach (['Volume &amp; spend', 'Cycle time', 'Backlog history'] as $label) {
                $this->assertStringContainsString($label, $body, "{$uri} lists {$label}");
            }
        }
        $this->assertStringContainsString('aria-current="page"', $this->raw($this->actingAs($this->acmeManager)->get('admin/analytics/backlog')));

        foreach (['reports', 'reports/suppliers'] as $uri) {
            $body = $this->raw($this->actingAs($this->plain)->get($uri));
            $this->assertStringContainsString('By supplier', $body);
            $this->assertStringNotContainsString('Long-term analysis', $body);
            $this->assertStringNotContainsString('admin/analytics', $body);
        }
    }

    public function testHelpExplainsLongTermAnalysisToTheRightPeople(): void
    {
        $manager = $this->raw($this->actingAs($this->acmeManager)->get('help'));
        $this->assertStringContainsString('Long-term analytics', $manager);
        $this->assertStringContainsString('I cannot see the Long-term analysis tab', $manager);
        $this->assertStringNotContainsString('Setting it up (administrators)', $manager, 'set-up steps are for administrators only');

        $admin = $this->raw($this->actingAs($this->admin)->get('help'));
        $this->assertStringContainsString('Setting it up (administrators)', $admin);
        $this->assertStringContainsString('analytics:backfill', $admin);

        $plain = $this->raw($this->actingAs($this->plain)->get('help'));
        $this->assertStringNotContainsString('Long-term analytics', $plain);
        $this->assertStringNotContainsString('analytics:backfill', $plain);
    }

    public function testNavigationHasOneReportsEntryThatStaysActiveOnAnalysisPages(): void
    {
        $body = $this->raw($this->actingAs($this->acmeManager)->get('admin/analytics'));

        $this->assertStringNotContainsString('Long-term Analytics</a>', $body);
        $this->assertMatchesRegularExpression('~<a class="active" href="[^"]*/reports">~', $body);
    }
}

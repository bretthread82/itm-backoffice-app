<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Routing\RoutingImporter;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class RoutingScreensTest extends FeatureTestCase
{
    private \CodeIgniter\Shield\Entities\User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->shieldUser('boss@example.test', 'The Admin', true);
    }

    private function importSample(): void
    {
        (new RoutingImporter($this->db))->commit(SUPPORTPATH . 'fixtures/routing_sample.csv', (int) $this->admin->id);

        // The importer creates no memberships; give every named approver the right so routes can be re-saved.
        $pairs = $this->db->query('SELECT DISTINCT s.assignee_user_id AS u, r.company_id AS c FROM route_steps s JOIN routes r ON r.id = s.route_id WHERE s.assignee_user_id IS NOT NULL')->getResultArray();
        foreach ($pairs as $p) {
            $row = ['user_id' => (int) $p['u'], 'company_id' => (int) $p['c']];
            if ($this->db->table('user_companies')->where($row)->countAllResults() === 0) {
                $this->db->table('user_companies')->insert($row + ['can_submit' => 0, 'can_approve' => 1, 'created_at' => date('Y-m-d H:i:s')]);
            } else {
                $this->db->table('user_companies')->where($row)->update(['can_approve' => 1]);
            }
        }
    }

    private function routeId(string $company, string $type, string $variant = 'default'): int
    {
        $row = $this->db->query(
            'SELECT r.id FROM routes r JOIN companies c ON c.id = r.company_id JOIN invoice_types t ON t.id = r.invoice_type_id
             WHERE c.code = ? AND t.name = ? AND r.variant = ?',
            [$company, $type, $variant],
        )->getRowArray();
        $this->assertNotNull($row, "No route $company / $type / $variant");

        return (int) $row['id'];
    }

    public function testNonAdminCannotOpenRoutingScreens(): void
    {
        $user = $this->shieldUser('plain@example.test', 'Plain');

        foreach (['admin/routes', 'admin/routes/new', 'admin/import'] as $uri) {
            $this->actingAs($user)->get($uri)->assertRedirectTo(site_url('/'));
        }
    }

    public function testRoutesListAndFilters(): void
    {
        $this->importSample();

        $all = $this->actingAs($this->admin)->get('admin/routes');
        $all->assertStatus(200);
        $all->assertSee('HC Gym');

        $active = $this->actingAs($this->admin)->get('admin/routes', ['status' => 'active', 'q' => 'Gym']);
        $active->assertSee('HC Gym');

        $variants = $this->actingAs($this->admin)->get('admin/routes', ['variant' => 'extra_approval']);
        $variants->assertSee('Procurement');
        $variants->assertDontSee('HC Gym');
    }

    public function testEditPageRendersStepsAndTemplate(): void
    {
        $this->importSample();
        $id = $this->routeId('HCSRL', 'HC Gym');

        $result = $this->actingAs($this->admin)->get('admin/routes/' . $id . '/edit');
        $result->assertStatus(200);
        $result->assertSee('step-template');
        $result->assertSee('Felisha Weekes');
        $result->assertSee('Operations Manager Club Fitness');
    }

    public function testUserWithoutTheApproveRightCannotBeNamedOnAnApproveStep(): void
    {
        $this->importSample();
        $id     = $this->routeId('HCSRL', 'HC Gym');
        $first  = (int) $this->db->table('route_steps')->where('route_id', $id)->orderBy('step_order')->get()->getRowArray()['assignee_user_id'];
        $before = $this->db->table('route_steps')->where('route_id', $id)->countAllResults();
        $this->db->table('user_companies')->where('user_id', $first)->update(['can_approve' => 0]);

        $this->actingAs($this->admin)->post('admin/routes/' . $id, ['active' => '1', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => 'approve', 'job_title' => 'X', 'assignee_kind' => 'user', 'user_id' => (string) $first],
        ]]);

        $this->assertSame($before, $this->db->table('route_steps')->where('route_id', $id)->countAllResults());
    }

    public function testSavingWarnsAboutAssigneesWhoHaveNotSignedInButStillSaves(): void
    {
        $this->importSample();
        $id    = $this->routeId('HCSRL', 'HC Gym');
        $first = (int) $this->db->table('route_steps')->where('route_id', $id)->orderBy('step_order')->get()->getRowArray()['assignee_user_id'];
        $this->db->table('users')->where('id', $first)->update(['last_active' => null]);

        $this->actingAs($this->admin)->post('admin/routes/' . $id, ['active' => '1', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => 'approve', 'job_title' => 'X', 'assignee_kind' => 'user', 'user_id' => (string) $first],
        ]])->assertRedirectTo(site_url('admin/routes/' . $id . '/edit'));

        $this->assertSame(1, $this->db->table('route_steps')->where('route_id', $id)->countAllResults());
        $this->assertStringContainsString('not signed in yet', (string) session()->getFlashdata('warning'));

        $this->db->table('users')->where('id', $first)->update(['last_active' => date('Y-m-d H:i:s')]);
        $this->actingAs($this->admin)->post('admin/routes/' . $id, ['active' => '1', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => 'approve', 'job_title' => 'X', 'assignee_kind' => 'user', 'user_id' => (string) $first],
        ]]);
        $this->assertNull(session()->getFlashdata('warning'));
    }

    public function testSavingReordersRemovesAndAddsSteps(): void
    {
        $this->importSample();
        $id    = $this->routeId('HCSRL', 'HC Gym');
        $steps = $this->db->table('route_steps')->where('route_id', $id)->orderBy('step_order')->get()->getResultArray();
        $this->assertCount(5, $steps);

        $user = fn (int $i): int => (int) $steps[$i]['assignee_user_id'];

        // New order: accountant first, then the original first step; drop the CEO; add a notify mailbox at the end.
        $post = ['active' => '1', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => 'submit', 'job_title' => 'Accountant', 'assignee_kind' => 'user', 'user_id' => (string) $user(2)],
            'b' => ['step_type' => 'approve', 'job_title' => 'AP Clerk', 'assignee_kind' => 'user', 'user_id' => (string) $user(0)],
            'c' => ['step_type' => 'process', 'job_title' => 'AP Processing Clerk', 'assignee_kind' => 'user', 'user_id' => (string) $user(4)],
            'd' => ['step_type' => 'notify', 'job_title' => 'Finance Inbox', 'assignee_kind' => 'team', 'team_id' => (string) $this->makeTeam('Finance team', [], true, 'finance@example.test')],
        ]];

        $this->actingAs($this->admin)->post('admin/routes/' . $id, $post)->assertRedirectTo(site_url('admin/routes/' . $id . '/edit'));

        $saved = $this->db->table('route_steps')->where('route_id', $id)->orderBy('step_order')->get()->getResultArray();
        $this->assertSame([1, 2, 3, 4], array_map('intval', array_column($saved, 'step_order')));
        $this->assertSame(['submit', 'approve', 'process', 'notify'], array_column($saved, 'step_type'));
        $this->assertSame($user(2), (int) $saved[0]['assignee_user_id']);
        $this->assertNull($saved[3]['assignee_user_id']);
        $this->assertSame('team', $saved[3]['assignee_kind']);
        $this->assertSame('Finance team', $saved[3]['assignee_label']);

        $route = $this->db->table('routes')->where('id', $id)->get()->getRowArray();
        $this->assertSame(2, (int) $route['version']);
    }

    public function testBlankRoleIsFilledFromTheUsersJobTitle(): void
    {
        $this->importSample();
        $id      = $this->routeId('HCSRL', 'HC Gym');
        $company = (int) $this->db->table('routes')->where('id', $id)->get()->getRowArray()['company_id'];
        $step    = $this->db->table('route_steps')->where('route_id', $id)->orderBy('step_order')->get()->getResultArray()[0];
        $userId  = (int) $step['assignee_user_id'];

        $this->db->table('user_companies')->where('user_id', $userId)->delete();
        $this->db->table('user_companies')->insert(['user_id' => $userId, 'company_id' => $company, 'can_submit' => 0, 'can_approve' => 1, 'job_title' => 'Finance Controller', 'created_at' => date('Y-m-d H:i:s')]);

        $post = fn (string $role): array => ['active' => '1', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => 'approve', 'job_title' => $role, 'assignee_kind' => 'user', 'user_id' => (string) $userId],
        ]];
        $saved = fn (): string => (string) $this->db->table('route_steps s')->select('j.name')->join('job_titles j', 'j.id = s.job_title_id')
            ->where('s.route_id', $id)->get()->getRowArray()['name'];

        $this->actingAs($this->admin)->post('admin/routes/' . $id, $post(''));
        $this->assertSame('Finance Controller', $saved());

        // A role typed by the admin is kept.
        $this->actingAs($this->admin)->post('admin/routes/' . $id, $post('Custom Role'));
        $this->assertSame('Custom Role', $saved());

        $this->assertStringContainsString('data-title="Finance Controller"', (string) $this->actingAs($this->admin)->get('admin/routes/' . $id . '/edit')->getBody());
    }

    public function testStepTimeLimitIsSavedShownAndValidated(): void
    {
        $this->importSample();
        $id     = $this->routeId('HCSRL', 'HC Gym');
        $userId = (int) $this->db->table('route_steps')->where('route_id', $id)->orderBy('step_order')->get()->getResultArray()[0]['assignee_user_id'];
        $post   = fn (string $sla, string $type = 'approve'): array => ['active' => '1', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => $type, 'job_title' => 'X', 'assignee_kind' => 'user', 'user_id' => (string) $userId, 'sla_days' => $sla],
        ]];
        $limit = fn (): ?int => $this->db->table('route_steps')->where('route_id', $id)->get()->getRowArray()['sla_days'] ?? null;

        $this->actingAs($this->admin)->post('admin/routes/' . $id, $post('3'));
        $this->assertSame(3, $limit());
        $this->assertStringContainsString('value="3"', (string) $this->actingAs($this->admin)->get('admin/routes/' . $id . '/edit')->getBody());

        // Rubbish, zero and a limit on a notify step are refused and change nothing.
        foreach ([$post('abc'), $post('0'), $post('400'), $post('2', 'notify')] as $bad) {
            $this->actingAs($this->admin)->post('admin/routes/' . $id, $bad);
            $this->assertSame(3, $limit());
        }

        $this->actingAs($this->admin)->post('admin/routes/' . $id, $post(''));
        $this->assertNull($limit());
    }

    public function testUserStepWithoutAUserIsRejectedAndNothingChanges(): void
    {
        $this->importSample();
        $id     = $this->routeId('HCSRL', 'HC Gym');
        $before = $this->db->table('route_steps')->where('route_id', $id)->countAllResults();

        $this->actingAs($this->admin)->post('admin/routes/' . $id, ['active' => '1', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => 'approve', 'job_title' => 'X', 'assignee_kind' => 'user', 'user_id' => ''],
        ]]);

        $this->assertSame($before, $this->db->table('route_steps')->where('route_id', $id)->countAllResults());
    }

    public function testVariantCannotBeSwitchedOnWithoutATrigger(): void
    {
        $this->importSample();
        $id    = $this->routeId('IMAGINE', 'Procurement', 'extra_approval');
        $steps = $this->db->table('route_steps')->where('route_id', $id)->orderBy('step_order')->get()->getResultArray();

        // Imported shared mailboxes are memberless teams; give them an approver so the route can be switched on.
        $company = (int) $this->db->table('routes')->where('id', $id)->get()->getRowArray()['company_id'];
        $member  = $this->makeUser('Team Approver');
        $this->join($member, $company);
        foreach (array_unique(array_filter(array_column($steps, 'team_id'))) as $teamId) {
            $this->addToTeam((int) $teamId, $member);
        }

        $rows = [];
        foreach ($steps as $i => $s) {
            $rows['s' . $i] = ['step_type' => $s['step_type'], 'job_title' => 'Role', 'assignee_kind' => $s['assignee_kind'], 'user_id' => (string) ($s['assignee_user_id'] ?? ''), 'team_id' => (string) ($s['team_id'] ?? ''), 'label' => (string) $s['assignee_label'], 'email' => ''];
        }

        $this->actingAs($this->admin)->post('admin/routes/' . $id, ['active' => '1', 'variant_trigger' => 'none', 'steps' => $rows]);
        $this->assertSame(0, (int) $this->db->table('routes')->where('id', $id)->get()->getRow()->active);

        $this->actingAs($this->admin)->post('admin/routes/' . $id, ['active' => '1', 'variant_trigger' => 'amount_threshold', 'trigger_value' => 'lots', 'steps' => $rows]);
        $this->assertSame(0, (int) $this->db->table('routes')->where('id', $id)->get()->getRow()->active);

        $this->actingAs($this->admin)->post('admin/routes/' . $id, ['active' => '1', 'variant_trigger' => 'amount_threshold', 'trigger_value' => '5000', 'steps' => $rows]);
        $route = $this->db->table('routes')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, (int) $route['active']);
        $this->assertSame('amount_threshold', $route['variant_trigger']);
        $this->assertSame('5000', (string) $route['trigger_value']);
    }

    public function testCreateRouteCreatesTypeAndRejectsDuplicates(): void
    {
        $company = $this->makeCompany('ACME');

        $this->actingAs($this->admin)->post('admin/routes', ['company_id' => (string) $company, 'type_name' => 'Fuel  Costs', 'variant' => 'default'])->assertStatus(302);
        $this->assertSame(1, $this->db->table('routes')->countAllResults());
        $this->assertSame('Fuel Costs', $this->db->table('invoice_types')->get()->getRow()->name);

        $this->actingAs($this->admin)->post('admin/routes', ['company_id' => (string) $company, 'type_name' => 'fuel costs', 'variant' => 'default']);
        $this->assertSame(1, $this->db->table('routes')->countAllResults());
    }

    public function testCreateRouteFromADefaultTypeAddsItToTheCompany(): void
    {
        $company = $this->makeCompany('ACME');
        $this->db->table('default_invoice_types')->insert(['name' => 'Utilities', 'active' => 1]);
        $defaultId = (int) $this->db->insertID();

        $page = $this->actingAs($this->admin)->get('admin/routes/new');
        $page->assertSee('Utilities');
        $page->assertSee('Other (custom type)');

        $this->actingAs($this->admin)->post('admin/routes', ['company_id' => (string) $company, 'type_choice' => (string) $defaultId, 'type_name' => '', 'variant' => 'default'])->assertStatus(302);
        $type = $this->db->table('invoice_types')->where('company_id', $company)->get()->getRowArray();
        $this->assertSame('Utilities', $type['name']);
        $this->assertSame($defaultId, (int) $type['default_type_id']);
        $this->assertSame(1, $this->db->table('routes')->countAllResults());

        $this->actingAs($this->admin)->post('admin/routes', ['company_id' => (string) $company, 'type_choice' => '99999', 'variant' => 'default']);
        $this->assertSame(1, $this->db->table('routes')->countAllResults());

        $this->actingAs($this->admin)->post('admin/routes', ['company_id' => (string) $company, 'type_choice' => 'other', 'type_name' => '', 'variant' => 'default']);
        $this->assertSame(1, $this->db->table('routes')->countAllResults());

        $this->actingAs($this->admin)->post('admin/routes', ['company_id' => (string) $company, 'type_choice' => 'other', 'type_name' => 'Bespoke', 'variant' => 'default'])->assertStatus(302);
        $this->assertSame(2, $this->db->table('routes')->countAllResults());
    }

    public function testImportReviewShowsIssuesAndCommitCreatesRoutes(): void
    {
        $dir = WRITEPATH . 'uploads/imports';
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stored = bin2hex(random_bytes(8)) . '.csv';
        copy(SUPPORTPATH . 'fixtures/routing_sample.csv', $dir . '/' . $stored);
        $this->db->table('routing_import_batches')->insert([
            'filename' => 'routing_sample.csv', 'stored_name' => $stored, 'uploaded_by' => $this->admin->id, 'status' => 'uploaded', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $batch = (int) $this->db->insertID();

        $review = $this->actingAs($this->admin)->get('admin/import/' . $batch);
        $review->assertStatus(200);
        $this->assertStringContainsString('no email column', $review->getBody());
        $review->assertSee('Test data skipped');
        $this->assertStringContainsString('no role order', $review->getBody());
        $this->assertSame(0, $this->db->table('routes')->countAllResults()); // review writes nothing

        $this->actingAs($this->admin)->post('admin/import/' . $batch . '/commit')->assertRedirectTo(site_url('admin/import/' . $batch));

        $this->assertGreaterThan(0, $this->db->table('routes')->countAllResults());
        $this->assertSame('committed', $this->db->table('routing_import_batches')->where('id', $batch)->get()->getRow()->status);

        $done = $this->actingAs($this->admin)->get('admin/import/' . $batch);
        $done->assertStatus(200);
        $done->assertSee('Routes created');

        @unlink($dir . '/' . $stored);
    }

    public function testDiscardedBatchCannotBeCommitted(): void
    {
        $this->db->table('routing_import_batches')->insert([
            'filename' => 'x.csv', 'stored_name' => 'missing.csv', 'uploaded_by' => $this->admin->id, 'status' => 'discarded', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $batch = (int) $this->db->insertID();

        $this->actingAs($this->admin)->post('admin/import/' . $batch . '/commit')->assertRedirectTo(site_url('admin/import'));
        $this->assertSame(0, $this->db->table('routes')->countAllResults());
    }
}

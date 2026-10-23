<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\AdminAccess;
use CodeIgniter\Shield\Entities\User;
use RuntimeException;
use Tests\Support\FeatureTestCase;

/**
 * The Workflow Manager level opens invoice types, suppliers, approval routes and route variants, and nothing else.
 * Only administrators grant it. Uses invoicing_test.
 *
 * @internal
 */
final class WorkflowManagerRoleTest extends FeatureTestCase
{
    private const WORKFLOW_SCREENS = [
        'admin/default-invoice-types', 'admin/default-invoice-types/upload', 'admin/suppliers', 'admin/suppliers/upload',
        'admin/routes', 'admin/routes/new', 'admin/route-variants', 'admin/route-variants/new',
    ];

    private const OTHER_SCREENS = [
        'admin/users', 'admin/users/new', 'admin/teams', 'admin/companies', 'admin/groups', 'admin/import', 'admin/delegations',
        'admin/audit', 'admin/group-level-activity', 'admin/analytics', 'admin/analytics/cycle-time',
        'admin/settings', 'admin/sharepoint', 'admin/api-tokens', 'admin/group-level',
    ];

    private function person(string $email, string $name, bool $admin = false, bool $manager = false, bool $workflow = false, bool $active = true): User
    {
        $users = model(UserModel::class);
        $users->save(new User([
            'email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $name,
            'is_admin' => $admin ? 1 : 0, 'is_manager' => $manager ? 1 : 0, 'is_workflow' => $workflow ? 1 : 0, 'active' => $active ? 1 : 0,
        ]));

        return $users->findById($users->getInsertID());
    }

    public function testAWorkflowManagerOpensTheFourWorkflowScreens(): void
    {
        $wf = $this->person('wf@example.test', 'Wendy Workflow', workflow: true);

        foreach (self::WORKFLOW_SCREENS as $uri) {
            $this->actingAs($wf)->get($uri)->assertStatus(200);
        }
    }

    public function testAWorkflowManagerIsRefusedEverywhereElse(): void
    {
        $wf = $this->person('wf@example.test', 'Wendy Workflow', workflow: true);

        foreach (self::OTHER_SCREENS as $uri) {
            $this->actingAs($wf)->get($uri)->assertRedirectTo(site_url('/'));
        }
        $this->actingAs($wf)->post('admin/users', ['display_name' => 'X', 'email' => 'x@example.test'])->assertRedirectTo(site_url('/'));
        $this->assertSame(0, $this->db->table('users')->where('display_name', 'X')->countAllResults());
    }

    public function testPlainAndDisabledUsersAreRefusedTheWorkflowScreens(): void
    {
        $plain    = $this->person('plain@example.test', 'Plain');
        $disabled = $this->person('off@example.test', 'Disabled', workflow: true, active: false);

        foreach (self::WORKFLOW_SCREENS as $uri) {
            $this->actingAs($plain)->get($uri)->assertRedirectTo(site_url('/'));
            $this->actingAs($disabled)->get($uri)->assertRedirectTo(site_url('/'));
        }
    }

    public function testManagersAndAdministratorsStillOpenTheWorkflowScreens(): void
    {
        $manager = $this->person('mgr@example.test', 'Manager', manager: true);
        $admin   = $this->person('boss@example.test', 'Admin', admin: true);

        foreach (self::WORKFLOW_SCREENS as $uri) {
            $this->actingAs($manager)->get($uri)->assertStatus(200);
            $this->actingAs($admin)->get($uri)->assertStatus(200);
        }
    }

    public function testAWorkflowManagerCanMaintainSuppliersVariantsAndTypePriority(): void
    {
        $wf = $this->person('wf@example.test', 'Wendy Workflow', workflow: true);

        $this->actingAs($wf)->post('admin/suppliers', ['code' => 'ACM001', 'name' => 'Acme Ltd'])->assertRedirectTo(site_url('admin/suppliers'));
        $this->assertSame(1, $this->db->table('suppliers')->where('code', 'ACM001')->countAllResults());
        $audit = $this->db->table('audit_log')->where('event', 'supplier.created')->get()->getRowArray();
        $this->assertSame((int) $wf->id, (int) $audit['user_id'], 'the change is attributed to the workflow manager');

        $this->actingAs($wf)->post('admin/route-variants', ['name' => 'Fast track'])->assertRedirectTo(site_url('admin/route-variants'));
        $this->assertSame(1, $this->db->table('route_variants')->where('name', 'Fast track')->countAllResults());

        $co = $this->makeCompany('AAA');
        $this->makeRoute($co, [['type' => 'approve', 'role' => 'Boss']], 'Hand made');
        $typeId = (int) $this->db->table('invoice_types')->where('name', 'Hand made')->get()->getRowArray()['id'];
        $this->actingAs($wf)->post('admin/invoice-types/' . $typeId . '/priority', ['high_priority' => '1']);
        $this->assertSame(1, (int) $this->db->table('invoice_types')->where('id', $typeId)->get()->getRowArray()['high_priority']);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'invoice_type.priority_changed')->countAllResults());
    }

    public function testTheSidebarShowsOnlyTheFourWorkflowEntries(): void
    {
        $wf   = $this->person('wf@example.test', 'Wendy Workflow', workflow: true);
        $html = (string) $this->actingAs($wf)->get('admin/routes')->getBody();

        foreach (['admin/default-invoice-types', 'admin/suppliers', 'admin/routes', 'admin/route-variants'] as $href) {
            $this->assertStringContainsString($href, $html);
        }
        foreach (['admin/users', 'admin/teams', 'admin/companies', 'admin/groups', 'admin/import', 'admin/delegations', 'admin/audit', 'admin/analytics', 'admin/settings', 'Administration', 'System'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->assertStringContainsString('Workflow Manager', $html, 'the role label');
    }

    public function testOnlyAnAdministratorCanGrantOrRevokeIt(): void
    {
        $admin   = $this->person('boss@example.test', 'Admin', admin: true);
        $manager = $this->person('mgr@example.test', 'Manager', manager: true);
        $target  = $this->person('target@example.test', 'Target');

        // A manager's attempt through the user form changes nothing.
        $this->actingAs($manager)->post('admin/users/' . $target->id, ['display_name' => 'Target', 'active' => '1', 'is_workflow' => '1'])->assertRedirect();
        $this->assertSame(0, (int) $this->db->table('users')->where('id', $target->id)->get()->getRowArray()['is_workflow']);

        $this->actingAs($admin)->post('admin/users/' . $target->id, ['display_name' => 'Target', 'active' => '1', 'is_workflow' => '1'])->assertRedirect();
        $this->assertSame(1, (int) $this->db->table('users')->where('id', $target->id)->get()->getRowArray()['is_workflow']);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'workflow_manager.granted')->countAllResults());

        $this->actingAs($admin)->post('admin/users/' . $target->id, ['display_name' => 'Target', 'active' => '1'])->assertRedirect();
        $this->assertSame(0, (int) $this->db->table('users')->where('id', $target->id)->get()->getRowArray()['is_workflow']);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'workflow_manager.revoked')->countAllResults());
    }

    public function testAUserCannotBeAManagerAndAWorkflowManagerTogether(): void
    {
        $admin   = $this->person('boss@example.test', 'Admin', admin: true);
        $manager = $this->person('mgr@example.test', 'Manager', manager: true);
        $wf      = $this->person('wf@example.test', 'Wendy Workflow', workflow: true);
        $access  = new AdminAccess();
        $flags   = fn (User $u): array => array_map('intval', array_intersect_key($this->db->table('users')->where('id', $u->id)->get()->getRowArray(), ['is_manager' => 1, 'is_workflow' => 1]));

        // The service refuses either direction, and says why.
        try {
            $access->setWorkflowManager((int) $admin->id, (int) $manager->id, true);
            $this->fail('A manager must not become a workflow manager as well.');
        } catch (RuntimeException $e) {
            $this->assertSame(AdminAccess::MANAGER_OR_WORKFLOW, $e->getMessage());
        }
        try {
            $access->setManager((int) $admin->id, (int) $wf->id, true);
            $this->fail('A workflow manager must not become a manager as well.');
        } catch (RuntimeException $e) {
            $this->assertSame(AdminAccess::MANAGER_OR_WORKFLOW, $e->getMessage());
        }
        $this->assertSame(['is_manager' => 1, 'is_workflow' => 0], $flags($manager));
        $this->assertSame(['is_manager' => 0, 'is_workflow' => 1], $flags($wf));

        // Ticking both on the form saves nothing at all (not even the name) and explains itself.
        $r = $this->actingAs($admin)->post('admin/users/' . $wf->id, ['display_name' => 'Renamed', 'active' => '1', 'is_manager' => '1', 'is_workflow' => '1']);
        $r->assertRedirect();
        $this->assertSame(['is_manager' => 0, 'is_workflow' => 1], $flags($wf));
        $this->assertSame('Wendy Workflow', $this->db->table('users')->where('id', $wf->id)->get()->getRowArray()['display_name']);
        $this->assertSame(AdminAccess::MANAGER_OR_WORKFLOW, session('error'));
    }

    public function testSwitchingAUserFromOneLevelToTheOtherWorksInOneSave(): void
    {
        $admin  = $this->person('boss@example.test', 'Admin', admin: true);
        $wf     = $this->person('wf@example.test', 'Wendy Workflow', workflow: true);
        $flags  = fn (User $u): array => array_map('intval', array_intersect_key($this->db->table('users')->where('id', $u->id)->get()->getRowArray(), ['is_manager' => 1, 'is_workflow' => 1]));

        $this->actingAs($admin)->post('admin/users/' . $wf->id, ['display_name' => 'Wendy Workflow', 'active' => '1', 'is_manager' => '1'])->assertRedirect();
        $this->assertSame(['is_manager' => 1, 'is_workflow' => 0], $flags($wf), 'workflow manager promoted to manager');

        $this->actingAs($admin)->post('admin/users/' . $wf->id, ['display_name' => 'Wendy Workflow', 'active' => '1', 'is_workflow' => '1'])->assertRedirect();
        $this->assertSame(['is_manager' => 0, 'is_workflow' => 1], $flags($wf), 'and back to workflow manager');
    }

    public function testCreatingAUserWithBothSwitchesIsRefusedBeforeAnythingIsCreated(): void
    {
        $admin = $this->person('boss@example.test', 'Admin', admin: true);

        $this->actingAs($admin)->post('admin/users', ['email' => 'new@example.test', 'display_name' => 'New Person', 'is_manager' => '1', 'is_workflow' => '1'])->assertRedirect();

        $this->assertSame(0, $this->db->table('users')->where('display_name', 'New Person')->countAllResults());
        $this->assertSame(AdminAccess::MANAGER_OR_WORKFLOW, session('error'));
    }

    public function testTheUserFormExplainsAndEnforcesTheEitherOrChoice(): void
    {
        $admin = $this->person('boss@example.test', 'Admin', admin: true);
        $html  = (string) $this->actingAs($admin)->get('admin/users/new')->getBody();

        $this->assertStringContainsString('Not combined with Manager', $html);
        $this->assertStringContainsString("document.getElementById('is_workflow')", $html, 'the either-or script');
    }

    public function testTheServiceRefusesEveryoneButAdministrators(): void
    {
        $manager = $this->person('mgr@example.test', 'Manager', manager: true);
        $wf      = $this->person('wf@example.test', 'Wendy Workflow', workflow: true);
        $access  = new AdminAccess();

        foreach ([$manager, $wf] as $actor) {
            try {
                $access->setWorkflowManager((int) $actor->id, (int) $wf->id, true);
                $this->fail('Expected a RuntimeException for a non-administrator.');
            } catch (RuntimeException $e) {
                $this->assertSame('Administrator access is required.', $e->getMessage());
            }
        }
    }

    public function testItDoesNotCountAsManagerAccessElsewhere(): void
    {
        $wf     = $this->person('wf@example.test', 'Wendy Workflow', workflow: true);
        $access = new AdminAccess();

        $this->assertTrue($access->isWorkflowManager((int) $wf->id));
        $this->assertTrue($access->canManageWorkflow((int) $wf->id));
        $this->assertFalse($access->canManage((int) $wf->id), 'delegation overrides, routing import and user import stay manager-only');
        $this->assertFalse($access->isManager((int) $wf->id));
        $this->assertFalse($access->isAdmin((int) $wf->id));
        $this->assertFalse($access->canManageWorkflow((int) $this->person('plain@example.test', 'Plain')->id));
    }

    public function testTheUserListShowsAndFiltersWorkflowManagers(): void
    {
        $admin = $this->person('boss@example.test', 'Admin', admin: true);
        $this->person('wf@example.test', 'Wendy Workflow', workflow: true);
        $this->person('plain@example.test', 'Pat Plain');

        $all = (string) $this->actingAs($admin)->get('admin/users')->getBody();
        $this->assertStringContainsString('Workflow managers', $all, 'the filter option');
        $this->assertStringContainsString('text-bg-info">Workflow<', $all, 'the badge');

        $only = (string) $this->actingAs($admin)->get('admin/users?filter=workflow')->getBody();
        $this->assertStringContainsString('Wendy Workflow', $only);
        $this->assertStringNotContainsString('Pat Plain', $only);
    }
}

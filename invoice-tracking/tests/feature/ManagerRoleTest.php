<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\AdminAccess;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use RuntimeException;
use Tests\Support\AppTestCase;

/**
 * Managers run workflow and day-to-day administration; system screens and the admin/manager/group-level
 * switches stay with administrators, and a manager can never act on an administrator.
 *
 * @internal
 */
final class ManagerRoleTest extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected function setUp(): void
    {
        parent::setUp();

        $filters                    = new \Config\Filters();
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    private function shieldUser(string $email, string $name, bool $admin = false, bool $manager = false): User
    {
        $users = model(UserModel::class);
        $user  = new User(['email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $name, 'is_admin' => $admin ? 1 : 0, 'is_manager' => $manager ? 1 : 0, 'active' => 1]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    public function testManagerOpensWorkflowAndAdministrationScreens(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);

        foreach (['admin/companies', 'admin/users', 'admin/users/new', 'admin/teams', 'admin/routes', 'admin/route-variants', 'admin/import', 'admin/delegations', 'admin/audit', 'admin/group-level-activity'] as $uri) {
            $this->actingAs($manager)->get($uri)->assertStatus(200);
        }
    }

    public function testManagerCannotOpenSystemScreens(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);

        foreach (['admin/settings', 'admin/sharepoint', 'admin/sharepoint-sync', 'admin/api-tokens', 'admin/group-level'] as $uri) {
            $this->actingAs($manager)->get($uri)->assertRedirectTo(site_url('/'));
        }
    }

    public function testPlainUserCannotOpenManagerScreens(): void
    {
        $user = $this->shieldUser('plain@example.test', 'Plain');

        foreach (['admin/routes', 'admin/users', 'admin/teams'] as $uri) {
            $this->actingAs($user)->get($uri)->assertRedirectTo(site_url('/'));
        }
    }

    public function testDisabledManagerIsRefused(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);
        $this->db->table('users')->where('id', $manager->id)->update(['active' => 0]);

        $this->actingAs(model(UserModel::class)->findById($manager->id))->get('admin/routes')->assertRedirectTo(site_url('/'));
    }

    public function testAdminStillOpensSystemScreens(): void
    {
        $admin = $this->shieldUser('boss@example.test', 'Admin', true);

        foreach (['admin/settings', 'admin/sharepoint', 'admin/api-tokens', 'admin/group-level', 'admin/routes'] as $uri) {
            $this->actingAs($admin)->get($uri)->assertStatus(200);
        }
    }

    public function testSidebarShowsSystemEntriesOnlyToAdmins(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);
        $admin   = $this->shieldUser('boss@example.test', 'Admin', true);

        $html = $this->actingAs($manager)->get('admin/routes')->getBody();
        $this->assertStringContainsString('Approval Routes', $html);
        $this->assertStringContainsString('Audit Log', $html);
        $this->assertStringNotContainsString('System Settings', $html);
        $this->assertStringNotContainsString('SharePoint Connections', $html);
        $this->assertStringNotContainsString('Group-level Users', $html);

        $this->assertStringContainsString('System Settings', $this->actingAs($admin)->get('admin/routes')->getBody());
    }

    public function testManagerCreatesUserButCannotGrantPrivilegedAccess(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);

        $this->actingAs($manager)->post('admin/users', [
            'email' => 'new@example.test', 'display_name' => 'New Person',
            'is_admin' => '1', 'is_manager' => '1', 'group_level' => '1',
        ])->assertRedirect();

        $row = $this->db->table('users')->where('display_name', 'New Person')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['is_admin']);
        $this->assertSame(0, (int) $row['is_manager']);
        $this->assertSame(0, $this->db->table('group_level_users')->where('user_id', $row['id'])->countAllResults());
    }

    public function testManagerEditingUserCannotChangeAdminManagerOrGroupLevel(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);
        $target  = $this->shieldUser('target@example.test', 'Target');

        $this->actingAs($manager)->post('admin/users/' . $target->id, [
            'display_name' => 'Renamed', 'active' => '1', 'is_admin' => '1', 'is_manager' => '1', 'group_level' => '1',
        ])->assertRedirect();

        $row = $this->db->table('users')->where('id', $target->id)->get()->getRowArray();
        $this->assertSame('Renamed', $row['display_name']);
        $this->assertSame(0, (int) $row['is_admin']);
        $this->assertSame(0, (int) $row['is_manager']);
        $this->assertSame(0, $this->db->table('group_level_users')->where('user_id', $target->id)->countAllResults());
    }

    public function testAdminCanMakeAManager(): void
    {
        $admin  = $this->shieldUser('boss@example.test', 'Admin', true);
        $target = $this->shieldUser('target@example.test', 'Target');

        $this->actingAs($admin)->post('admin/users/' . $target->id, ['display_name' => 'Target', 'active' => '1', 'is_manager' => '1'])->assertRedirect();

        $this->assertSame(1, (int) $this->db->table('users')->where('id', $target->id)->get()->getRowArray()['is_manager']);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'manager.granted')->countAllResults());
    }

    public function testManagerCannotEditDisableOrResetAnAdministrator(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);
        $admin   = $this->shieldUser('boss@example.test', 'Admin', true);
        $this->shieldUser('other@example.test', 'Other Admin', true);

        $this->actingAs($manager)->post('admin/users/' . $admin->id, ['display_name' => 'Hacked', 'active' => ''])->assertRedirect();
        $row = $this->db->table('users')->where('id', $admin->id)->get()->getRowArray();
        $this->assertSame('Admin', $row['display_name']);
        $this->assertSame(1, (int) $row['active']);

        $access = new AdminAccess();
        $this->expectException(RuntimeException::class);
        $access->setTemporaryPassword((int) $manager->id, (int) $admin->id, 'a-long-test-password-2');
    }

    public function testManagerCannotGrantManagerOrAdminThroughTheService(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);
        $target  = $this->shieldUser('target@example.test', 'Target');

        $this->expectException(RuntimeException::class);
        (new AdminAccess())->setManager((int) $manager->id, (int) $target->id, true);
    }

    public function testEditingAUserSavesEmailAndCompanyOptionsWithoutMemberTick(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);
        $company = $this->makeCompany('ACME');
        $this->db->table('users')->insert(['display_name' => 'Placeholder', 'active' => 0, 'email_missing' => 1, 'auth_source' => 'local', 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $this->db->insertID();

        $this->actingAs($manager)->post('admin/users/' . $id, [
            'display_name' => 'Placeholder', 'email' => 'Placeholder@Example.test',
            'submit' => [$company => '1'], 'approve' => [$company => '1'],
        ])->assertRedirect();

        $user = $this->db->table('users')->where('id', $id)->get()->getRowArray();
        $this->assertSame(0, (int) $user['email_missing']);
        $this->assertSame(1, (int) $user['active']);
        $this->assertSame(1, $this->db->table('auth_identities')->where(['user_id' => $id, 'type' => 'email_password', 'secret' => 'placeholder@example.test'])->countAllResults());

        $m = $this->db->table('user_companies')->where(['user_id' => $id, 'company_id' => $company])->get()->getRowArray();
        $this->assertNotNull($m);
        $this->assertSame(1, (int) $m['can_submit']);
        $this->assertSame(1, (int) $m['can_approve']);
    }

    public function testEmailAlreadyUsedByAnotherUserIsRefused(): void
    {
        $manager = $this->shieldUser('mgr@example.test', 'Manager', false, true);
        $target  = $this->shieldUser('target@example.test', 'Target');

        $this->actingAs($manager)->post('admin/users/' . $target->id, ['display_name' => 'Target', 'active' => '1', 'email' => 'mgr@example.test'])->assertRedirect();

        $this->assertSame(1, $this->db->table('auth_identities')->where(['user_id' => $target->id, 'secret' => 'target@example.test'])->countAllResults());
    }
}

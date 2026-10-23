<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class AdminScreensTest extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests exercise screens and rules, not CSRF (see CsrfEnforcedTest).
        $filters                    = new \Config\Filters();
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    private function shieldUser(string $email, string $name, bool $admin = false): User
    {
        $users = model(UserModel::class);
        $user  = new User(['email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $name, 'is_admin' => $admin ? 1 : 0, 'active' => 1]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    public function testSignedOutUserIsSentToLogin(): void
    {
        $result = $this->get('admin/companies');
        $result->assertRedirectTo(site_url('login'));
    }

    public function testNonAdminCannotOpenAdminScreens(): void
    {
        $user = $this->shieldUser('plain@example.test', 'Plain User');

        foreach (['admin/companies', 'admin/users', 'admin/group-level', 'admin/groups', 'admin/users/upload'] as $uri) {
            $result = $this->actingAs($user)->get($uri);
            $result->assertRedirectTo(site_url('/'));
        }
    }

    public function testAdminCanOpenAdminScreens(): void
    {
        $admin = $this->shieldUser('boss@example.test', 'The Admin', true);

        foreach (['admin/companies', 'admin/companies/new', 'admin/users', 'admin/users/new', 'admin/group-level', 'admin/groups', 'admin/users/upload'] as $uri) {
            $result = $this->actingAs($admin)->get($uri);
            $result->assertStatus(200);
        }
    }

    public function testDashboardShowsOnlyMemberCompanies(): void
    {
        $user = $this->shieldUser('member@example.test', 'Member');
        $acme = $this->makeCompany('ACME');
        $this->makeCompany('SECRET');
        $this->join((int) $user->id, $acme);

        $result = $this->actingAs($user)->get('/');
        $result->assertStatus(200);
        $result->assertSee('ACME');
        $result->assertDontSee('SECRET');
    }

    public function testAdminCreatesCompany(): void
    {
        $admin = $this->shieldUser('boss@example.test', 'The Admin', true);

        $result = $this->actingAs($admin)->post('admin/companies', ['code' => 'newco', 'name' => 'New Co', 'active' => '1']);
        $result->assertRedirectTo(site_url('admin/companies'));

        $row = $this->db->table('companies')->where('code', 'NEWCO')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('New Co', $row['name']);
    }

    public function testDuplicateCompanyCodeIsRejected(): void
    {
        $admin = $this->shieldUser('boss@example.test', 'The Admin', true);
        $this->makeCompany('DUPE');

        $this->actingAs($admin)->post('admin/companies', ['code' => 'DUPE', 'name' => 'Again']);

        $this->assertSame(1, $this->db->table('companies')->where('code', 'DUPE')->countAllResults());
    }

    public function testAdminCreatesUserWithMembershipAndGroupLevel(): void
    {
        $admin = $this->shieldUser('boss@example.test', 'The Admin', true);
        $acme  = $this->makeCompany('ACME');

        $result = $this->actingAs($admin)->post('admin/users', [
            'email'        => 'new.person@example.test',
            'display_name' => 'New Person',
            'member'       => [(string) $acme => '1'],
            'submit'       => [(string) $acme => '1'],
            'group_level'  => '1',
        ]);
        $result->assertStatus(302);

        $created = model(UserModel::class)->findByCredentials(['email' => 'new.person@example.test']);
        $this->assertNotNull($created);
        $this->assertSame(1, $this->db->table('user_companies')->where(['user_id' => $created->id, 'company_id' => $acme, 'can_submit' => 1])->countAllResults());
        $this->assertSame(1, $this->db->table('group_level_users')->where('user_id', $created->id)->countAllResults());
    }

    public function testNonAdminCannotCreateUsersOrGrantGroupLevel(): void
    {
        $user   = $this->shieldUser('plain@example.test', 'Plain User');
        $victim = $this->shieldUser('victim@example.test', 'Victim');

        $this->actingAs($user)->post('admin/users', ['email' => 'x@example.test', 'display_name' => 'X']);
        $this->actingAs($user)->post('admin/group-level', ['user_id' => (string) $victim->id]);

        $this->assertNull(model(UserModel::class)->findByCredentials(['email' => 'x@example.test']));
        $this->assertSame(0, $this->db->table('group_level_users')->countAllResults());
    }

    public function testUserEditPageShowsResetAndTransferTools(): void
    {
        $admin  = $this->shieldUser('boss@example.test', 'Boss', true);
        $target = $this->shieldUser('target@example.test', 'Target');

        $result = $this->actingAs($admin)->get('admin/users/' . $target->id . '/edit');
        $result->assertOK();
        $result->assertSee('transfer-work');
        $result->assertSee('reset-password');
    }

    public function testAdminSetsTemporaryPasswordAndForcesChange(): void
    {
        $admin  = $this->shieldUser('boss@example.test', 'Boss', true);
        $target = $this->shieldUser('target@example.test', 'Target');

        $result = $this->actingAs($admin)->post('admin/users/' . $target->id . '/reset-password', [
            'new_password'         => 'Tr1cky-Temp-Passphrase-88',
            'new_password_confirm' => 'Tr1cky-Temp-Passphrase-88',
        ]);
        $result->assertRedirectTo(site_url('admin/users/' . $target->id . '/edit'));

        $fresh = model(UserModel::class)->findById($target->id);
        $this->assertTrue($fresh->requiresPasswordReset());
        $this->assertTrue(service('passwords')->verify('Tr1cky-Temp-Passphrase-88', (string) $fresh->password_hash));
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'user.password_reset')->countAllResults());
    }

    public function testNonAdminCannotSetPasswordAndMismatchIsRejected(): void
    {
        $plain  = $this->shieldUser('plain@example.test', 'Plain');
        $admin  = $this->shieldUser('boss@example.test', 'Boss', true);
        $target = $this->shieldUser('target@example.test', 'Target');
        $post   = ['new_password' => 'Tr1cky-Temp-Passphrase-88', 'new_password_confirm' => 'Tr1cky-Temp-Passphrase-88'];

        $this->actingAs($plain)->post('admin/users/' . $target->id . '/reset-password', $post);
        $this->actingAs($admin)->post('admin/users/' . $target->id . '/reset-password', ['new_password' => 'Tr1cky-Temp-Passphrase-88', 'new_password_confirm' => 'different']);

        $fresh = model(UserModel::class)->findById($target->id);
        $this->assertFalse($fresh->requiresPasswordReset());
        $this->assertTrue(service('passwords')->verify('a-long-test-password-1', (string) $fresh->password_hash));
    }

    public function testForcedPasswordResetRedirectsToChangePassword(): void
    {
        $user = $this->shieldUser('first@example.test', 'First Login');
        $user->forcePasswordReset();

        $result = $this->actingAs($user)->get('/');
        $result->assertRedirectTo(site_url('account/password'));
    }

    public function testPasswordChangeClearsForcedReset(): void
    {
        $user = $this->shieldUser('first@example.test', 'First Login');
        $user->forcePasswordReset();

        $result = $this->actingAs($user)->post('account/password', [
            'password'         => 'Tr1cky-New-Passphrase-77',
            'password_confirm' => 'Tr1cky-New-Passphrase-77',
        ]);
        $result->assertRedirectTo(site_url('/'));

        $fresh = model(UserModel::class)->findById($user->id);
        $this->assertFalse($fresh->requiresPasswordReset());
    }
}

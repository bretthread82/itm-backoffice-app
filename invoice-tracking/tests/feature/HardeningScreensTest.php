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
final class HardeningScreensTest extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected function setUp(): void
    {
        parent::setUp();
        $filters                    = new \Config\Filters();
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        service('cache')->clean();
        parent::tearDown();
    }

    private function shieldUser(string $email, bool $admin): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $email, 'is_admin' => $admin ? 1 : 0, 'active' => 1]));

        return $users->findById($users->getInsertID());
    }

    public function testRepeatedSignInAttemptsAreThrottled(): void
    {
        $codes = [];
        for ($i = 0; $i < 14; $i++) {
            $codes[] = $this->post('login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-' . $i])->response()->getStatusCode();
        }

        $this->assertNotContains(429, array_slice($codes, 0, 5), 'normal use is not blocked');
        $this->assertContains(429, $codes, 'guessing is stopped');
    }

    public function testViewingTheLoginFormIsNeverThrottled(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->assertNotSame(429, $this->get('login')->response()->getStatusCode());
        }
    }

    public function testAuditLogIsAdminOnlyAndFilterable(): void
    {
        $admin = $this->shieldUser('boss@example.test', true);
        $plain = $this->shieldUser('plain@example.test', false);
        $this->db->table('audit_log')->insert(['user_id' => $admin->id, 'event' => 'sharepoint.created', 'entity' => 'sharepoint_connection', 'entity_id' => 3, 'created_at' => date('Y-m-d H:i:s')]);
        $this->db->table('audit_log')->insert(['user_id' => $admin->id, 'event' => 'zzuser.created', 'detail' => 'MARKER-B', 'created_at' => date('Y-m-d H:i:s')]);

        $this->actingAs($plain)->get('admin/audit')->assertRedirectTo(site_url('/'));

        $all = $this->actingAs($admin)->get('admin/audit');
        $all->assertStatus(200);
        $all->assertSee('sharepoint.created');
        $all->assertSee('MARKER-B');

        $only = $this->actingAs($admin)->get('admin/audit?event=sharepoint.');
        $only->assertSee('sharepoint.created');
        $only->assertDontSee('MARKER-B');
    }
}

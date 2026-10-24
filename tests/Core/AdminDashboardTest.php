<?php

declare(strict_types=1);

namespace Tests\Core;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Payables\PayablesTestCase;

/**
 * The Admin dashboard (account menu > Administration): what each kind of user is offered, and that the
 * sidebar no longer carries the Administration group. Real requests through the filter pipeline.
 *
 * @internal
 */
final class AdminDashboardTest extends PayablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private int $super;
    private int $backupManager;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        Services::resetSingle('modules');
        setting()->set('ModuleState.disabled', []);
        session();

        $this->super         = $this->makeSuperUser('super@example.test');
        $this->backupManager = $this->makeUserNamed('Bea Backups', 'bea@example.test');
        $this->staff         = $this->makeUserNamed('Sam Staff', 'sam@example.test');

        $this->db->table('user_roles')->insert(['user_id' => $this->backupManager, 'role_id' => (int) $this->db->table('roles')->where('key', 'backups_manager')->get()->getRow()->id]);
        service('permissions')->forget($this->backupManager);
    }

    protected function tearDown(): void
    {
        setting()->set('ModuleState.disabled', []);
        Services::resetSingle('modules');
        parent::tearDown();
    }

    private function as(int $userId, string $path)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();

        $this->db->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        return $this->withSession(['user' => ['id' => $userId]])->get($path);
    }

    /** Status of a request, treating a thrown "page not found" as 404 as the app does. */
    private function statusOf(int $userId, string $path): int
    {
        try {
            return $this->as($userId, $path)->response()->getStatusCode();
        } catch (PageNotFoundException) {
            return 404;
        }
    }

    public function testSuperAdminSeesEverySectionAndTheLinksWork(): void
    {
        $page = $this->as($this->super, 'admin');
        $page->assertOK();

        foreach (['People and access', 'Payables', 'Customer invoices', 'Shared data', 'System'] as $heading) {
            $page->assertSee($heading);
        }

        foreach (['admin/users', 'admin/roles', 'payables-admin/routes', 'receivables-admin/routes', 'admin/suppliers', 'admin/audit', 'admin/backups'] as $url) {
            $this->assertStringContainsString('href="' . site_url($url) . '"', $page->getBody());
        }
    }

    public function testAUserSeesOnlyWhatTheyMayUse(): void
    {
        $page = $this->as($this->backupManager, 'admin');
        $page->assertOK();
        $this->assertStringContainsString('href="' . site_url('admin/backups') . '"', $page->getBody());
        $page->assertSee('System');

        foreach (['admin/users', 'admin/roles', 'admin/audit', 'payables-admin/routes', 'People and access'] as $hidden) {
            $page->assertDontSee($hidden);
        }
    }

    public function testSomeoneWithNoAdminOptionsGetsNotFound(): void
    {
        $this->assertSame(404, $this->statusOf($this->staff, 'admin'));
    }

    public function testASwitchedOffModulesOptionsDisappear(): void
    {
        $this->as($this->super, 'admin')->assertSee('payables-admin/routes');

        service('modules')->setEnabled('payables', false);
        Services::resetSingle('modules');
        service('permissions')->forget($this->super);

        $page = $this->as($this->super, 'admin');
        $page->assertDontSee('payables-admin/routes');
        $page->assertDontSee('Payables');
        $page->assertSee('admin/users');
    }

    public function testTheSidebarNoLongerHasAnAdministrationGroup(): void
    {
        $body = $this->as($this->super, '/')->getBody();

        $this->assertStringNotContainsString('data-nav-group="admin"', $body);
        $this->assertStringNotContainsString('>Administration<', explode('</aside>', $body)[0], 'nothing called Administration in the sidebar');
    }

    public function testTheAccountMenuLinkShowsOnlyForPeopleWithOptions(): void
    {
        $withLink = '</i>Administration</a>';

        $this->assertStringContainsString($withLink, $this->as($this->backupManager, '/')->getBody());
        $this->assertStringContainsString($withLink, $this->as($this->super, '/')->getBody());
        $this->assertStringNotContainsString($withLink, $this->as($this->staff, '/')->getBody());
    }

    public function testAdminPagesLinkBackToTheDashboard(): void
    {
        $crumb = 'class="itm-crumb"';

        $this->assertStringContainsString($crumb, $this->as($this->backupManager, 'admin/backups')->getBody());
        $this->assertStringContainsString($crumb, $this->as($this->super, 'payables-admin/routes')->getBody());
        $this->assertStringNotContainsString($crumb, $this->as($this->super, 'admin')->getBody(), 'the dashboard itself has no back link');
        $this->assertStringNotContainsString($crumb, $this->as($this->super, '/')->getBody());
    }

    public function testEveryAdminEntryHasAKnownSectionAndASummary(): void
    {
        $config = config(\Config\Backoffice::class);
        $count  = 0;

        $this->assertFalse($config->navGroups['admin']['sidebar']);

        foreach ($config->modules as $module) {
            foreach ($module['menu'] as $item) {
                if (($item['group'] ?? null) !== 'admin') {
                    continue;
                }

                $count++;
                $this->assertArrayHasKey($item['section'] ?? '', $config->adminSections, $item['label'] . ' needs a known section');
                $this->assertNotSame('', trim((string) ($item['summary'] ?? '')), $item['label'] . ' needs a summary');
            }
        }

        $this->assertGreaterThan(0, $count);
    }
}

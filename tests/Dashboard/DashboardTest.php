<?php

declare(strict_types=1);

namespace Tests\Dashboard;

use App\Core\Dashboard\DashboardService;
use App\Core\Dashboard\DashboardTab;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Backoffice;
use Config\Services;
use Tests\Support\Payables\PayablesTestCase;

/**
 * The dashboard is a Today tab plus one tab per enabled module the user can use.
 */
final class DashboardTest extends PayablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private int $admin;
    private int $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();
        Services::resetSingle('modules');
        setting()->set('ModuleState.disabled', []);

        $company     = $this->makeCompany('ACME');
        $this->admin = $this->makeUserNamed('Ada Admin', 'ada@example.test', true);
        $this->plain = $this->makeUserNamed('Pat Plain', 'pat@example.test');
        $this->join($this->admin, $company, true);
        $this->join($this->plain, $company, true);
    }

    private function as(int $userId, string $path)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();
        db_connect()->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        return $this->withSession(['user' => ['id' => $userId]])->get($path);
    }

    public function testEveryDeclaredProviderImplementsTheContract(): void
    {
        foreach (config(Backoffice::class)->dashboardTabs() as $key => $tab) {
            $this->assertTrue(is_subclass_of($tab['provider'], DashboardTab::class), $key . ' provider must implement DashboardTab');
            $this->assertArrayHasKey($tab['permission'], config(Backoffice::class)->allPermissions());
            $this->assertFileExists(APPPATH . 'Views/dashboard/tabs/' . $key . '.php');
        }
    }

    public function testTabsFollowPermissionsAndOrder(): void
    {
        $board = new DashboardService();

        $this->assertSame(['payables', 'receivables', 'users'], array_keys($board->tabsFor($this->admin)));
        $this->assertSame([], $board->tabsFor($this->plain));
    }

    public function testSwitchingAModuleOffRemovesItsTab(): void
    {
        service('modules')->setEnabled('payables', false);

        $this->assertSame(['receivables', 'users'], array_keys((new DashboardService())->tabsFor($this->admin)));
    }

    public function testAnUnknownOrForbiddenTabFallsBackToToday(): void
    {
        $board = new DashboardService();
        $tabs  = $board->tabsFor($this->plain);

        $this->assertSame('today', $board->resolve($tabs, 'payables'));
        $this->assertSame('today', $board->resolve($tabs, 'nonsense'));
        $this->assertSame('today', $board->resolve($tabs, null));
    }

    public function testTheDashboardRendersEveryTabForAnAdmin(): void
    {
        foreach (['/', '/?tab=payables', '/?tab=receivables', '/?tab=users'] as $path) {
            $result = $this->as($this->admin, $path);
            $this->assertTrue($result->isOK(), $path);
            $result->assertSee('Today');
        }
    }

    public function testAUserWithoutAccessOnlySeesToday(): void
    {
        $result = $this->as($this->plain, '/?tab=payables');

        $this->assertTrue($result->isOK());
        $result->assertSee('Unread notifications');
        $result->assertDontSee('Waiting on me');
    }
}

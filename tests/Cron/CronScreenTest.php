<?php

declare(strict_types=1);

namespace Tests\Cron;

use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Payables\PayablesTestCase;

/**
 * The Cron jobs page through the real filter pipeline: administrators only, and what it shows.
 *
 * @internal
 */
final class CronScreenTest extends PayablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private int $super;
    private int $admin;
    private int $manager;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->super   = $this->makeSuperUser('super@example.test');
        $this->admin   = $this->makeUserNamed('Ann Admin', 'ann@example.test');
        $this->manager = $this->makeUserNamed('Mia Manager', 'mia@example.test');
        $this->staff   = $this->makeUserNamed('Sam Staff', 'sam@example.test');

        $this->giveRole($this->admin, 'admin');
        $this->giveRole($this->manager, 'manager');
    }

    private function giveRole(int $userId, string $key): void
    {
        $this->db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => (int) $this->db->table('roles')->where('key', $key)->get()->getRow()->id]);
        service('permissions')->forget($userId);
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

    public function testAdministratorsCanOpenItAndOthersCannot(): void
    {
        $this->as($this->super, 'admin/cron')->assertOK();
        $this->as($this->admin, 'admin/cron')->assertOK();

        $this->assertFalse($this->as($this->manager, 'admin/cron')->isOK(), 'the Manager role does not get it');
        $this->assertFalse($this->as($this->staff, 'admin/cron')->isOK());
    }

    public function testItAppearsOnTheAdminDashboardOnlyForAdministrators(): void
    {
        $link = 'href="' . site_url('admin/cron') . '"';

        $this->assertStringContainsString($link, $this->as($this->admin, 'admin')->getBody());
        $this->assertStringContainsString($link, $this->as($this->super, 'admin')->getBody());
        $this->assertStringNotContainsString($link, $this->as($this->manager, 'admin')->getBody());
    }

    public function testItListsJobsRunsAndTheCrontabLines(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('cron_runs')->insertBatch([
            ['command' => 'backup:run', 'started_at' => $now, 'finished_at' => $now, 'status' => 'failed', 'exit_code' => 1, 'message' => 'Backup failed: disk full'],
            ['command' => 'cron:check', 'started_at' => $now, 'finished_at' => $now, 'status' => 'ok', 'exit_code' => 0, 'message' => null],
        ]);

        $page = $this->as($this->admin, 'admin/cron');

        $page->assertSee('Database backup');
        $page->assertSee('Failed');
        $page->assertSee('Backup failed: disk full');
        $page->assertSee('No runs yet');
        $page->assertSee('php spark backup:run');
        $page->assertSee('php spark cron:check');
        $page->assertDontSee('Cron may not be running');
    }

    public function testTheBannerAppearsWhenTheHourlyCheckStopsReporting(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 6 * 3600);
        $this->db->table('cron_runs')->insert(['command' => 'cron:check', 'started_at' => $old, 'finished_at' => $old, 'status' => 'ok', 'exit_code' => 0]);

        $this->as($this->admin, 'admin/cron')->assertSee('Cron may not be running');
    }

    public function testTheRunFilterOnlyAcceptsKnownCommands(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('cron_runs')->insertBatch([
            ['command' => 'backup:run', 'started_at' => $now, 'finished_at' => $now, 'status' => 'ok', 'exit_code' => 0, 'message' => null],
            ['command' => 'adhoc:task', 'started_at' => $now, 'finished_at' => $now, 'status' => 'ok', 'exit_code' => 0, 'message' => 'only-adhoc-run'],
        ]);

        $this->as($this->admin, 'admin/cron?command=adhoc:task')->assertSee('only-adhoc-run');
        $this->as($this->admin, 'admin/cron?command=backup:run')->assertDontSee('only-adhoc-run');
        $this->as($this->admin, "admin/cron?command='%20OR%201=1")->assertOK();
    }
}

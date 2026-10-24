<?php

declare(strict_types=1);

namespace Tests\Backups;

use App\Core\Backups\DestinationService;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Payables\PayablesTestCase;

/**
 * Backup screens through the real filter pipeline: who may see status, who may change things,
 * and that stored secrets never come back out.
 *
 * @internal
 */
final class BackupScreensTest extends PayablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private int $manager;
    private int $viewer;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->manager = $this->makeUserNamed('Mia Manager', 'mia@example.test');
        $this->viewer  = $this->makeUserNamed('Vic Viewer', 'vic@example.test');
        $this->staff   = $this->makeUserNamed('Sam Staff', 'sam@example.test');

        $this->giveRole($this->manager, 'backups_manager');
        $this->giveRole($this->viewer, 'manager');       // the broad Manager role: status only
    }

    private function giveRole(int $userId, string $key): void
    {
        $this->db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => (int) $this->db->table('roles')->where('key', $key)->get()->getRow()->id]);
        service('permissions')->forget($userId);
    }

    private function as(int $userId, string $method, string $path, ?array $params = null)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();

        $this->db->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        $call = $this->withSession(['user' => ['id' => $userId]]);

        return $method === 'post' ? $call->post($path, ($params ?? []) + [csrf_token() => csrf_hash()]) : $call->get($path);
    }

    private function webdav(): int
    {
        return (new DestinationService())->create('webdav', ['name' => 'Cloud', 'keep_last' => 7, 'enabled' => 1, 's_url' => 'https://cloud.test/dav', 's_username' => 'bob', 's_password' => 'S3CRET-VALUE'], null);
    }

    public function testStatusPageIsOpenToManagersAndViewersButNotStaff(): void
    {
        $this->as($this->manager, 'get', 'admin/backups')->assertOK();
        $this->as($this->viewer, 'get', 'admin/backups')->assertOK();
        $this->assertFalse($this->as($this->staff, 'get', 'admin/backups')->isOK());
    }

    public function testViewerSeesStatusButNoDestinationsOrRunNow(): void
    {
        $this->webdav();

        $viewer  = $this->as($this->viewer, 'get', 'admin/backups');
        $manager = $this->as($this->manager, 'get', 'admin/backups');

        $viewer->assertDontSee('Run now');
        $viewer->assertDontSee('Add destination');
        $manager->assertSee('Run now');
        $manager->assertSee('Cloud');
    }

    public function testViewerCannotChangeAnything(): void
    {
        $id = $this->webdav();

        // If the guard failed, Run now must not start a real process and Test must not reach the network.
        config(\Config\Backups::class)->phpPath = 'no-such-php-binary';

        $this->as($this->viewer, 'post', 'admin/backups/run');
        $this->as($this->viewer, 'post', 'admin/backups/destinations/' . $id . '/test');
        $this->as($this->viewer, 'post', 'admin/backups/destinations/' . $id . '/delete');

        $destination = (new DestinationService())->find($id);
        $this->assertNotNull($destination, 'the destination survived');
        $this->assertNull($destination['last_test_ok'], 'it was not tested');
        $this->assertSame(0, $this->db->table('audit_log')->like('action', 'backups.')->where('action !=', 'backups.destination_added')->countAllResults(), 'nothing was audited as done');
        $this->assertFalse($this->as($this->viewer, 'get', 'admin/backups/destinations/new')->isOK());
    }

    public function testSecretsAreNeverShownAndBlankKeepsTheSavedOne(): void
    {
        $id = $this->webdav();

        $page = $this->as($this->manager, 'get', 'admin/backups/destinations/' . $id . '/edit');
        $page->assertOK();
        $page->assertDontSee('S3CRET-VALUE');
        $page->assertSee('Saved. Leave blank to keep it.');

        $stored = (string) $this->db->table('bk_destinations')->where('id', $id)->get()->getRow()->settings;
        $this->assertStringNotContainsString('S3CRET-VALUE', $stored, 'encrypted at rest');

        $this->as($this->manager, 'post', 'admin/backups/destinations/' . $id, ['name' => 'Cloud 2', 'keep_last' => '3', 'enabled' => '1', 's_url' => 'https://cloud.test/dav', 's_username' => 'bob', 's_password' => '']);

        $row      = (new DestinationService())->find($id);
        $settings = (new DestinationService())->settings($row);
        $this->assertSame('Cloud 2', $row['name']);
        $this->assertSame('S3CRET-VALUE', $settings['password']);
    }

    public function testInvalidDestinationIsRejected(): void
    {
        $this->as($this->manager, 'post', 'admin/backups/destinations', ['type' => 'webdav', 'name' => 'Bad', 'keep_last' => '5', 's_url' => 'ftp://nope', 's_username' => 'u', 's_password' => 'p']);

        $this->assertSame(0, $this->db->table('bk_destinations')->countAllResults());
    }

    public function testRunDetailPageShowsDestinationOutcome(): void
    {
        $dest = $this->webdav();
        $this->db->table('bk_runs')->insert(['trigger_type' => 'cron', 'status' => 'partial', 'started_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s'), 'file_name' => 'itm-backup-20261023-020000.sql.gz.enc', 'size_bytes' => 2048, 'encrypted' => 1]);
        $run = (int) $this->db->insertID();
        $this->db->table('bk_run_destinations')->insert(['run_id' => $run, 'destination_id' => $dest, 'status' => 'failed', 'error' => 'Upload was refused: check the user name and password.']);

        $this->as($this->viewer, 'get', 'admin/backups/runs/' . $run)->assertSee('check the user name and password');
        $this->as($this->viewer, 'get', 'admin/backups')->assertSee('itm-backup-20261023-020000.sql.gz.enc');
    }
}

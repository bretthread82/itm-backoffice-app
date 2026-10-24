<?php

declare(strict_types=1);

namespace Tests\Backups;

use App\Core\Backups\BackupEncryptor;
use App\Core\Backups\BackupService;
use App\Core\Backups\BackupStatus;
use App\Core\Backups\DatabaseDumper;
use App\Core\Backups\DestinationFactory;
use App\Core\Backups\DestinationService;
use Config\Backups as BackupsConfig;
use RuntimeException;
use Tests\Support\Backups\FakeTransport;
use Tests\Support\Payables\PayablesTestCase;

/**
 * @internal
 */
final class BackupServiceTest extends PayablesTestCase
{
    public const DUMP = "-- fake dump\nCREATE TABLE t (id int);\n-- Dump completed\n";

    private string $dir;
    private BackupsConfig $config;
    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bksvc' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $this->config                    = new BackupsConfig();
        $this->config->workDir           = $this->dir . '/work';
        $this->config->passphrase        = 'a-long-test-passphrase';
        $this->config->kdfIterations     = 1000;
        $this->config->localKeep         = 2;

        $this->manager = $this->makeUserNamed('Mia Manager', 'mia@example.test');
        $this->db->table('user_roles')->insert(['user_id' => $this->manager, 'role_id' => (int) $this->db->table('roles')->where('key', 'backups_manager')->get()->getRow()->id]);
        service('permissions')->forget($this->manager);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $name) {
            $f = $d . DIRECTORY_SEPARATOR . $name;
            is_dir($f) ? $this->rrmdir($f) : unlink($f);
        }

        rmdir($d);
    }

    private function dumper(bool $fail = false): DatabaseDumper
    {
        return new class ($fail) extends DatabaseDumper {
            public function __construct(private readonly bool $fail)
            {
            }

            public function dump(string $targetGz): void
            {
                if ($this->fail) {
                    throw new RuntimeException('mysqldump failed (exit 2): Access denied');
                }

                file_put_contents($targetGz, gzencode(BackupServiceTest::DUMP));
            }
        };
    }

    private function localDestination(string $name, string $folder, int $keep = 14, bool $enabled = true): int
    {
        return (new DestinationService())->create('local', ['name' => $name, 'keep_last' => $keep, 'enabled' => $enabled, 's_path' => $this->dir . '/' . $folder], null);
    }

    private function service(?FakeTransport $http = null, bool $failDump = false): BackupService
    {
        return new BackupService($this->dumper($failDump), new BackupEncryptor(1000), new DestinationService(null, new DestinationFactory($http)), $this->config);
    }

    public function testSuccessfulRunIsEncryptedSentAndRecorded(): void
    {
        $dest = $this->localDestination('Disk', 'remote');

        $run = $this->service()->run('cron');

        $this->assertSame('success', $run['status']);
        $this->assertSame(1, (int) $run['encrypted']);
        $this->assertNotEmpty($run['sha256']);

        $sent = $this->dir . '/remote/' . $run['file_name'];
        $this->assertFileExists($sent);
        $this->assertSame(hash_file('sha256', $sent), $run['sha256']);
        $this->assertFileDoesNotExist($this->config->dir() . '/' . basename($run['file_name'], '.enc'), 'the unencrypted dump is removed');
        $this->seeInDatabase('bk_run_destinations', ['run_id' => $run['id'], 'destination_id' => $dest, 'status' => 'ok']);

        // What was sent can be restored with the passphrase.
        (new BackupEncryptor())->decryptFile($sent, $this->dir . '/restored.gz', $this->config->passphrase);
        $this->assertSame(self::DUMP, gzdecode((string) file_get_contents($this->dir . '/restored.gz')));
    }

    public function testOneFailingDestinationMakesTheRunPartialAndNotifiesManagers(): void
    {
        $this->localDestination('Disk', 'remote');
        (new DestinationService())->create('webdav', ['name' => 'Cloud', 'keep_last' => 5, 'enabled' => 1, 's_url' => 'https://cloud.test/dav', 's_username' => 'u', 's_password' => 'p', 's_folder' => 'f'], null);

        $run = $this->service((new FakeTransport())->on('MKCOL', '', 401))->run('cron');

        $this->assertSame('partial', $run['status']);
        $this->assertSame(1, $this->db->table('bk_run_destinations')->where(['run_id' => $run['id'], 'status' => 'ok'])->countAllResults());
        $failed = $this->db->table('bk_run_destinations')->where(['run_id' => $run['id'], 'status' => 'failed'])->get()->getRowArray();
        $this->assertStringContainsString('user name and password', $failed['error']);
        $this->assertSame(1, $this->bell($this->manager, 'backups.failed'));
    }

    public function testAllDestinationsFailingIsFailed(): void
    {
        (new DestinationService())->create('webdav', ['name' => 'Cloud', 'keep_last' => 5, 'enabled' => 1, 's_url' => 'https://cloud.test/dav', 's_username' => 'u', 's_password' => 'p'], null);

        $run = $this->service((new FakeTransport())->on('PUT', '', 500))->run('cron');

        $this->assertSame('failed', $run['status']);
    }

    public function testDumpFailureIsRecordedAndLeavesNoFiles(): void
    {
        $this->localDestination('Disk', 'remote');

        $run = $this->service(null, true)->run('manual', $this->manager);

        $this->assertSame('failed', $run['status']);
        $this->assertStringContainsString('Access denied', $run['error']);
        $this->assertSame($this->manager, (int) $run['triggered_by']);
        $this->assertSame([], glob($this->config->dir() . '/itm-backup-*') ?: []);
        $this->assertSame(0, $this->db->table('bk_run_destinations')->where('run_id', $run['id'])->countAllResults());
        $this->assertSame(1, $this->bell($this->manager, 'backups.failed'));
    }

    public function testMissingPassphraseRefusesToBackUpUnencrypted(): void
    {
        $this->config->passphrase = '';
        $this->localDestination('Disk', 'remote');

        $run = $this->service()->run('cron');

        $this->assertSame('failed', $run['status']);
        $this->assertStringContainsString('passphrase', $run['error']);
        $this->assertFalse(is_dir($this->dir . '/remote') && glob($this->dir . '/remote/*'));
    }

    public function testUnencryptedBackupAllowedOnlyWhenSwitchedOff(): void
    {
        $this->config->passphrase        = '';
        $this->config->requireEncryption = false;

        $run = $this->service()->run('cron');

        $this->assertSame('success', $run['status']);
        $this->assertSame(0, (int) $run['encrypted']);
        $this->assertStringEndsWith('.sql.gz', $run['file_name']);
    }

    public function testRetentionKeepsNewestAndIgnoresOtherFiles(): void
    {
        $this->localDestination('Disk', 'remote', 2);
        mkdir($this->dir . '/remote');

        foreach (['20250101-000000', '20250102-000000', '20250103-000000'] as $d) {
            file_put_contents($this->dir . '/remote/itm-backup-' . $d . '.sql.gz.enc', 'old');
        }

        file_put_contents($this->dir . '/remote/holiday-photos.zip', 'not ours');

        $run = $this->service()->run('cron');

        $left     = array_values(array_diff(scandir($this->dir . '/remote') ?: [], ['.', '..']));
        $expected = ['holiday-photos.zip', $run['file_name'], 'itm-backup-20250103-000000.sql.gz.enc'];
        sort($left);
        sort($expected);

        $this->assertSame($expected, $left);
    }

    public function testLocalCopiesArePrunedToLocalKeep(): void
    {
        $svc = $this->service();

        mkdir($this->config->dir(), 0750, true);

        foreach ([1, 2, 3] as $i) {
            file_put_contents($this->config->dir() . '/itm-backup-2025010' . $i . '-000000.sql.gz.enc', 'x');
        }

        $svc->run('cron');

        $this->assertCount(2, glob($this->config->dir() . '/itm-backup-*') ?: []);
    }

    public function testDisabledDestinationIsSkippedAndNoDestinationStillSucceedsLocally(): void
    {
        $this->localDestination('Off', 'remote', 14, false);

        $run = $this->service()->run('cron');

        $this->assertSame('success', $run['status']);
        $this->assertSame(0, $this->db->table('bk_run_destinations')->where('run_id', $run['id'])->countAllResults());
    }

    public function testOldRunningRowIsMarkedInterrupted(): void
    {
        $this->db->table('bk_runs')->insert(['trigger_type' => 'cron', 'status' => 'running', 'started_at' => date('Y-m-d H:i:s', time() - 7200)]);
        $stale = (int) $this->db->insertID();

        $this->service()->run('cron');

        $row = $this->db->table('bk_runs')->where('id', $stale)->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('interrupted', $row['error']);
    }

    public function testStatusFlagsStaleBackupsAndNotifiesOncePerDay(): void
    {
        $status = new BackupStatus($this->config);
        $this->assertTrue($status->isStale(), 'no backup yet');

        $this->db->table('bk_runs')->insert(['trigger_type' => 'cron', 'status' => 'success', 'started_at' => date('Y-m-d H:i:s', time() - 100), 'finished_at' => date('Y-m-d H:i:s', time() - 90)]);
        $this->assertFalse($status->isStale());

        $this->db->table('bk_runs')->emptyTable();
        $this->db->table('bk_runs')->insert(['trigger_type' => 'cron', 'status' => 'success', 'started_at' => date('Y-m-d H:i:s', time() - 3600 * 40), 'finished_at' => date('Y-m-d H:i:s', time() - 3600 * 40)]);
        $this->assertTrue($status->isStale());

        cache()->delete('backups_stale_notified');
        $this->assertTrue($status->notifyIfStale());
        $this->assertFalse($status->notifyIfStale(), 'only once a day');
        $this->assertSame(1, $this->bell($this->manager, 'backups.stale'));
    }
}

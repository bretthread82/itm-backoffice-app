<?php

declare(strict_types=1);

namespace Tests\Cron;

use App\Commands\MonitoredCommand;
use App\Core\Cron\CronMonitor;
use CodeIgniter\CLI\CLI;
use Config\Services;
use RuntimeException;
use Tests\Support\Payables\PayablesTestCase;

/**
 * Cron monitoring: how a job's state is worked out from what it reported, the command wrapper that reports it,
 * and the daily alert. Jobs are declared in the module registry (backup:run every 1440 min, cron:check every 60).
 *
 * @internal
 */
final class CronMonitorTest extends PayablesTestCase
{
    private const NOW = 1_800_000_000;

    private CronMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('modules');
        setting()->set('ModuleState.disabled', []);
        $this->monitor = new CronMonitor();
    }

    protected function tearDown(): void
    {
        setting()->set('ModuleState.disabled', []);
        Services::resetSingle('modules');
        parent::tearDown();
    }

    private function recordRun(string $command, int $minutesAgo, string $status = 'ok', ?string $message = null): void
    {
        $started = gmdate('Y-m-d H:i:s', self::NOW - $minutesAgo * 60);
        $this->db->table('cron_runs')->insert([
            'command'     => $command,
            'started_at'  => $started,
            'finished_at' => $status === 'running' ? null : gmdate('Y-m-d H:i:s', self::NOW - $minutesAgo * 60 + 30),
            'status'      => $status,
            'exit_code'   => $status === 'failed' ? 1 : ($status === 'ok' ? 0 : null),
            'message'     => $message,
        ]);
    }

    /** @return array<string, mixed> */
    private function job(string $command): array
    {
        foreach ($this->monitor->jobs(self::NOW) as $job) {
            if ($job['command'] === $command) {
                return $job;
            }
        }

        $this->fail($command . ' is not a declared job');
    }

    public function testEveryDeclaredJobIsListed(): void
    {
        $commands = array_column($this->monitor->jobs(self::NOW), 'command');

        foreach (['entra:sync', 'receivables:remind', 'backup:run', 'backup:check', 'cron:check'] as $expected) {
            $this->assertContains($expected, $commands);
        }
    }

    public function testAJobThatNeverRanIsNoneAndNotAlertedOn(): void
    {
        $this->assertSame('none', $this->job('backup:run')['state']);
        $this->assertSame([], $this->monitor->needingAttention(self::NOW));
    }

    public function testAJobThatRanOnTimeIsOk(): void
    {
        $this->recordRun('backup:run', 600);

        $this->assertSame('ok', $this->job('backup:run')['state']);
    }

    public function testAJobIsLateOnlyAfterItsScheduleAndGrace(): void
    {
        // Daily job: 1440 min + 25% grace (360 min) = 1800 min.
        $this->recordRun('backup:run', 1799);
        $this->assertSame('ok', $this->job('backup:run')['state']);

        $this->db->table('cron_runs')->emptyTable();
        $this->recordRun('backup:run', 1801);
        $this->assertSame('late', $this->job('backup:run')['state']);
    }

    public function testShortJobsGetAtLeastFifteenMinutesGrace(): void
    {
        // Hourly job: 25% would be 15 min, so due by 75 min.
        $this->recordRun('cron:check', 74);
        $this->assertSame('ok', $this->job('cron:check')['state']);

        $this->db->table('cron_runs')->emptyTable();
        $this->recordRun('cron:check', 76);
        $this->assertSame('late', $this->job('cron:check')['state']);
    }

    public function testTheLatestRunDecidesFailure(): void
    {
        $this->recordRun('backup:run', 600, 'ok');
        $this->recordRun('backup:run', 30, 'failed', 'Backup failed: mysqldump exit 2');

        $job = $this->job('backup:run');
        $this->assertSame('failed', $job['state']);
        $this->assertStringContainsString('mysqldump', $job['last']['message']);

        $this->recordRun('backup:run', 5, 'ok');
        $this->assertSame('ok', $this->job('backup:run')['state'], 'a later good run clears it');
    }

    public function testRunningThenStuck(): void
    {
        $this->recordRun('backup:run', 30, 'running');
        $this->assertSame('running', $this->job('backup:run')['state']);

        $this->db->table('cron_runs')->emptyTable();
        $this->recordRun('backup:run', 200, 'running');
        $this->assertSame('stuck', $this->job('backup:run')['state'], 'running for over 120 minutes');
    }

    public function testASwitchedOffModulesJobsAreLeftOut(): void
    {
        $this->recordRun('receivables:remind', 5000);
        $this->assertSame('late', $this->job('receivables:remind')['state']);

        service('modules')->setEnabled('receivables', false);
        Services::resetSingle('modules');

        $this->assertNotContains('receivables:remind', array_column($this->monitor->jobs(self::NOW), 'command'));
        $this->assertNotContains('receivables:remind', array_column($this->monitor->needingAttention(self::NOW), 'command'));
    }

    public function testCronLooksStoppedWhenTheHourlyCheckGoesMissing(): void
    {
        $this->assertFalse($this->monitor->cronLooksStopped(self::NOW), 'nothing recorded yet is not an alarm');

        $this->recordRun('cron:check', 30);
        $this->assertFalse($this->monitor->cronLooksStopped(self::NOW));

        $this->db->table('cron_runs')->emptyTable();
        $this->recordRun('cron:check', 300);
        $this->assertTrue($this->monitor->cronLooksStopped(self::NOW));
    }

    public function testPruneRemovesOnlyRowsOlderThanNinetyDays(): void
    {
        $this->recordRun('backup:run', 91 * 1440);
        $this->recordRun('backup:run', 89 * 1440);

        $this->assertSame(1, $this->monitor->prune(self::NOW));
        $this->assertSame(1, $this->db->table('cron_runs')->countAllResults());
    }

    public function testAlertsGoToAdministratorsOncePerJobAndState(): void
    {
        $admin = $this->makeUserNamed('Ann Admin', 'ann@example.test');
        $staff = $this->makeUserNamed('Sam Staff', 'sam@example.test');
        $this->db->table('user_roles')->insert(['user_id' => $admin, 'role_id' => (int) $this->db->table('roles')->where('key', 'admin')->get()->getRow()->id]);
        service('permissions')->forget($admin);
        cache()->delete('cron_attention_' . md5('backup:run|failed'));

        $this->recordRun('backup:run', 30, 'failed', 'Backup failed: disk full');

        $this->assertSame(1, $this->monitor->notifyAttention(self::NOW));
        $this->assertSame(0, $this->monitor->notifyAttention(self::NOW), 'only once a day');
        $this->assertSame(1, $this->bell($admin, 'cron.attention'));
        $this->assertSame(0, $this->bell($staff, 'cron.attention'));
    }

    // ---- MonitoredCommand -----------------------------------------------------------------------------------------

    private function command(\Closure $body, string $name = 'test:job'): MonitoredCommand
    {
        $cmd = new class (service('logger'), service('commands'), $body, $name) extends MonitoredCommand {
            public function __construct($logger, $commands, private readonly \Closure $body, string $name)
            {
                parent::__construct($logger, $commands);
                $this->name = $name;
            }

            protected function handle(array $params)
            {
                return ($this->body)($this);
            }

            public function noteIt(string $m): void
            {
                $this->note($m);
            }
        };

        return $cmd;
    }

    public function testASuccessfulRunIsRecorded(): void
    {
        $this->assertSame(EXIT_SUCCESS, $this->command(static fn () => EXIT_SUCCESS)->run([]));

        $row = $this->db->table('cron_runs')->where('command', 'test:job')->get()->getRowArray();
        $this->assertSame('ok', $row['status']);
        $this->assertNotNull($row['finished_at']);
    }

    public function testANonZeroExitIsFailedAndKeepsTheNote(): void
    {
        $cmd = $this->command(static function ($c) {
            $c->noteIt('Another run was already in progress.');

            return EXIT_ERROR;
        });

        $this->assertSame(EXIT_ERROR, $cmd->run([]), 'the exit code is passed through unchanged');

        $row = $this->db->table('cron_runs')->where('command', 'test:job')->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Another run was already in progress.', $row['message']);
    }

    public function testAnExceptionIsRecordedAndRethrown(): void
    {
        $cmd = $this->command(static function () {
            throw new RuntimeException('boom');
        });

        try {
            $cmd->run([]);
            $this->fail('the exception should propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $row = $this->db->table('cron_runs')->where('command', 'test:job')->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('boom', $row['message']);
    }

    public function testADryRunIsNotRecorded(): void
    {
        $this->command(static fn () => EXIT_SUCCESS)->run(['dry-run' => null]);

        $this->assertSame(0, $this->db->table('cron_runs')->countAllResults());
    }

    public function testAMonitoringFailureIsSwallowed(): void
    {
        // A connection that cannot connect: recording must neither throw nor pretend a run was stored.
        $broken  = \Config\Database::connect(['DBDriver' => 'MySQLi', 'hostname' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'x', 'database' => 'x', 'DBDebug' => true], false);
        $monitor = new CronMonitor($broken);

        $this->assertNull($monitor->start('test:job'));
        $monitor->finish(7, 0, 'ignored');
        $monitor->finish(null, 1);

        $this->assertSame(0, $this->db->table('cron_runs')->countAllResults());
    }
}

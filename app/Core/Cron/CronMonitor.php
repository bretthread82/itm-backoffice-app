<?php

declare(strict_types=1);

namespace App\Core\Cron;

use CodeIgniter\Database\BaseConnection;
use Config\Backoffice;
use Throwable;

/**
 * What the scheduled commands report about themselves, checked against the schedule each module declares in its
 * registry 'cron' entry. The app cannot read the server's crontab, so "late" means "has not reported in on time".
 * Times are stored in UTC.
 */
class CronMonitor
{
    public const KEEP_DAYS = 90;

    /** Job states, in the order they are worth looking at. */
    public const ATTENTION = ['failed', 'late', 'stuck'];

    public function __construct(private readonly ?BaseConnection $recordDb = null)
    {
    }

    // ---- Recording (called by MonitoredCommand; must never break a job) ----------------------------------------

    public function start(string $command): ?int
    {
        try {
            $db = $this->recordDb ?? db_connect();
            $db->table('cron_runs')->insert(['command' => $command, 'started_at' => gmdate('Y-m-d H:i:s'), 'status' => 'running']);

            return (int) $db->insertID();
        } catch (Throwable $e) {
            log_message('error', 'Cron monitor could not record the start of ' . $command . ': ' . $e->getMessage());

            return null;
        }
    }

    public function finish(?int $runId, int $exitCode, ?string $message = null): void
    {
        if ($runId === null) {
            return;
        }

        try {
            ($this->recordDb ?? db_connect())->table('cron_runs')->where('id', $runId)->update([
                'finished_at' => gmdate('Y-m-d H:i:s'),
                'status'      => $exitCode === 0 ? 'ok' : 'failed',
                'exit_code'   => $exitCode,
                'message'     => $message !== null ? mb_strimwidth(trim($message), 0, 495, '…') : ($exitCode === 0 ? null : 'Exited with code ' . $exitCode . '.'),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Cron monitor could not record the end of run ' . $runId . ': ' . $e->getMessage());
        }
    }

    // ---- Reading ------------------------------------------------------------------------------------------------

    /**
     * Every declared job whose module is switched on, with its latest run and a computed state:
     * ok, running, failed, late, stuck, or none (no run recorded yet; never alerted, so a fresh deploy stays quiet).
     *
     * @return list<array<string, mixed>>
     */
    public function jobs(?int $now = null): array
    {
        $now ??= time();
        $db   = db_connect();
        $jobs = [];

        foreach (config(Backoffice::class)->modules as $key => $module) {
            if (empty($module['cron']) || ! service('modules')->enabled((string) $key)) {
                continue;
            }

            foreach ($module['cron'] as $job) {
                $every = (int) $job['every'];
                $grace = (int) ($job['grace'] ?? max(15, (int) ceil($every * 0.25)));
                $max   = (int) ($job['maxMinutes'] ?? 120);
                $last  = $db->table('cron_runs')->where('command', $job['command'])->orderBy('id', 'DESC')->get(1)->getRowArray();

                $state = 'none';
                $due   = null;

                if ($last !== null) {
                    $started = (int) strtotime($last['started_at'] . ' UTC');
                    $due     = $started + ($every + $grace) * 60;
                    $state   = match (true) {
                        $last['status'] === 'running' => $now - $started > $max * 60 ? 'stuck' : 'running',
                        $last['status'] === 'failed'  => 'failed',
                        $now > $due                   => 'late',
                        default                       => 'ok',
                    };
                }

                $jobs[] = $job + [
                    'module'  => (string) $key,
                    'every'   => $every,
                    'grace'   => $grace,
                    'last'    => $last,
                    'state'   => $state,
                    'dueBy'   => $due !== null ? gmdate('Y-m-d H:i:s', $due) : null,
                    'cadence' => self::cadence($every),
                ];
            }
        }

        return $jobs;
    }

    /**
     * Jobs that need someone to look at them.
     *
     * @return list<array<string, mixed>>
     */
    public function needingAttention(?int $now = null): array
    {
        return array_values(array_filter($this->jobs($now), static fn (array $j) => in_array($j['state'], self::ATTENTION, true)));
    }

    /**
     * True when the hourly cron:check has stopped reporting: the best sign available that cron itself is not running
     * (or that the cron:check line was never added).
     */
    public function cronLooksStopped(?int $now = null): bool
    {
        foreach ($this->jobs($now) as $job) {
            if ($job['command'] === 'cron:check') {
                return $job['state'] === 'late';
            }
        }

        return false;
    }

    /**
     * @return list<string>  every command that has reported, for the history filter
     */
    public function knownCommands(): array
    {
        return array_column(db_connect()->query('SELECT DISTINCT command FROM cron_runs ORDER BY command')->getResultArray(), 'command');
    }

    // ---- Housekeeping and alerts --------------------------------------------------------------------------------

    public function prune(?int $now = null): int
    {
        $db = db_connect();
        $db->table('cron_runs')->where('started_at <', gmdate('Y-m-d H:i:s', ($now ?? time()) - self::KEEP_DAYS * 86400))->delete();

        return $db->affectedRows();
    }

    /**
     * Tells administrators about each job needing attention, at most once a day per job and state.
     *
     * @return int how many alerts were sent
     */
    public function notifyAttention(?int $now = null): int
    {
        $sent = 0;

        foreach ($this->needingAttention($now) as $job) {
            $key = 'cron_attention_' . md5($job['command'] . '|' . $job['state']);

            if (cache($key)) {
                continue;
            }

            cache()->save($key, 1, 86400);

            $why = match ($job['state']) {
                'failed' => 'The last run failed' . ($job['last']['message'] ? ': ' . $job['last']['message'] : '.'),
                'stuck'  => 'It started but never finished. The process may have been killed.',
                default  => 'It has not run on time. Expected ' . strtolower($job['cadence']) . '.',
            };

            service('notifier')->sendToPermission('cron.view', 'cron.attention', $job['label'] . ': ' . ($job['state'] === 'late' ? 'running late' : $job['state']), $why, 'admin/cron');
            $sent++;
        }

        return $sent;
    }

    public static function cadence(int $minutes): string
    {
        return match (true) {
            $minutes === 60                         => 'Every hour',
            $minutes === 1440                       => 'Every day',
            $minutes % 1440 === 0                   => 'Every ' . ($minutes / 1440) . ' days',
            $minutes % 60 === 0                     => 'Every ' . ($minutes / 60) . ' hours',
            default                                 => 'Every ' . $minutes . ' minutes',
        };
    }
}

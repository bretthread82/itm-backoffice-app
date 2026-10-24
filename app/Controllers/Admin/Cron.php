<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Cron\CronMonitor;

/**
 * Status of the scheduled (cron) jobs, for administrators. The jobs report themselves (see MonitoredCommand);
 * what is expected of each comes from the module registry.
 */
class Cron extends BaseController
{
    private const PER_PAGE = 20;

    public function index(): string
    {
        $monitor = new CronMonitor();
        $jobs    = $monitor->jobs();
        $known   = $monitor->knownCommands();
        $command = trim((string) $this->request->getGet('command'));
        $command = in_array($command, $known, true) ? $command : '';
        $page    = max(1, (int) $this->request->getGet('page'));
        $db      = db_connect();

        $build = static function () use ($db, $command) {
            $b = $db->table('cron_runs');

            if ($command !== '') {
                $b->where('command', $command);
            }

            return $b;
        };

        $total = $build()->countAllResults();
        $runs  = $build()->orderBy('id', 'DESC')->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        $attention = array_filter($jobs, static fn (array $j) => in_array($j['state'], CronMonitor::ATTENTION, true));
        $check     = array_values(array_filter($jobs, static fn (array $j) => $j['command'] === 'cron:check'))[0] ?? null;

        return view('admin/cron/index', [
            'title'     => 'Cron jobs',
            'subtitle'  => 'Whether scheduled jobs are running on time',
            'jobs'      => $jobs,
            'attention' => count($attention),
            'lastCheck' => $check['last']['started_at'] ?? null,
            'stopped'   => $monitor->cronLooksStopped(),
            'runs'      => $runs,
            'commands'  => $known,
            'command'   => $command,
            'keepDays'  => CronMonitor::KEEP_DAYS,
            'root'      => rtrim(ROOTPATH, '/\\'),
            'pager'     => service('pager')->makeLinks($page, self::PER_PAGE, $total, 'bootstrap'),
        ]);
    }
}

<?php

namespace App\Commands;

use App\Core\Cron\CronMonitor;
use CodeIgniter\CLI\CLI;

class CronCheck extends MonitoredCommand
{
    protected $group       = 'App';
    protected $name        = 'cron:check';
    protected $description = 'Warns administrators when a scheduled job failed or is late, and prunes old run history.';
    protected $usage       = 'cron:check';

    protected function handle(array $params)
    {
        $monitor = new CronMonitor();
        $pruned  = $monitor->prune();
        $sent    = $monitor->notifyAttention();
        $needing = count($monitor->needingAttention());

        if ($pruned > 0) {
            CLI::write('Removed ' . $pruned . ' old run record(s).');
        }

        if ($needing === 0) {
            CLI::write('All scheduled jobs are on time.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::write($needing . ' job(s) need attention; ' . $sent . ' new alert(s) sent.', 'yellow');

        return EXIT_SUCCESS;
    }
}

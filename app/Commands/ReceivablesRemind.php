<?php

namespace App\Commands;

use App\Core\Receivables\OverdueReminder;
use CodeIgniter\CLI\CLI;

class ReceivablesRemind extends MonitoredCommand
{
    protected $group       = 'App';
    protected $name        = 'receivables:remind';
    protected $description = 'Reminds the raiser and collectors about overdue customer invoices (safe to run more than once a day).';
    protected $usage       = 'receivables:remind [--dry-run]';
    protected $options     = ['--dry-run' => 'Show what would be sent without sending or recording anything.'];

    protected function handle(array $params)
    {
        if (! service('modules')->enabled('receivables')) {
            // Exit 0 so a cron entry is harmless while the module is switched off.
            CLI::write('The Receivables module is switched off. Nothing to do.', 'yellow');

            return EXIT_SUCCESS;
        }

        // Cron concurrency guard.
        $lock = fopen(WRITEPATH . 'cache/receivables-remind.lock', 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            CLI::error('Another reminder run is already in progress.');
            $this->note('Another reminder run was already in progress.');

            return EXIT_ERROR;
        }

        $dry = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;

        try {
            $stats = (new OverdueReminder())->run($dry);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        CLI::write(sprintf(
            '%s%d overdue invoice(s): %d reminded (%d people), %d already reminded recently.',
            $dry ? '[dry run] ' : '',
            $stats['overdue'],
            $stats['reminded'],
            $stats['recipients'],
            $stats['skipped'],
        ), 'green');

        return EXIT_SUCCESS;
    }
}

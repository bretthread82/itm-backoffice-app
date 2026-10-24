<?php

namespace App\Commands;

use App\Core\Receivables\SubscriptionBilling;
use CodeIgniter\CLI\CLI;

class ReceivablesSubscriptions extends MonitoredCommand
{
    protected $group       = 'App';
    protected $name        = 'receivables:subscriptions';
    protected $description = 'Raises draft customer invoices for subscriptions that are due and tells owners and collectors what is coming up (safe to run more than once a day).';
    protected $usage       = 'receivables:subscriptions [--dry-run]';
    protected $options     = ['--dry-run' => 'Show what would be raised and sent without creating or recording anything.'];

    protected function handle(array $params)
    {
        if (! service('modules')->enabled('receivables')) {
            // Exit 0 so a cron entry is harmless while the module is switched off.
            CLI::write('The Receivables module is switched off. Nothing to do.', 'yellow');

            return EXIT_SUCCESS;
        }

        // Cron concurrency guard.
        $lock = fopen(WRITEPATH . 'cache/receivables-subscriptions.lock', 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            CLI::error('Another subscription run is already in progress.');
            $this->note('Another subscription run was already in progress.');

            return EXIT_ERROR;
        }

        $dry = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;

        try {
            $billing = new SubscriptionBilling();
            $billed  = $billing->bill($dry);
            $notices = $billing->remind($dry);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        CLI::write(sprintf(
            '%s%d subscription cycle(s) due: %d billed, %d failed, %d ended. %d coming up: %d notified (%d people), %d already notified.',
            $dry ? '[dry run] ' : '',
            $billed['due'],
            $billed['billed'],
            $billed['failed'],
            $billed['ended'],
            $notices['upcoming'],
            $notices['notified'],
            $notices['recipients'],
            $notices['skipped'],
        ), $billed['failed'] > 0 ? 'red' : 'green');

        if ($billed['failed'] > 0) {
            $this->note($billed['failed'] . ' subscription cycle(s) could not be billed; owners were told.');
        }

        return EXIT_SUCCESS;
    }
}

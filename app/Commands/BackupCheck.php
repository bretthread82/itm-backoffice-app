<?php

namespace App\Commands;

use App\Core\Backups\BackupStatus;
use CodeIgniter\CLI\CLI;

class BackupCheck extends MonitoredCommand
{
    protected $group       = 'App';
    protected $name        = 'backup:check';
    protected $description = 'Warns the backup managers when there has been no successful backup within the allowed time.';
    protected $usage       = 'backup:check';

    protected function handle(array $params)
    {
        $status = new BackupStatus();

        if (! $status->isStale()) {
            CLI::write('Backups are up to date.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::error('No successful backup within the allowed time.');
        $this->note('No successful backup within the allowed time.');
        $status->notifyIfStale();

        return EXIT_ERROR;
    }
}

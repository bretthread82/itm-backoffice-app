<?php

namespace App\Commands;

use App\Core\Entra\EntraSyncService;
use CodeIgniter\CLI\CLI;
use Config\Entra;

class EntraSync extends MonitoredCommand
{
    protected $group       = 'App';
    protected $name        = 'entra:sync';
    protected $description = 'Synchronises users, departments, managers and group roles from Microsoft Entra.';
    protected $usage       = 'entra:sync';

    protected function handle(array $params)
    {
        $config = config(Entra::class);

        if (! $config->isReady()) {
            // Exit 0 so a cron entry is harmless while Entra is switched off.
            CLI::write('Microsoft Entra is not enabled/configured. Nothing to do.', 'yellow');

            return EXIT_SUCCESS;
        }

        // Cron concurrency guard.
        $lock = fopen(WRITEPATH . 'cache/entra-sync.lock', 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            CLI::error('Another sync is already running.');
            $this->note('Another sync was already running.');

            return EXIT_ERROR;
        }

        set_time_limit(0);

        try {
            $stats = (new EntraSyncService())->run();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        CLI::write(sprintf(
            'Created %d, updated %d, reactivated %d, deactivated %d.',
            $stats['created'],
            $stats['updated'],
            $stats['reactivated'],
            $stats['deactivated'],
        ), 'green');

        foreach ($stats['errors'] as $error) {
            CLI::error($error);
        }

        if ($stats['errors'] !== []) {
            $this->note(count($stats['errors']) . ' issue(s): ' . $stats['errors'][0]);
        }

        return $stats['errors'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}

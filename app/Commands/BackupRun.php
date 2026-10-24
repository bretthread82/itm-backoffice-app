<?php

namespace App\Commands;

use App\Core\Backups\BackupLauncher;
use App\Core\Backups\BackupService;
use App\Core\Backups\DestinationService;
use CodeIgniter\CLI\CLI;
use Config\Backups;

class BackupRun extends MonitoredCommand
{
    protected $group       = 'App';
    protected $name        = 'backup:run';
    protected $description = 'Dumps the database, encrypts it and sends it to every enabled backup destination.';
    protected $usage       = 'backup:run [options]';
    protected $options     = [
        '--destination' => 'Only send to this destination id.',
        '--dry-run'     => 'Check the setup and list the destinations without making a backup.',
        '--trigger'     => 'Who started it: cron (default) or manual.',
        '--user'        => 'User id to record for a manual run.',
    ];

    protected function handle(array $params)
    {
        helper('number');

        $config  = config(Backups::class);
        $only   = $params['destination'] ?? CLI::getOption('destination');
        $only    = $only !== null && $only !== true ? (int) $only : null;
        $dryRun  = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $trigger = ($params['trigger'] ?? CLI::getOption('trigger')) === 'manual' ? 'manual' : 'cron';
        $user    = $params['user'] ?? CLI::getOption('user');
        $user    = $user !== null && $user !== true ? (int) $user : null;

        if ($dryRun) {
            return $this->dryRun($config, $only);
        }

        // Cron concurrency guard, shared with the Run now button.
        $lock = fopen(WRITEPATH . BackupLauncher::LOCK, 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            CLI::error('Another backup is already running.');
            $this->note('Another backup was already running.');

            return EXIT_ERROR;
        }

        set_time_limit(0);

        try {
            $run = (new BackupService())->run($trigger, $user, $only);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $rows = db_connect()->table('bk_run_destinations d')->select('d.*, x.name')->join('bk_destinations x', 'x.id = d.destination_id')->where('d.run_id', $run['id'])->get()->getResultArray();

        foreach ($rows as $r) {
            if ($r['status'] === 'ok') {
                CLI::write('  ' . $r['name'] . ': sent', 'green');
            } else {
                CLI::error('  ' . $r['name'] . ': ' . ($r['error'] ?? 'failed'));
            }
        }

        if ($run['status'] === 'failed' || $run['status'] === 'partial') {
            CLI::error('Backup ' . $run['status'] . ($run['error'] ? ': ' . $run['error'] : '.'));
            $this->note('Backup ' . $run['status'] . ($run['error'] ? ': ' . $run['error'] : '.'));

            return EXIT_ERROR;
        }

        CLI::write(sprintf('Backup %s (%s, %s).', $run['status'], $run['file_name'], number_to_size((int) $run['size_bytes'])), 'green');

        if ($rows === []) {
            CLI::write('No destinations are enabled, so the backup only exists on this server. Add one in Admin > Backups.', 'yellow');
        }

        return EXIT_SUCCESS;
    }

    private function dryRun(Backups $config, ?int $only): int
    {
        CLI::write('Passphrase: ' . ($config->encryptionReady() ? 'set (backups will be encrypted)' : 'NOT set'), $config->encryptionReady() ? 'green' : 'yellow');
        CLI::write('Work folder: ' . $config->dir());
        CLI::write('mysqldump: ' . $config->mysqldumpPath);

        $destinations = (new DestinationService())->enabled($only);

        if ($destinations === []) {
            CLI::write('No enabled destinations.', 'yellow');
        }

        foreach ($destinations as $d) {
            CLI::write('Destination #' . $d['id'] . ' ' . $d['name'] . ' (' . $d['type'] . '), keep ' . $d['keep_last']);
        }

        return EXIT_SUCCESS;
    }
}

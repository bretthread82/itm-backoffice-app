<?php

declare(strict_types=1);

namespace App\Core\Backups;

use Config\Backups as BackupsConfig;

/**
 * Starts "php spark backup:run" in the background for the Run now button, so the web request does not wait
 * for a dump that can take minutes. The command takes the same lock as cron, so two runs never overlap.
 */
class BackupLauncher
{
    public const LOCK = 'cache/backup.lock';

    public function isRunning(): bool
    {
        $lock = @fopen(WRITEPATH . self::LOCK, 'c');

        if (! $lock) {
            return false;
        }

        $free = flock($lock, LOCK_EX | LOCK_NB);

        if ($free) {
            flock($lock, LOCK_UN);
        }

        fclose($lock);

        return ! $free;
    }

    /**
     * @return string|null  null when started, otherwise why it could not be
     */
    public function start(int $userId, ?int $destinationId = null): ?string
    {
        if (! function_exists('popen') || in_array('popen', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return 'This server does not allow the web app to start background jobs. Run php spark backup:run from the command line instead.';
        }

        if ($this->isRunning()) {
            return 'A backup is already running.';
        }

        $php  = config(BackupsConfig::class)->phpPath;
        // CodeIgniter's CLI reads "--option value", not "--option=value".
        $args = ['backup:run', '--trigger', 'manual', '--user', (string) $userId];

        if ($destinationId !== null) {
            array_push($args, '--destination', (string) $destinationId);
        }

        $line = escapeshellarg($php) . ' ' . escapeshellarg(ROOTPATH . 'spark') . ' ' . implode(' ', array_map('escapeshellarg', $args));

        $line = DIRECTORY_SEPARATOR === '\\'
            ? 'start /B "" ' . $line . ' > NUL 2>&1'
            : 'nohup ' . $line . ' > /dev/null 2>&1 &';

        $handle = @popen($line, 'r');

        if ($handle === false) {
            return 'Could not start the backup.';
        }

        pclose($handle);

        return null;
    }
}

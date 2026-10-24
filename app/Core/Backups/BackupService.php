<?php

declare(strict_types=1);

namespace App\Core\Backups;

use App\Core\Backups\Destinations\Destination;
use Config\Backups as BackupsConfig;
use Throwable;

/**
 * Runs a backup: dump, encrypt, send to every enabled destination, record the outcome, prune old copies.
 * All backup runs go through here, whether started by cron or by a person.
 */
class BackupService
{
    public function __construct(
        private readonly ?DatabaseDumper $dumper = null,
        private readonly ?BackupEncryptor $encryptor = null,
        private readonly ?DestinationService $destinations = null,
        private readonly ?BackupsConfig $config = null,
    ) {
    }

    /**
     * @return array<string, mixed>  the finished bk_runs row
     */
    public function run(string $trigger = 'cron', ?int $userId = null, ?int $onlyDestination = null): array
    {
        $cfg = $this->config ?? config(BackupsConfig::class);
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        // The caller holds the lock, so anything still marked running was killed part-way.
        $db->table('bk_runs')->where('status', 'running')->update(['status' => 'failed', 'finished_at' => $now, 'error' => 'The run was interrupted (server restart or process killed).']);

        $db->table('bk_runs')->insert(['trigger_type' => $trigger, 'triggered_by' => $userId, 'status' => 'running', 'started_at' => $now]);
        $runId = (int) $db->insertID();

        $encrypt = $cfg->encryptionReady();
        $base    = $cfg->dir() . DIRECTORY_SEPARATOR . 'itm-backup-' . gmdate('Ymd-His');
        $plain   = $base . '.sql.gz';
        $final   = $encrypt ? $plain . '.enc' : $plain;

        try {
            if (! $encrypt && $cfg->requireEncryption) {
                throw new \RuntimeException('No backup passphrase is set. Add backups.passphrase (12+ characters) to .env, or switch off backups.requireEncryption.');
            }

            if (! is_dir($cfg->dir()) && ! @mkdir($cfg->dir(), 0750, true) && ! is_dir($cfg->dir())) {
                throw new \RuntimeException('Cannot create the backup folder ' . $cfg->dir() . '.');
            }

            ($this->dumper ?? new DatabaseDumper($cfg))->dump($plain);

            if ($encrypt) {
                ($this->encryptor ?? new BackupEncryptor($cfg->kdfIterations))->encryptFile($plain, $final, $cfg->passphrase);
                @unlink($plain);
            }

            $db->table('bk_runs')->where('id', $runId)->update([
                'file_name'  => basename($final),
                'size_bytes' => (int) filesize($final),
                'sha256'     => hash_file('sha256', $final),
                'encrypted'  => $encrypt ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            @unlink($plain);
            @unlink($final);

            return $this->finish($runId, 'failed', $this->clean($e->getMessage()), $userId);
        }

        $results = $this->send($runId, $final, $onlyDestination);

        $this->pruneLocal($cfg);

        $failed = count(array_filter($results, static fn (string $s) => $s === 'failed'));
        $status = match (true) {
            $failed === 0                 => 'success',
            $failed === count($results)   => 'failed',
            default                       => 'partial',
        };

        return $this->finish($runId, $status, $failed > 0 ? $failed . ' of ' . count($results) . ' destination(s) failed.' : null, $userId);
    }

    /**
     * @return array<int, string>  destination id => ok|failed
     */
    private function send(int $runId, string $file, ?int $onlyDestination): array
    {
        $db      = db_connect();
        $results = [];
        $name    = basename($file);

        foreach (($this->destinations ?? new DestinationService())->enabled($onlyDestination) as $row) {
            $id = (int) $row['id'];
            $db->table('bk_run_destinations')->insert(['run_id' => $runId, 'destination_id' => $id, 'status' => 'pending']);

            try {
                $dest = ($this->destinations ?? new DestinationService())->build($row);
                $path = $dest->put($file, $name);

                $db->table('bk_run_destinations')->where(['run_id' => $runId, 'destination_id' => $id])->update([
                    'status' => 'ok', 'remote_path' => mb_strimwidth($path, 0, 500), 'bytes' => (int) filesize($file), 'finished_at' => date('Y-m-d H:i:s'),
                ]);
                $results[$id] = 'ok';
            } catch (Throwable $e) {
                $db->table('bk_run_destinations')->where(['run_id' => $runId, 'destination_id' => $id])->update([
                    'status' => 'failed', 'error' => mb_strimwidth($this->clean($e->getMessage()), 0, 990, '…'), 'finished_at' => date('Y-m-d H:i:s'),
                ]);
                $results[$id] = 'failed';

                continue;
            }

            $this->prune($dest, (int) $row['keep_last'], $name, (string) $row['name']);
        }

        return $results;
    }

    /**
     * Keeps the newest $keep backups at a destination. Only files this app made are ever touched, and the
     * file just sent is never removed. A prune problem is logged but does not fail the backup.
     */
    public function prune(Destination $dest, int $keep, string $justSent = '', string $label = ''): void
    {
        try {
            $names = array_values(array_filter($dest->list(), static fn (string $n) => preg_match(Destination::NAME_PATTERN, $n) === 1));
            rsort($names);

            foreach (array_slice($names, max(1, $keep)) as $old) {
                if ($old !== $justSent) {
                    $dest->delete($old);
                }
            }
        } catch (Throwable $e) {
            log_message('warning', 'Backup prune failed' . ($label !== '' ? ' for ' . $label : '') . ': ' . $e->getMessage());
        }
    }

    private function pruneLocal(BackupsConfig $cfg): void
    {
        $files = glob($cfg->dir() . DIRECTORY_SEPARATOR . 'itm-backup-*') ?: [];
        $files = array_values(array_filter($files, static fn (string $f) => preg_match(Destination::NAME_PATTERN, basename($f)) === 1));
        rsort($files);

        foreach (array_slice($files, max(1, $cfg->localKeep)) as $old) {
            @unlink($old);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function finish(int $runId, string $status, ?string $error, ?int $userId): array
    {
        $db = db_connect();
        $db->table('bk_runs')->where('id', $runId)->update(['status' => $status, 'finished_at' => date('Y-m-d H:i:s'), 'error' => $error]);

        $run = $db->table('bk_runs')->where('id', $runId)->get()->getRowArray();

        service('audit')->log('backups.run_' . $status, 'backups', $runId, null, ['file' => $run['file_name'], 'error' => $error], $userId);

        if ($status !== 'success') {
            service('notifier')->sendToPermission(
                'backups.manage',
                'backups.failed',
                $status === 'partial' ? 'A backup only partly succeeded' : 'A backup failed',
                $error ?? 'See the backup status page for details.',
                'admin/backups/runs/' . $runId,
            );
        }

        return $run;
    }

    private function clean(string $message): string
    {
        return mb_strimwidth($message, 0, 1500, '…');
    }
}

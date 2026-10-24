<?php

declare(strict_types=1);

namespace App\Core\Backups;

use Config\Backups as BackupsConfig;
use RuntimeException;

/**
 * Dumps the application database with mysqldump into a gzip file. The password is passed in the environment,
 * never on the command line, where other users on the server could read it.
 */
class DatabaseDumper
{
    public function __construct(private readonly ?BackupsConfig $config = null)
    {
    }

    public function dump(string $targetGz): void
    {
        $cfg = $this->config ?? config(BackupsConfig::class);
        $db  = config('Database')->default;

        if (! function_exists('proc_open')) {
            throw new RuntimeException('PHP\'s proc_open is disabled, so mysqldump cannot be run.');
        }

        $command = [
            $cfg->mysqldumpPath,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--host=' . $db['hostname'],
            '--port=' . ($db['port'] ?? 3306),
            '--user=' . $db['username'],
            $db['database'],
        ];

        $errFile = tempnam(sys_get_temp_dir(), 'bke');
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', (string) $errFile, 'w']], $pipes, null, ['MYSQL_PWD' => (string) $db['password']] + getenv());

        if (! is_resource($process)) {
            @unlink((string) $errFile);

            throw new RuntimeException('Could not start mysqldump. Check backups.mysqldumpPath in .env.');
        }

        $gz   = gzopen($targetGz, 'wb6');
        $tail = '';

        if (! $gz) {
            proc_close($process);
            @unlink((string) $errFile);

            throw new RuntimeException('Cannot write ' . $targetGz . '.');
        }

        while (! feof($pipes[1])) {
            $data = fread($pipes[1], 262144);

            if ($data === false || $data === '') {
                continue;
            }

            gzwrite($gz, $data);
            $tail = substr($tail . $data, -300);
        }

        fclose($pipes[1]);
        gzclose($gz);

        $code  = proc_close($process);
        $error = trim((string) @file_get_contents((string) $errFile));
        @unlink((string) $errFile);

        // mysqldump ends a complete dump with this comment; its absence means the output was cut short.
        if ($code !== 0 || ! str_contains($tail, 'Dump completed')) {
            @unlink($targetGz);

            throw new RuntimeException('mysqldump failed' . ($code !== 0 ? ' (exit ' . $code . ')' : ' (output incomplete)') . ($error !== '' ? ': ' . mb_strimwidth($error, 0, 400, '…') : '.'));
        }
    }
}

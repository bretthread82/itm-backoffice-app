<?php

namespace App\Commands;

use App\Core\Backups\BackupEncryptor;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Backups;
use Throwable;

class BackupDecrypt extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'backup:decrypt';
    protected $description = 'Decrypts a backup file so it can be restored. The result is a gzip-compressed SQL dump.';
    protected $usage       = 'backup:decrypt <encrypted-file> <output-file>';
    protected $arguments   = [
        'encrypted-file' => 'The .enc backup to read.',
        'output-file'    => 'Where to write the .sql.gz (an existing file is not overwritten).',
    ];

    public function run(array $params)
    {
        $in  = $params[0] ?? null;
        $out = $params[1] ?? null;

        if (! $in || ! $out) {
            CLI::error('Usage: php spark ' . $this->usage);

            return EXIT_ERROR;
        }

        if (! is_file($in) || ! BackupEncryptor::isEncrypted($in)) {
            CLI::error('That is not an encrypted ITM backup file.');

            return EXIT_ERROR;
        }

        if (file_exists($out)) {
            CLI::error($out . ' already exists.');

            return EXIT_ERROR;
        }

        // Never taken from the command line, where it would end up in shell history.
        $passphrase = config(Backups::class)->passphrase ?: (string) getenv('BACKUP_PASSPHRASE');

        if ($passphrase === '') {
            $passphrase = CLI::prompt('Backup passphrase');
        }

        try {
            (new BackupEncryptor())->decryptFile($in, $out, $passphrase);
        } catch (Throwable $e) {
            @unlink($out);
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Decrypted to ' . $out . '. Restore with: gunzip -c ' . $out . ' | mysql -u USER -p DATABASE', 'green');

        return EXIT_SUCCESS;
    }
}

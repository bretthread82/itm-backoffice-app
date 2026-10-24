<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Database backups. Every value can be set from .env with the `backups.` prefix,
 * e.g. backups.passphrase = '...'. The passphrase belongs in .env only and must also
 * be kept somewhere safe off the server: without it an encrypted backup cannot be restored.
 * Remote destinations are managed in Admin > Backups, not here.
 */
class Backups extends BaseConfig
{
    /**
     * Passphrase used to encrypt every backup file (AES-256-GCM). Deliberately not the
     * application encryption key, so a backup can be restored on a rebuilt server.
     */
    public string $passphrase = '';

    /**
     * When true, a backup is never written or uploaded without a passphrase.
     */
    public bool $requireEncryption = true;

    /**
     * Path to the mysqldump binary (a bare name is looked up on PATH).
     */
    public string $mysqldumpPath = 'mysqldump';

    /**
     * PHP command line binary used when "Run now" starts a background backup from the web app.
     */
    public string $phpPath = 'php';

    /**
     * Where dumps are built and where the local copy is kept. Defaults to writable/backups.
     */
    public string $workDir = '';

    /**
     * Encrypted backups kept in the work directory.
     */
    public int $localKeep = 3;

    /**
     * Hours without a successful backup before the status page warns and admins are notified.
     */
    public int $staleHours = 26;

    /**
     * Encryption work factor for deriving the file key from the passphrase.
     */
    public int $kdfIterations = 200000;

    public function dir(): string
    {
        return rtrim($this->workDir !== '' ? $this->workDir : WRITEPATH . 'backups', '/\\');
    }

    public function encryptionReady(): bool
    {
        return strlen($this->passphrase) >= 12 && ! str_starts_with($this->passphrase, 'YOUR-');
    }
}

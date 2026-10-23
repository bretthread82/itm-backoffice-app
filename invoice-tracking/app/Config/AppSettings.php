<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Defaults for settings an administrator can change in Admin > Settings. Saved values live in
 * the database (CodeIgniter Settings) and override these.
 */
class AppSettings extends BaseConfig
{
    /** Sender for all system email. The domain must be authenticated in SendGrid. */
    public string $mailFromEmail = 'invoicing@maloneygroup.net';
    public string $mailFromName  = 'Maloney Group Invoicing';
    public string $mailReplyTo   = '';

    /** Largest invoice PDF accepted, in megabytes. */
    public int $maxUploadMb = 20;

    /** Hour of day (0-23, server time) the daily digest is meant to run. Informational for cron. */
    public int $digestHour = 7;

    /** Currency pre-filled on new invoices. */
    public string $defaultCurrency = 'BBD';

    /** Microsoft Entra ID sign-in. Built in but OFF until an administrator switches it on (and .env is configured). */
    public bool $entraEnabled = false;

    /** Days a server copy of a completed invoice file is kept (used by the purge job). */
    public int $retentionDays = 30;

    /** Group logo shown on the dashboard when all companies are selected (file name under writable/uploads/logos). */
    public string $groupLogoFile = '';
    public string $groupLogoMime = '';

    /** Optional antivirus command run on every upload, with {file} as the path. Empty = none. Set in .env, not in the admin screen. */
    public string $scanCommand = '';
}

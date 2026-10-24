<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Defaults for the payables module. Saved values (CodeIgniter Settings) override these.
 */
class InvoiceSettings extends BaseConfig
{
    /** Largest invoice PDF or supporting document accepted, in megabytes. */
    public int $maxUploadMb = 20;

    /** Currency pre-filled on new invoices. */
    public string $defaultCurrency = 'USD';

    /** Days a server copy of a finished invoice file is kept (used by the purge job, phase 2). */
    public int $retentionDays = 30;

    /** Optional antivirus command run on every upload, with {file} as the path. Empty = none. Set in .env. */
    public string $scanCommand = '';
}

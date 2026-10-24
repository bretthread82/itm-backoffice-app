<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Defaults for the receivables module. Saved values (CodeIgniter Settings) override these.
 * Upload limits and the antivirus command are shared with payables (InvoiceSettings).
 */
class ReceivablesSettings extends BaseConfig
{
    /** Days between overdue reminders for the same invoice: the first goes the day after the due date. */
    public int $remindEveryDays = 7;

    /** Days before a subscription is billed that its owner and collectors are told, comma separated (one notice per window). */
    public string $subscriptionLeadDays = '14,7,1';
}

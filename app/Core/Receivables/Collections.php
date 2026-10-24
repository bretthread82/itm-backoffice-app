<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use CodeIgniter\Database\BaseConnection;

/**
 * What is owed and what is late. "Overdue" is never stored: an issued or part-paid invoice is overdue when its due date
 * is before today on the invoice's company calendar (companies can sit in different timezones).
 */
final class Collections
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @param array<string, mixed> $inv an ar_invoices row
     *
     * @return string the unpaid balance, e.g. "150.00"
     */
    public static function outstanding(array $inv): string
    {
        return number_format((ReceivablesEngine::cents((string) ($inv['amount'] ?? '0')) - ReceivablesEngine::cents((string) ($inv['amount_paid'] ?? '0'))) / 100, 2, '.', '');
    }

    /**
     * Whole days past the due date as of $today (a Y-m-d date): 1 on the day after the due date, 0 when not late.
     *
     * @param array<string, mixed> $inv
     */
    public static function daysOverdue(array $inv, string $today): int
    {
        if (! in_array($inv['status'] ?? '', ReceivablesEngine::COLLECTING, true) || empty($inv['due_date'])) {
            return 0;
        }

        return max(0, (int) round((strtotime($today) - strtotime((string) $inv['due_date'])) / 86400));
    }

    public static function isOverdue(array $inv, string $today): bool
    {
        return self::daysOverdue($inv, $today) > 0;
    }

    /**
     * Every overdue invoice, each with "days_overdue" worked out on its own company's calendar.
     *
     * @return list<array<string, mixed>> most overdue first
     */
    public function overdue(?int $companyId = null): array
    {
        $b = $this->db->table('ar_invoices')->whereIn('status', ReceivablesEngine::COLLECTING)->where('due_date IS NOT NULL')->where('due_date <', date('Y-m-d', strtotime('+1 day')));
        if ($companyId !== null) {
            $b->where('company_id', $companyId);
        }

        $out = [];
        foreach ($b->get()->getResultArray() as $inv) {
            $days = self::daysOverdue($inv, service('timezone')->todayFor((int) $inv['company_id']));
            if ($days > 0) {
                $out[] = $inv + ['days_overdue' => $days];
            }
        }

        usort($out, static fn (array $a, array $b): int => [$b['days_overdue'], $a['id']] <=> [$a['days_overdue'], $b['id']]);

        return $out;
    }
}

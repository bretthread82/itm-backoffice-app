<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Payables\ReportService;
use App\Core\Tenancy\AccessScope;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Numbers for the receivables reports. Always limited to what the user may see (AccessScope).
 *
 * "Outstanding" is what issued and part-paid invoices still owe: the balance, not the invoice amount. Ageing is by
 * days past the due date on the invoice's own company calendar, in the same bands as the payables reports (not yet due,
 * 1-30, 31-60, 61-90, over 90; 61 days and over is critical), reusing ReportService's pure helpers. Money is never
 * added across currencies: every total is a map of currency => amount.
 */
final class ArReportService
{
    public function __construct(private ?BaseConnection $db = null, private ?AccessScope $scope = null)
    {
        $this->db    ??= db_connect();
        $this->scope ??= new AccessScope($this->db);
    }

    // ------------------------------------------------------------------ outstanding and ageing

    /**
     * Outstanding invoices the user may see, with balance and days past due worked out. Most overdue first.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function outstanding(int $userId, array $filters = []): array
    {
        $rows = $this->baseQuery($userId, $filters)
            ->select('i.id, i.invoice_number, i.customer, i.customer_id, cu.code AS customer_code, cu.name AS customer_current, i.amount, i.amount_paid, i.currency, i.due_date, i.status, i.company_id,
                      i.issued_at, i.last_payment_date, c.code AS company_code, c.name AS company_name, g.name AS group_name')
            ->whereIn('i.status', ReceivablesEngine::COLLECTING)
            ->get()->getResultArray();

        $today = [];
        foreach ($rows as &$r) {
            $companyId          = (int) $r['company_id'];
            $today[$companyId] ??= service('timezone')->todayFor($companyId);
            $r['balance']       = Collections::outstanding($r);
            $r['days_past_due'] = Collections::daysOverdue($r, $today[$companyId]);
            $r['band']          = ReportService::bandFor($r['days_past_due']);
        }
        unset($r);

        usort($rows, static fn (array $a, array $b): int => [$b['days_past_due'], $a['id']] <=> [$a['days_past_due'], $b['id']]);

        return $rows;
    }

    /**
     * Totals for a set of outstanding rows (as returned by outstanding()), by age band, company and sub-group.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    public static function aggregate(array $rows, int $oldestLimit = 10): array
    {
        $add = static function (array &$bucket, array $row): void {
            $bucket['count']++;
            $cur                    = (string) ($row['currency'] ?: '—');
            $bucket['amount'][$cur] = round(($bucket['amount'][$cur] ?? 0.0) + (float) $row['balance'], 2);
        };
        $empty = static fn (): array => ['count' => 0, 'amount' => []];

        $out = [
            'total'     => $empty(),
            'overdue'   => $empty(),
            'critical'  => $empty(),
            'bands'     => array_map($empty, array_fill_keys(array_keys(ReportService::BANDS), 0)),
            'companies' => [],
            'groups'    => [],
        ];

        foreach ($rows as $r) {
            $days = (int) $r['days_past_due'];
            $add($out['total'], $r);
            if ($days > 0) {
                $add($out['overdue'], $r);
            }
            if (ReportService::isCritical($days)) {
                $add($out['critical'], $r);
            }
            $add($out['bands'][ReportService::bandFor($days)], $r);

            $code                      = (string) $r['company_code'];
            $out['companies'][$code] ??= ['code' => $code, 'name' => (string) $r['company_name'], 'company_id' => (int) $r['company_id']] + $empty() + ['overdue' => 0, 'critical' => 0, 'worst' => 0];
            $add($out['companies'][$code], $r);
            $out['companies'][$code]['overdue']  += $days > 0 ? 1 : 0;
            $out['companies'][$code]['critical'] += ReportService::isCritical($days) ? 1 : 0;
            $out['companies'][$code]['worst']     = max($out['companies'][$code]['worst'], $days);

            $group                    = (string) ($r['group_name'] ?? '') !== '' ? (string) $r['group_name'] : 'No sub-group';
            $out['groups'][$group] ??= ['name' => $group] + $empty() + ['overdue' => 0, 'critical' => 0];
            $add($out['groups'][$group], $r);
            $out['groups'][$group]['overdue']  += $days > 0 ? 1 : 0;
            $out['groups'][$group]['critical'] += ReportService::isCritical($days) ? 1 : 0;
        }

        // Most urgent first: critical, then most overdue, then biggest backlog.
        uasort($out['companies'], static fn (array $a, array $b): int => [$b['critical'], $b['worst'], $b['count']] <=> [$a['critical'], $a['worst'], $a['count']]);
        uasort($out['groups'], static fn (array $a, array $b): int => [$b['critical'], $b['count']] <=> [$a['critical'], $a['count']]);

        $overdueRows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['days_past_due'] > 0));
        usort($overdueRows, static fn (array $a, array $b): int => [$b['days_past_due'], (float) $b['balance']] <=> [$a['days_past_due'], (float) $a['balance']]);
        $out['oldest'] = array_slice($overdueRows, 0, $oldestLimit);

        return $out;
    }

    // ------------------------------------------------------------------ by customer

    /** Shown for invoices raised without a customer. */
    public const NO_CUSTOMER = '(No customer)';

    /**
     * Outstanding balances grouped by customer (the shared customer when the invoice is linked to one, otherwise the
     * name typed on the invoice). Biggest backlog first: critical, then overdue, then count.
     *
     * @param list<array<string, mixed>> $rows from outstanding()
     *
     * @return list<array{customer: string, code: string, customer_id: int|null, count: int, amount: array<string, float>, overdue: int, overdue_amount: array<string, float>, critical: int, worst: int}>
     */
    public static function outstandingByCustomer(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            // A linked customer is shown under its current name, so a rename does not split it; otherwise the name typed on the invoice.
            $name = trim((string) ($r['customer_current'] ?? '')) !== '' ? trim((string) $r['customer_current'])
                : (trim((string) ($r['customer'] ?? '')) !== '' ? trim((string) $r['customer']) : self::NO_CUSTOMER);
            $key  = $r['customer_id'] !== null ? 'id:' . $r['customer_id'] : 'name:' . mb_strtolower($name);
            $days = (int) $r['days_past_due'];
            $cur  = (string) ($r['currency'] ?: '—');
            $bal  = (float) $r['balance'];

            $by[$key] ??= [
                'customer' => $name, 'code' => (string) ($r['customer_code'] ?? ''), 'customer_id' => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
                'count' => 0, 'amount' => [], 'overdue' => 0, 'overdue_amount' => [], 'critical' => 0, 'worst' => 0,
            ];
            $by[$key]['count']++;
            $by[$key]['amount'][$cur] = round(($by[$key]['amount'][$cur] ?? 0.0) + $bal, 2);
            if ($days > 0) {
                $by[$key]['overdue']++;
                $by[$key]['overdue_amount'][$cur] = round(($by[$key]['overdue_amount'][$cur] ?? 0.0) + $bal, 2);
            }
            if (ReportService::isCritical($days)) {
                $by[$key]['critical']++;
            }
            $by[$key]['worst'] = max($by[$key]['worst'], $days);
        }

        $list = array_values($by);
        usort($list, static fn (array $a, array $b): int => [$b['critical'], $b['overdue'], $b['count'], $a['customer']] <=> [$a['critical'], $a['overdue'], $a['count'], $b['customer']]);

        return $list;
    }

    // ------------------------------------------------------------------ collections

    /**
     * Money invoiced and money received month by month for the last $months months (oldest first). Invoiced = invoices
     * issued that month (not cancelled); received = receipts by the date received, net of reversals. Each month carries a
     * map per currency, and the list of currencies seen is returned so a chart can pick one.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return array{months: list<array{month: string, label: string, invoiced_count: int, invoiced: array<string, float>, received_count: int, received: array<string, float>}>, currencies: list<string>}
     */
    public function collectionsByMonth(int $userId, array $filters = [], int $months = 12, ?string $today = null): array
    {
        $today ??= service('timezone')->todayFor();
        $out     = [];
        for ($k = $months - 1; $k >= 0; $k--) {
            $ts                    = strtotime(date('Y-m-01', strtotime($today)) . " -{$k} months");
            $out[date('Y-m', $ts)] = ['month' => date('Y-m', $ts), 'label' => date('M y', $ts), 'invoiced_count' => 0, 'invoiced' => [], 'received_count' => 0, 'received' => []];
        }
        $first = array_key_first($out);

        $currencies = [];
        $invoiced   = $this->baseQuery($userId, $filters)
            ->select("DATE_FORMAT(i.issued_at, '%Y-%m') AS m, i.currency, COUNT(*) AS n, SUM(COALESCE(i.amount, 0)) AS total", false)
            ->where('i.issued_at IS NOT NULL', null, false)->where('i.issued_at >=', $first . '-01 00:00:00')
            ->whereIn('i.status', ['issued', 'part_paid', 'paid'])
            ->groupBy("DATE_FORMAT(i.issued_at, '%Y-%m'), i.currency")->get()->getResultArray();
        foreach ($invoiced as $r) {
            if (! isset($out[$r['m']])) {
                continue;
            }
            $cur                                 = (string) ($r['currency'] ?: '—');
            $currencies[$cur]                    = true;
            $out[$r['m']]['invoiced_count']     += (int) $r['n'];
            $out[$r['m']]['invoiced'][$cur]      = round((float) $r['total'], 2);
        }

        $received = $this->baseQuery($userId, $filters)
            ->select("DATE_FORMAT(p.received_date, '%Y-%m') AS m, i.currency, SUM(CASE WHEN p.amount > 0 THEN 1 ELSE 0 END) AS n, SUM(p.amount) AS total", false)
            ->join('ar_payments p', 'p.invoice_id = i.id')
            ->where('p.received_date >=', $first . '-01')
            ->groupBy("DATE_FORMAT(p.received_date, '%Y-%m'), i.currency")->get()->getResultArray();
        foreach ($received as $r) {
            if (! isset($out[$r['m']])) {
                continue;
            }
            $cur                                 = (string) ($r['currency'] ?: '—');
            $currencies[$cur]                    = true;
            $out[$r['m']]['received_count']     += (int) $r['n'];
            $out[$r['m']]['received'][$cur]      = round((float) $r['total'], 2);
        }

        $list = array_keys($currencies);
        sort($list);

        return ['months' => array_values($out), 'currencies' => $list];
    }

    /**
     * How paid invoices performed: how many, the average days from issue to the invoice being paid in full, and how many
     * were paid on or before their due date.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return array{paid: int, avg_days: float|null, with_due: int, on_time: int}
     */
    public function paymentStats(int $userId, array $filters = []): array
    {
        $rows = $this->baseQuery($userId, $filters)
            ->select('i.issued_at, i.paid_at, i.due_date, i.company_id', false)
            ->where('i.status', 'paid')->where('i.paid_at IS NOT NULL', null, false)
            ->get()->getResultArray();

        $days = [];
        $out  = ['paid' => count($rows), 'avg_days' => null, 'with_due' => 0, 'on_time' => 0];
        foreach ($rows as $r) {
            if ($r['issued_at']) {
                $days[] = max(0, (strtotime((string) $r['paid_at']) - strtotime((string) $r['issued_at'])) / 86400);
            }
            if ($r['due_date']) {
                $out['with_due']++;
                // Stored times are UTC; "on time" is judged on the company's own calendar.
                $paidOn = (new \DateTimeImmutable((string) $r['paid_at'], new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone(service('timezone')->forCompany((int) $r['company_id'])))->format('Y-m-d');
                if ($paidOn <= $r['due_date']) {
                    $out['on_time']++;
                }
            }
        }
        if ($days !== []) {
            $out['avg_days'] = round(array_sum($days) / count($days), 1);
        }

        return $out;
    }

    // ------------------------------------------------------------------ filters

    /**
     * Companies the user can see (for filter lists), with their sub-group.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleCompanies(int $userId): array
    {
        $b = $this->db->table('companies')->select('id, code, name, group_id')->orderBy('code');
        $this->scope->restrict($b, $userId, 'companies.id');

        return $b->get()->getResultArray();
    }

    /**
     * Sub-group ids to filter by: the group and all groups beneath it.
     *
     * @return list<int>
     */
    public function groupIds(int $groupId): array
    {
        $ids   = [$groupId];
        $queue = [$groupId];
        while ($queue !== []) {
            $kids  = $this->db->table('company_groups')->select('id')->whereIn('parent_id', $queue)->get()->getResultArray();
            $queue = [];
            foreach ($kids as $k) {
                $id = (int) $k['id'];
                if (! in_array($id, $ids, true)) {
                    $ids[]   = $id;
                    $queue[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array{company?: int, group?: int} $filters
     */
    private function baseQuery(int $userId, array $filters): BaseBuilder
    {
        $b = $this->db->table('ar_invoices i')
            ->join('companies c', 'c.id = i.company_id')
            ->join('company_groups g', 'g.id = c.group_id', 'left')
            ->join('customers cu', 'cu.id = i.customer_id', 'left');

        $this->scope->restrict($b, $userId, 'i.company_id');

        if (! empty($filters['company'])) {
            $b->where('i.company_id', (int) $filters['company']);
        }
        if (! empty($filters['group'])) {
            $b->whereIn('c.group_id', $this->groupIds((int) $filters['group']));
        }

        return $b;
    }
}

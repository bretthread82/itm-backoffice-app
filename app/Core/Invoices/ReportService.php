<?php

declare(strict_types=1);

namespace App\Core\Invoices;

use App\Core\Tenancy\AccessScope;

use CodeIgniter\Database\BaseConnection;

/**
 * Numbers for the dashboard and reports. Always limited to what the user may see (AccessScope).
 *
 * "Outstanding" = submitted and not yet completed: in approval, being processed, or returned for
 * changes. Drafts, rejected and cancelled invoices are not outstanding. The app does not track payment
 * after completion.
 *
 * Aging is by days past the due date: not yet due, 1-30, 31-60 (warning), 61-90 and over 90 (critical).
 * Money is never added across currencies: every total is a map of currency => amount.
 */
final class ReportService
{
    /** @var list<string> */
    public const OUTSTANDING = ['in_approval', 'approved', 'returned'];

    /** A processed invoice is still outstanding while it waits at its pay step (it has a current step). */
    public const AWAITING_PAYMENT = 'processed';

    /** Restricts a query on the invoices table to outstanding invoices: not yet paid, not rejected or cancelled. */
    public static function applyOutstanding(\CodeIgniter\Database\BaseBuilder $builder, string $alias = 'i'): void
    {
        $builder->groupStart()
            ->whereIn($alias . '.status', self::OUTSTANDING)
            ->orGroupStart()->where($alias . '.status', self::AWAITING_PAYMENT)->where($alias . '.current_step_order IS NOT NULL', null, false)->groupEnd()
            ->groupEnd();
    }

    /**
     * @param array<string, mixed> $row status and current_step_order
     */
    public static function isOutstanding(array $row): bool
    {
        return in_array($row['status'], self::OUTSTANDING, true)
            || ($row['status'] === self::AWAITING_PAYMENT && ($row['current_step_order'] ?? null) !== null);
    }

    /** Days past due at or above which an invoice is Critical. */
    public const CRITICAL_FROM = 61;

    /** key => [label, colour] */
    public const BANDS = [
        'current' => ['Not yet due', 'success'],
        '1-30'    => ['1 to 30 days', 'warning'],
        '31-60'   => ['31 to 60 days', 'warning'],
        '61-90'   => ['61 to 90 days', 'danger'],
        '90+'     => ['Over 90 days', 'danger'],
    ];

    public function __construct(
        private ?BaseConnection $db = null,
        private ?AccessScope $scope = null,
        private ?WorkflowEngine $engine = null,
    ) {
        $this->db     ??= db_connect();
        $this->scope  ??= new AccessScope($this->db);
        $this->engine ??= new WorkflowEngine($this->db, $this->scope);
    }

    // ------------------------------------------------------------------ pure helpers

    public static function daysPastDue(?string $dueDate, string $today): int
    {
        if ($dueDate === null || $dueDate === '') {
            return 0;
        }
        $diff = (int) floor((strtotime($today) - strtotime($dueDate)) / 86400);

        return max(0, $diff);
    }

    public static function bandFor(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0  => 'current',
            $daysPastDue <= 30 => '1-30',
            $daysPastDue <= 60 => '31-60',
            $daysPastDue <= 90 => '61-90',
            default            => '90+',
        };
    }

    /** Valid values of the invoice list's "age" filter. */
    public const AGE_FILTERS = ['overdue', 'current', '1-30', '31-60', '61-90', '90+', 'critical'];

    /**
     * Days-past-due range for an age filter: [minimum, maximum], either may be null (open ended).
     *
     * @return array{0: int|null, 1: int|null}|null null for an unknown filter
     */
    public static function ageRange(string $age): ?array
    {
        return match ($age) {
            'overdue'  => [1, null],
            'critical' => [self::CRITICAL_FROM, null],
            'current'  => [null, 0],
            '1-30'     => [1, 30],
            '31-60'    => [31, 60],
            '61-90'    => [61, 90],
            '90+'      => [91, null],
            default    => null,
        };
    }

    /**
     * Restricts a query on a due-date column to an age filter.
     */
    public static function applyAge(\CodeIgniter\Database\BaseBuilder $builder, string $age, string $dueColumn, ?string $today = null): void
    {
        $range = self::ageRange($age);
        if ($range === null) {
            return;
        }
        $today ??= service('timezone')->todayFor();
        [$min, $max] = $range;

        if ($min !== null) {
            $builder->where($dueColumn . ' <=', date('Y-m-d', strtotime($today) - $min * 86400));
        }
        if ($max !== null) {
            $builder->where($dueColumn . ' >=', date('Y-m-d', strtotime($today) - $max * 86400));
        }
    }

    public static function isCritical(int $daysPastDue): bool
    {
        return $daysPastDue >= self::CRITICAL_FROM;
    }

    /**
     * Totals for a set of outstanding rows (as returned by outstanding()).
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
            $bucket['amount'][$cur] = round(($bucket['amount'][$cur] ?? 0.0) + (float) $row['amount'], 2);
        };
        $empty = static fn (): array => ['count' => 0, 'amount' => []];

        $out = [
            'total'    => $empty(),
            'overdue'  => $empty(),
            'critical' => $empty(),
            'bands'    => array_map($empty, array_fill_keys(array_keys(self::BANDS), 0)),
            'companies' => [],
            'groups'   => [],
        ];

        foreach ($rows as $r) {
            $days = (int) $r['days_past_due'];
            $add($out['total'], $r);
            if ($days > 0) {
                $add($out['overdue'], $r);
            }
            if (self::isCritical($days)) {
                $add($out['critical'], $r);
            }
            $add($out['bands'][self::bandFor($days)], $r);

            $code                      = (string) $r['company_code'];
            $out['companies'][$code] ??= ['code' => $code, 'name' => (string) $r['company_name'], 'company_id' => (int) $r['company_id']] + $empty() + ['overdue' => 0, 'critical' => 0, 'worst' => 0];
            $add($out['companies'][$code], $r);
            $out['companies'][$code]['overdue']  += $days > 0 ? 1 : 0;
            $out['companies'][$code]['critical'] += self::isCritical($days) ? 1 : 0;
            $out['companies'][$code]['worst']     = max($out['companies'][$code]['worst'], $days);

            $group                    = (string) ($r['group_name'] ?? '') !== '' ? (string) $r['group_name'] : 'No sub-group';
            $out['groups'][$group] ??= ['name' => $group] + $empty() + ['overdue' => 0, 'critical' => 0];
            $add($out['groups'][$group], $r);
            $out['groups'][$group]['overdue']  += $days > 0 ? 1 : 0;
            $out['groups'][$group]['critical'] += self::isCritical($days) ? 1 : 0;
        }

        // Most urgent first: critical, then most overdue, then biggest backlog.
        uasort($out['companies'], static fn (array $a, array $b): int => [$b['critical'], $b['worst'], $b['count']] <=> [$a['critical'], $a['worst'], $a['count']]);
        uasort($out['groups'], static fn (array $a, array $b): int => [$b['critical'], $b['count']] <=> [$a['critical'], $a['count']]);

        $overdueRows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['days_past_due'] > 0));
        usort($overdueRows, static fn (array $a, array $b): int => [$b['days_past_due'], (float) $b['amount']] <=> [$a['days_past_due'], (float) $a['amount']]);
        $out['oldest'] = array_slice($overdueRows, 0, $oldestLimit);

        return $out;
    }

    /**
     * "USD 1,200.00 · BBD 300.00" for display. Empty map shows a dash.
     *
     * @param array<string, float> $amounts
     */
    public static function formatAmounts(array $amounts): string
    {
        if ($amounts === []) {
            return '—';
        }
        ksort($amounts);

        return implode(' · ', array_map(static fn (string $c, float $a): string => $c . ' ' . number_format($a, 2), array_keys($amounts), $amounts));
    }

    // ------------------------------------------------------------------ queries

    /**
     * Outstanding invoices the user may see, with days past due worked out.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function outstanding(int $userId, array $filters = [], ?string $today = null): array
    {
        $today ??= service('timezone')->todayFor();

        $builder = $this->baseQuery($userId, $filters)
            ->select('i.id, i.invoice_number, i.supplier, i.amount, i.currency, i.due_date, i.status, i.company_id, i.submitted_at, i.route_snapshot, i.current_step_order,
                      c.code AS company_code, c.name AS company_name, g.name AS group_name,
                      (SELECT MAX(a.acted_at) FROM inv_actions a WHERE a.invoice_id = i.id AND a.action != \'attach\') AS last_action_at', false)
            ;
        self::applyOutstanding($builder);

        $rows = $builder->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['days_past_due'] = self::daysPastDue($r['due_date'], $today);
            $r['band']          = self::bandFor($r['days_past_due']);
            $since              = $r['last_action_at'] ?: ($r['submitted_at'] ?: null);
            $r['days_waiting']  = $since ? max(0, (int) floor((strtotime($today . ' 23:59:59') - strtotime((string) $since)) / 86400)) : 0;
            $step               = $this->engine->currentStep($r);
            $r['step_label']    = $step !== null ? $this->engine->label($step) : '';
            unset($r['route_snapshot']);
        }
        unset($r);

        usort($rows, static fn (array $a, array $b): int => [$b['days_past_due'], $b['id']] <=> [$a['days_past_due'], $a['id']]);

        return $rows;
    }

    /**
     * Invoice counts by status for what the user may see.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return array<string, int>
     */
    public function countsByStatus(int $userId, array $filters = []): array
    {
        $rows = $this->baseQuery($userId, $filters)->select('i.status, COUNT(*) AS n', false)->groupBy('i.status')->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Outstanding rows (from outstanding()) whose due date falls from today to $days days ahead.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{count: int, amount: array<string, float>}
     */
    public static function dueWithin(array $rows, int $days, ?string $today = null): array
    {
        $today ??= service('timezone')->todayFor();
        $end     = date('Y-m-d', strtotime($today) + $days * 86400);
        $out     = ['count' => 0, 'amount' => []];
        foreach ($rows as $r) {
            if ($r['due_date'] !== null && $r['due_date'] >= $today && $r['due_date'] <= $end) {
                $out['count']++;
                $cur                 = (string) ($r['currency'] ?: '—');
                $out['amount'][$cur] = round(($out['amount'][$cur] ?? 0.0) + (float) $r['amount'], 2);
            }
        }

        return $out;
    }

    /**
     * Amount submitted so far this year and in the same period last year, per currency.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return array{year: array<string, float>, prior: array<string, float>}
     */
    public function invoicedYearToDate(int $userId, array $filters = [], ?string $today = null): array
    {
        $today ??= service('timezone')->todayFor();
        $year    = (int) substr($today, 0, 4);
        $mmdd    = substr($today, 5);
        $out     = ['year' => [], 'prior' => []];

        foreach (['year' => $year, 'prior' => $year - 1] as $key => $y) {
            $rows = $this->baseQuery($userId, $filters)
                ->select('i.currency, SUM(i.amount) AS total', false)
                ->whereNotIn('i.status', ['draft', 'cancelled'])
                ->where('i.submitted_at >=', $y . '-01-01 00:00:00')
                ->where('i.submitted_at <=', $y . '-' . $mmdd . ' 23:59:59')
                ->groupBy('i.currency')->get()->getResultArray();
            foreach ($rows as $r) {
                $out[$key][(string) ($r['currency'] ?: '—')] = round((float) $r['total'], 2);
            }
        }

        return $out;
    }

    /**
     * How completed invoices performed: how many, the average days from submission to completion,
     * and how many were completed on or before their due date (invoices with no due date are skipped
     * for the on-time rate).
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return array{completed: int, avg_days: float|null, with_due: int, on_time: int}
     */
    public function completionStats(int $userId, array $filters = []): array
    {
        $rows = $this->baseQuery($userId, $filters)
            ->select('i.submitted_at, i.completed_at, i.due_date', false)
            ->whereIn('i.status', ['processed', 'paid'])->where('i.completed_at IS NOT NULL', null, false)
            ->get()->getResultArray();

        $days = [];
        $out  = ['completed' => count($rows), 'avg_days' => null, 'with_due' => 0, 'on_time' => 0];
        foreach ($rows as $r) {
            if ($r['submitted_at']) {
                $days[] = max(0, (strtotime((string) $r['completed_at']) - strtotime((string) $r['submitted_at'])) / 86400);
            }
            if ($r['due_date']) {
                $out['with_due']++;
                if (substr((string) $r['completed_at'], 0, 10) <= $r['due_date']) {
                    $out['on_time']++;
                }
            }
        }
        if ($days !== []) {
            $out['avg_days'] = round(array_sum($days) / count($days), 1);
        }

        return $out;
    }

    /**
     * Monthly invoice activity for the last $months months (oldest first): invoices submitted, completed,
     * and, of those submitted that month, how many are still outstanding and how many are past due now.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return list<array{month: string, label: string, submitted: int, completed: int, pending: int, overdue: int}>
     */
    public function monthlyTrend(int $userId, array $filters = [], int $months = 10, ?string $today = null): array
    {
        $today ??= service('timezone')->todayFor();
        $out     = [];
        for ($k = $months - 1; $k >= 0; $k--) {
            $ts                       = strtotime(date('Y-m-01', strtotime($today)) . " -{$k} months");
            $out[date('Y-m', $ts)]    = ['month' => date('Y-m', $ts), 'label' => date('M', $ts), 'submitted' => 0, 'completed' => 0, 'pending' => 0, 'overdue' => 0];
        }
        $first = array_key_first($out) . '-01 00:00:00';

        $rows = $this->baseQuery($userId, $filters)
            ->select('i.status, i.current_step_order, i.submitted_at, i.completed_at, i.due_date', false)
            ->groupStart()->where('i.submitted_at >=', $first)->orWhere('i.completed_at >=', $first)->groupEnd()
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $sm = $r['submitted_at'] ? substr((string) $r['submitted_at'], 0, 7) : null;
            $cm = $r['completed_at'] ? substr((string) $r['completed_at'], 0, 7) : null;
            if ($sm !== null && isset($out[$sm]) && ! in_array($r['status'], ['draft', 'cancelled'], true)) {
                $out[$sm]['submitted']++;
                if (self::isOutstanding($r)) {
                    $out[$sm]['pending']++;
                    if (self::daysPastDue($r['due_date'], $today) > 0) {
                        $out[$sm]['overdue']++;
                    }
                }
            }
            if ($cm !== null && isset($out[$cm]) && in_array($r['status'], ['processed', 'paid'], true)) {
                $out[$cm]['completed']++;
            }
        }

        return array_values($out);
    }

    /**
     * Submitted invoices (everything except drafts and cancelled).
     *
     * @param array<string, int> $counts
     */
    public static function totalSubmitted(array $counts): int
    {
        return array_sum(array_diff_key($counts, ['draft' => 1, 'cancelled' => 1]));
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
            $kids = $this->db->table('company_groups')->select('id')->whereIn('parent_id', $queue)->get()->getResultArray();
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
     * Companies the user can see (for filter lists).
     *
     * @return list<array<string, mixed>>
     */
    public function visibleCompanies(int $userId): array
    {
        $b = $this->db->table('companies')->select('id, code, name, group_id')->orderBy('code');
        $this->scope->restrict($b, $userId, 'companies.id');

        return $b->get()->getResultArray();
    }

    // ------------------------------------------------------------------ by supplier

    /** Period type => how many periods the supplier totals report shows. */
    public const SUPPLIER_PERIODS = ['week' => 8, 'month' => 6, 'quarter' => 4, 'year' => 3];

    /** Shown for invoices saved without a supplier. */
    public const NO_SUPPLIER = '(No supplier)';

    /**
     * The calendar periods ending with the one that contains $anchor, oldest first. Weeks start on Monday.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    public static function periods(string $type, string $anchor, ?int $count = null): array
    {
        $count ??= self::SUPPLIER_PERIODS[$type] ?? 6;
        $d       = new \DateTimeImmutable($anchor);
        $out     = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            switch ($type) {
                case 'week':
                    $from  = $d->modify('monday this week')->modify('-' . $i . ' weeks');
                    $to    = $from->modify('+6 days');
                    $label = $from->format('j M') . ' – ' . $to->format('j M Y');
                    break;

                case 'quarter':
                    $q     = intdiv((int) $d->format('n') - 1, 3);
                    $from  = $d->setDate((int) $d->format('Y'), $q * 3 + 1, 1)->modify('-' . ($i * 3) . ' months');
                    $to    = $from->modify('+3 months -1 day');
                    $label = 'Q' . (intdiv((int) $from->format('n') - 1, 3) + 1) . ' ' . $from->format('Y');
                    break;

                case 'year':
                    $from  = $d->setDate((int) $d->format('Y') - $i, 1, 1);
                    $to    = $from->setDate((int) $from->format('Y'), 12, 31);
                    $label = $from->format('Y');
                    break;

                default: // month
                    $from  = $d->modify('first day of this month')->modify('-' . $i . ' months');
                    $to    = $from->modify('last day of this month');
                    $label = $from->format('M Y');
            }
            $out[] = ['label' => $label, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        }

        return $out;
    }

    /**
     * Outstanding invoices grouped by supplier (rows as returned by outstanding()). Most invoices first.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{supplier: string, count: int, amount: array<string, float>, overdue: int, critical: int, worst: int}>
     */
    public static function outstandingBySupplier(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) $r['supplier']) !== '' ? trim((string) $r['supplier']) : self::NO_SUPPLIER;
            $days = (int) $r['days_past_due'];
            $cur  = (string) ($r['currency'] ?: '—');

            $out[$name] ??= ['supplier' => $name, 'count' => 0, 'amount' => [], 'overdue' => 0, 'critical' => 0, 'worst' => 0];
            $out[$name]['count']++;
            $out[$name]['amount'][$cur]  = round(($out[$name]['amount'][$cur] ?? 0.0) + (float) $r['amount'], 2);
            $out[$name]['overdue']      += $days > 0 ? 1 : 0;
            $out[$name]['critical']     += self::isCritical($days) ? 1 : 0;
            $out[$name]['worst']         = max($out[$name]['worst'], $days);
        }
        uasort($out, static fn (array $a, array $b): int => [$b['count'], $a['supplier']] <=> [$a['count'], $b['supplier']]);

        return array_values($out);
    }

    /**
     * Invoices submitted in each period, per supplier. Drafts and cancelled invoices are left out.
     * Amounts are per currency, never added together.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return array{periods: list<array{label: string, from: string, to: string}>, suppliers: list<array<string, mixed>>, totals: list<array<string, mixed>>}
     */
    public function totalsBySupplier(int $userId, array $filters, string $type, ?string $anchor = null): array
    {
        $periods = self::periods($type, $anchor ?? service('timezone')->todayFor());
        $blank   = static fn (): array => ['count' => 0, 'amount' => []];
        $rows    = [];
        $totals  = array_fill(0, count($periods), $blank());
        $name    = "COALESCE(NULLIF(TRIM(i.supplier), ''), " . $this->db->escape(self::NO_SUPPLIER) . ')';

        foreach ($periods as $idx => $p) {
            $found = $this->baseQuery($userId, $filters)
                ->select($name . ' AS supplier, i.currency, COUNT(*) AS n, SUM(i.amount) AS total', false)
                ->whereNotIn('i.status', ['draft', 'cancelled'])
                ->where('i.submitted_at >=', $p['from'] . ' 00:00:00')
                ->where('i.submitted_at <=', $p['to'] . ' 23:59:59')
                ->groupBy($name . ', i.currency', false)
                ->get()->getResultArray();

            foreach ($found as $r) {
                $sup = (string) $r['supplier'];
                $cur = (string) ($r['currency'] ?: '—');
                $amt = round((float) $r['total'], 2);

                $rows[$sup] ??= ['supplier' => $sup, 'cells' => array_fill(0, count($periods), $blank()), 'count' => 0];
                $rows[$sup]['cells'][$idx]['count']        += (int) $r['n'];
                $rows[$sup]['cells'][$idx]['amount'][$cur]  = round(($rows[$sup]['cells'][$idx]['amount'][$cur] ?? 0.0) + $amt, 2);
                $rows[$sup]['count']                       += (int) $r['n'];

                $totals[$idx]['count']        += (int) $r['n'];
                $totals[$idx]['amount'][$cur]  = round(($totals[$idx]['amount'][$cur] ?? 0.0) + $amt, 2);
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['supplier']] <=> [$a['count'], $b['supplier']]);

        return ['periods' => $periods, 'suppliers' => $rows, 'totals' => $totals];
    }

    /**
     * Supplier code for each supplier name on the managers' list, keyed by lower-case name.
     *
     * @return array<string, string>
     */
    public function supplierCodes(): array
    {
        $codes = [];
        foreach (service('suppliers')->options(false) as $s) {
            $codes[mb_strtolower((string) $s['name'])] = (string) $s['code'];
        }

        return $codes;
    }

    /**
     * @param array{company?: int, group?: int} $filters
     */
    private function baseQuery(int $userId, array $filters): \CodeIgniter\Database\BaseBuilder
    {
        $b = $this->db->table('inv_invoices i')
            ->join('companies c', 'c.id = i.company_id')
            ->join('company_groups g', 'g.id = c.group_id', 'left');

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

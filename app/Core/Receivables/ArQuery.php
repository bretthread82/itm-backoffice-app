<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Payables\ReportService;
use App\Core\Tenancy\AccessScope;
use CodeIgniter\Database\BaseBuilder;

/**
 * The customer invoice register query: filters, sorting and the AccessScope restriction in one place, shared by the
 * register screen and its CSV export. It knows nothing about the session or the request; the caller decides which
 * company (if any) to narrow to. The age filters reuse the payables ReportService helpers, which are pure date maths.
 */
final class ArQuery
{
    public const SORTS = [
        'invoice'      => 'i.invoice_number',
        'customer'     => 'i.customer',
        'invoice_date' => 'i.invoice_date',
        'due'          => 'i.due_date',
        'amount'       => 'i.amount',
        'status'       => 'i.status',
        'company'      => 'c.code',
    ];

    /** Status filter values and their labels, in the order the register offers them. */
    public const STATUS_FILTERS = [
        ''            => 'All statuses',
        'collecting'  => 'Outstanding (issued, not paid)',
        'overdue'     => 'Overdue',
        'open'        => 'In approval',
        'draft'       => 'Draft',
        'approved'    => 'Approved, to be issued',
        'part_paid'   => 'Part paid',
        'paid'        => 'Paid',
        'returned'    => 'Returned',
        'rejected'    => 'Rejected',
        'cancelled'   => 'Cancelled',
    ];

    public function __construct(private ?AccessScope $scope = null)
    {
        $this->scope ??= new AccessScope();
    }

    /**
     * One invoice with names joined, or null when it does not exist or the user may not see its company (the two are
     * deliberately indistinguishable).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $uid, int $id): ?array
    {
        $inv = db_connect()->table('ar_invoices i')
            ->select('i.*, c.code AS company_code, c.name AS company_name, t.name AS type_name, u.display_name AS submitter_name')
            ->join('companies c', 'c.id = i.company_id')->join('ar_invoice_types t', 't.id = i.invoice_type_id')
            ->join('inv_users u', 'u.id = i.submitted_by', 'left')
            ->where('i.id', $id)->get()->getRowArray();

        return $inv === null || ! $this->scope->canViewCompany($uid, (int) $inv['company_id']) ? null : $inv;
    }

    /**
     * Outstanding, overdue and received amounts for the register's filters, ignoring the status filter so they can be
     * shown side by side. Outstanding = issued and part-paid balances; overdue = the part of that past its due date;
     * received = everything paid so far. Amounts are per currency and never added across currencies.
     *
     * @param array<string, mixed> $get
     *
     * @return array{outstanding: array{count: int, amount: array<string, float>}, overdue: array{count: int, amount: array<string, float>}, received: array{count: int, amount: array<string, float>}}
     */
    public function summary(int $uid, array $get, int $company = 0): array
    {
        [$builder] = $this->build($uid, $get, $company, true);

        $today = service('timezone')->todayFor();
        $open  = "i.status IN ('issued','part_paid')";
        $late  = "{$open} AND i.due_date < " . db_connect()->escape($today);
        $bal   = 'COALESCE(i.amount, 0) - COALESCE(i.amount_paid, 0)';

        $rows = $builder->select(
            "i.currency,
             SUM(CASE WHEN {$open} THEN 1 ELSE 0 END) AS out_n, SUM(CASE WHEN {$open} THEN {$bal} ELSE 0 END) AS out_total,
             SUM(CASE WHEN {$late} THEN 1 ELSE 0 END) AS late_n, SUM(CASE WHEN {$late} THEN {$bal} ELSE 0 END) AS late_total,
             SUM(CASE WHEN i.amount_paid > 0 THEN 1 ELSE 0 END) AS rec_n, SUM(COALESCE(i.amount_paid, 0)) AS rec_total",
            false,
        )->groupBy('i.currency')->get()->getResultArray();

        $out = ['outstanding' => ['count' => 0, 'amount' => []], 'overdue' => ['count' => 0, 'amount' => []], 'received' => ['count' => 0, 'amount' => []]];
        foreach ($rows as $r) {
            $cur = (string) ($r['currency'] ?: '—');
            foreach (['outstanding' => 'out', 'overdue' => 'late', 'received' => 'rec'] as $k => $p) {
                $n = (int) $r[$p . '_n'];
                if ($n === 0) {
                    continue;
                }
                $out[$k]['count']       += $n;
                $out[$k]['amount'][$cur] = round((float) $r[$p . '_total'], 2);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $get     status, q, age, date_from, date_to, min, max, sort, dir
     * @param int                  $company company id to narrow to, 0 for everything the user may see
     * @param bool                 $forTotals for aggregates: leaves out the status filter, the column list and the sorting
     *                                        (the caller adds its own select), every other filter and the AccessScope stay
     *
     * @return array{0: BaseBuilder, 1: array<string, mixed>} the builder and the cleaned filters
     */
    public function build(int $uid, array $get, int $company = 0, bool $forTotals = false): array
    {
        $status = (string) ($get['status'] ?? '');
        $status = array_key_exists($status, self::STATUS_FILTERS) ? $status : '';
        $q      = trim((string) ($get['q'] ?? ''));
        $age    = (string) ($get['age'] ?? '');
        $iso    = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
        $from   = $iso((string) ($get['date_from'] ?? ''));
        $to     = $iso((string) ($get['date_to'] ?? ''));
        $num    = static fn (string $v): string => is_numeric($v) ? $v : '';
        $min    = $num((string) ($get['min'] ?? ''));
        $max    = $num((string) ($get['max'] ?? ''));
        $sort   = array_key_exists((string) ($get['sort'] ?? ''), self::SORTS) ? (string) $get['sort'] : '';
        $dir    = strtolower((string) ($get['dir'] ?? '')) === 'asc' ? 'asc' : 'desc';

        $builder = db_connect()->table('ar_invoices i');
        if (! $forTotals) {
            $builder->select('i.*, c.code AS company_code, t.name AS type_name, u.display_name AS submitter');
        }
        $builder
            ->join('companies c', 'c.id = i.company_id')
            ->join('ar_invoice_types t', 't.id = i.invoice_type_id')
            ->join('inv_users u', 'u.id = i.submitted_by', 'left');
        $this->scope->restrict($builder, $uid, 'i.company_id');

        if ($company > 0) {
            $builder->where('i.company_id', $company);
        }
        if ($status !== '' && ! $forTotals) {
            match ($status) {
                'open'       => $builder->whereIn('i.status', ['in_approval']),
                'collecting' => $builder->whereIn('i.status', ReceivablesEngine::COLLECTING),
                'overdue'    => $this->overdue($builder),
                default      => $builder->where('i.status', $status),
            };
        }
        if (in_array($age, ReportService::AGE_FILTERS, true)) {
            ReportService::applyAge($builder, $age, 'i.due_date');
            $builder->whereIn('i.status', ReceivablesEngine::COLLECTING);
        } else {
            $age = '';
        }
        if ($q !== '') {
            $builder->groupStart()->like('i.invoice_number', $q)->orLike('i.customer', $q)->orLike('i.customer_po_reference', $q)->groupEnd();
        }
        if ($from !== '') {
            $builder->where('i.invoice_date >=', $from);
        }
        if ($to !== '') {
            $builder->where('i.invoice_date <=', $to);
        }
        if ($min !== '') {
            $builder->where('i.amount >=', $min);
        }
        if ($max !== '') {
            $builder->where('i.amount <=', $max);
        }

        if ($forTotals) {
            // no ordering: it would clash with the caller's GROUP BY
        } elseif ($sort !== '') {
            $builder->orderBy(self::SORTS[$sort], $dir)->orderBy('i.id', 'DESC');
        } else {
            $builder->orderBy('i.id', 'DESC');
        }

        return [$builder, [
            'company' => $company, 'status' => $status, 'q' => $q, 'age' => $age,
            'date_from' => $from, 'date_to' => $to, 'min' => $min, 'max' => $max, 'sort' => $sort, 'dir' => $dir,
        ]];
    }

    private function overdue(BaseBuilder $builder): void
    {
        $builder->whereIn('i.status', ReceivablesEngine::COLLECTING);
        ReportService::applyAge($builder, 'overdue', 'i.due_date');
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Invoices;

use App\Core\Tenancy\AccessScope;

use CodeIgniter\Database\BaseBuilder;

/**
 * The invoice register query: filters, sorting and the AccessScope restriction in one place, shared
 * by the register screen, its CSV export and the API. It knows nothing about the session or the
 * request; the caller decides which company (if any) to narrow to.
 */
final class InvoiceQuery
{
    public const SORTS = [
        'invoice'      => 'i.invoice_number',
        'supplier'     => 'i.supplier',
        'invoice_date' => 'i.invoice_date',
        'due'          => 'i.due_date',
        'amount'       => 'i.amount',
        'status'       => 'i.status',
        'company'      => 'c.code',
    ];

    public function __construct(private ?AccessScope $scope = null)
    {
        $this->scope ??= new AccessScope();
    }

    /**
     * One invoice with names joined, or null when it does not exist or the user may not see its
     * company (the two are deliberately indistinguishable).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $uid, int $id): ?array
    {
        $inv = db_connect()->table('inv_invoices i')
            ->select('i.*, c.code AS company_code, c.name AS company_name, t.name AS type_name, u.display_name AS submitter_name')
            ->join('companies c', 'c.id = i.company_id')->join('inv_invoice_types t', 't.id = i.invoice_type_id')
            ->join('inv_users u', 'u.id = i.submitted_by', 'left')
            ->where('i.id', $id)->get()->getRowArray();

        return $inv === null || ! $this->scope->canViewCompany($uid, (int) $inv['company_id']) ? null : $inv;
    }

    /**
     * Paid, unpaid and total amounts for the register's filters, ignoring the status filter so the three
     * can be shown side by side. Paid = the invoice reached its pay step and was marked paid; unpaid = outstanding,
     * which includes a processed invoice still waiting to be paid. Amounts are per currency and never added across currencies.
     *
     * @param array<string, mixed> $get
     *
     * @return array{paid: array{count: int, amount: array<string, float>}, unpaid: array{count: int, amount: array<string, float>}, total: array{count: int, amount: array<string, float>}}
     */
    public function paidUnpaid(int $uid, array $get, int $company = 0): array
    {
        [$builder] = $this->build($uid, $get, $company, true);

        $outstanding = "'" . implode("','", ReportService::OUTSTANDING) . "'";
        $isPaid      = "i.status = 'paid'";
        $isUnpaid    = 'i.status IN (' . $outstanding . ") OR (i.status = 'processed' AND i.current_step_order IS NOT NULL)";

        $rows = $builder->select(
            "i.currency,
             SUM(CASE WHEN {$isPaid} THEN 1 ELSE 0 END) AS paid_n, SUM(CASE WHEN {$isPaid} THEN COALESCE(i.amount, 0) ELSE 0 END) AS paid_total,
             SUM(CASE WHEN {$isUnpaid} THEN 1 ELSE 0 END) AS unpaid_n, SUM(CASE WHEN {$isUnpaid} THEN COALESCE(i.amount, 0) ELSE 0 END) AS unpaid_total",
            false,
        )->groupBy('i.currency')->get()->getResultArray();

        $out = ['paid' => ['count' => 0, 'amount' => []], 'unpaid' => ['count' => 0, 'amount' => []], 'total' => ['count' => 0, 'amount' => []]];
        foreach ($rows as $r) {
            $cur = (string) ($r['currency'] ?: '—');
            foreach (['paid', 'unpaid'] as $k) {
                $n = (int) $r[$k . '_n'];
                if ($n === 0) {
                    continue;
                }
                $amt = round((float) $r[$k . '_total'], 2);
                $out[$k]['count']        += $n;
                $out[$k]['amount'][$cur]  = $amt;
                $out['total']['count']   += $n;
                $out['total']['amount'][$cur] = round(($out['total']['amount'][$cur] ?? 0.0) + $amt, 2);
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

        $builder = db_connect()->table('inv_invoices i');
        if (! $forTotals) {
            $builder->select('i.*, c.code AS company_code, t.name AS type_name, u.display_name AS submitter');
        }
        $builder
            ->join('companies c', 'c.id = i.company_id')
            ->join('inv_invoice_types t', 't.id = i.invoice_type_id')
            ->join('inv_users u', 'u.id = i.submitted_by', 'left');
        $this->scope->restrict($builder, $uid, 'i.company_id');

        if ($company > 0) {
            $builder->where('i.company_id', $company);
        }
        if ($status !== '' && ! $forTotals) {
            match ($status) {
                'open'        => $builder->whereIn('i.status', ['in_approval', 'approved']),
                'outstanding' => ReportService::applyOutstanding($builder),
                'completed'   => $builder->whereIn('i.status', ['processed', 'paid']),   // the old name for finished
                default       => $builder->where('i.status', $status),
            };
        }
        if (in_array($age, ReportService::AGE_FILTERS, true)) {
            ReportService::applyAge($builder, $age, 'i.due_date');
        } else {
            $age = '';
        }
        if ($q !== '') {
            $builder->groupStart()->like('i.invoice_number', $q)->orLike('i.supplier', $q)->orLike('i.po_reference', $q)->groupEnd();
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
}

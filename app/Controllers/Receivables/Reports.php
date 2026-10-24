<?php

declare(strict_types=1);

namespace App\Controllers\Receivables;

use App\Controllers\BaseController;
use App\Core\Payables\Csv;
use App\Core\Payables\ReportService;
use App\Core\Receivables\ArAudit;
use App\Core\Receivables\ArReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Customer invoice reports for everything the signed-in user may see: outstanding and ageing, outstanding by customer,
 * and collections month by month. Thin: ArReportService does the work and applies AccessScope.
 */
class Reports extends BaseController
{
    public function index(): string
    {
        $uid     = (int) auth()->id();
        $reports = new ArReportService();
        $filters = $this->filters($reports, $uid);

        return view('receivables/reports/ageing', [
            'title' => 'Customer invoice reports',
            'agg'   => ArReportService::aggregate($reports->outstanding($uid, $filters)),
            'stats' => $reports->paymentStats($uid, $filters),
        ] + $this->filterData($reports, $uid, $filters));
    }

    public function customers(): string
    {
        $uid     = (int) auth()->id();
        $reports = new ArReportService();
        $filters = $this->filters($reports, $uid);

        return view('receivables/reports/customers', [
            'title'     => 'Outstanding by customer',
            'customers' => ArReportService::outstandingByCustomer($reports->outstanding($uid, $filters)),
        ] + $this->filterData($reports, $uid, $filters));
    }

    public function collections(): string
    {
        $uid     = (int) auth()->id();
        $reports = new ArReportService();
        $filters = $this->filters($reports, $uid);
        $data    = $reports->collectionsByMonth($uid, $filters);

        $currency = strtoupper((string) $this->request->getGet('currency'));
        $currency = in_array($currency, $data['currencies'], true) ? $currency : ($data['currencies'][0] ?? '');

        return view('receivables/reports/collections', [
            'title'    => 'Collections',
            'months'   => $data['months'],
            'currency' => $currency,
            'currencies' => $data['currencies'],
            'stats'    => $reports->paymentStats($uid, $filters),
        ] + $this->filterData($reports, $uid, $filters));
    }

    /** Every outstanding invoice in the user's view as a spreadsheet, most overdue first. */
    public function outstandingCsv(): ResponseInterface
    {
        $uid     = (int) auth()->id();
        $reports = new ArReportService();
        $rows    = $reports->outstanding($uid, $this->filters($reports, $uid));

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['company_code'], $r['company_name'], $r['group_name'] ?? '', $r['invoice_number'], $r['customer_code'] ?? '', $r['customer'],
                $r['amount'] !== null ? (float) $r['amount'] : '', (float) $r['amount_paid'], (float) $r['balance'], $r['currency'], $r['due_date'],
                $r['days_past_due'], ReportService::BANDS[$r['band']][0], ReportService::isCritical((int) $r['days_past_due']) ? 'Yes' : 'No', ar_status_label((string) $r['status']),
            ];
        }

        return $this->csv('outstanding-customer-invoices', ['Company code', 'Company', 'Sub-group', 'Invoice number', 'Customer code', 'Customer', 'Amount', 'Paid', 'Still owed', 'Currency', 'Due date', 'Days past due', 'Age band', 'Critical (61+)', 'Status'], $out);
    }

    /** Outstanding by customer as a spreadsheet: one row per customer and currency. */
    public function customersCsv(): ResponseInterface
    {
        $uid     = (int) auth()->id();
        $reports = new ArReportService();
        $rows    = ArReportService::outstandingByCustomer($reports->outstanding($uid, $this->filters($reports, $uid)));

        $out = [];
        foreach ($rows as $r) {
            $n = $r['count'];   // an invoice count covers every currency, so it is given once per customer, not per currency
            foreach ($r['amount'] as $cur => $amt) {
                $out[] = [$r['code'], $r['customer'], $n, $cur, $amt, $r['overdue_amount'][$cur] ?? 0.0, $r['overdue'], $r['critical'], $r['worst']];
                $n     = 0;
            }
        }

        return $this->csv('outstanding-by-customer', ['Customer code', 'Customer', 'Outstanding invoices (counted once per customer)', 'Currency', 'Still owed', 'Of which overdue', 'Overdue invoices', 'Critical (61+)', 'Most days past due'], $out);
    }

    /** Collections month by month as a spreadsheet: one row per month and currency. */
    public function collectionsCsv(): ResponseInterface
    {
        $uid     = (int) auth()->id();
        $reports = new ArReportService();
        $data    = $reports->collectionsByMonth($uid, $this->filters($reports, $uid));

        $out = [];
        foreach ($data['months'] as $m) {
            foreach ($data['currencies'] as $cur) {
                if (! isset($m['invoiced'][$cur]) && ! isset($m['received'][$cur])) {
                    continue;
                }
                $out[] = [$m['month'], $cur, $m['invoiced'][$cur] ?? 0.0, $m['received'][$cur] ?? 0.0];
            }
        }

        return $this->csv('collections-by-month', ['Month', 'Currency', 'Invoiced (issued that month)', 'Received (net of reversals)'], $out);
    }

    /**
     * @param list<string>                  $headers
     * @param list<list<scalar|null>>       $rows
     */
    private function csv(string $name, array $headers, array $rows): ResponseInterface
    {
        (new ArAudit())->log('ar.report_exported', (int) auth()->id(), null, null, ['report' => $name, 'rows' => count($rows)]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(Csv::build($headers, $rows));
    }

    /**
     * What every report page needs for its filter bar.
     *
     * @param array{company?: int, group?: int} $filters
     *
     * @return array<string, mixed>
     */
    private function filterData(ArReportService $reports, int $uid, array $filters): array
    {
        $companies = $reports->visibleCompanies($uid);
        $groupIds  = array_values(array_unique(array_filter(array_column($companies, 'group_id'))));

        return [
            'f'         => $filters,
            'companies' => $companies,
            'groups'    => $groupIds === [] ? [] : db_connect()->table('company_groups')->select('id, name')->whereIn('id', $groupIds)->orderBy('name')->get()->getResultArray(),
        ];
    }

    /**
     * @return array{company?: int, group?: int}
     */
    private function filters(ArReportService $reports, int $uid): array
    {
        $out     = [];
        $company = $this->scopedCompany((int) $this->request->getGet('company'));
        $group   = (int) $this->request->getGet('group');

        // Only accept a company the user can actually see. The service restricts again regardless.
        if ($company > 0 && in_array($company, array_map('intval', array_column($reports->visibleCompanies($uid), 'id')), true)) {
            $out['company'] = $company;
        }
        if ($group > 0) {
            $out['group'] = $group;
        }

        return $out;
    }
}

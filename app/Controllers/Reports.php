<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Payables\InvoiceAudit;

use App\Core\Payables\Csv;
use App\Core\Payables\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Outstanding invoices, aging and totals for everything the signed-in user may see.
 */
class Reports extends BaseController
{
    public function index(): string
    {
        $uid     = (int) auth()->id();
        $reports = new ReportService();
        $filters = $this->filters($reports, $uid);

        $companies = $reports->visibleCompanies($uid);
        $groupIds  = array_values(array_unique(array_filter(array_column($companies, 'group_id'))));

        return view('payables/reports/index', [
            'title'     => 'Reports',
            'agg'       => ReportService::aggregate($reports->outstanding($uid, $filters)),
            'counts'    => $reports->countsByStatus($uid, $filters),
            'stats'     => $reports->completionStats($uid, $filters),
            'trend'     => $reports->monthlyTrend($uid, $filters),
            'f'         => $filters,
            'companies' => $companies,
            'groups'    => $groupIds === [] ? [] : db_connect()->table('company_groups')->select('id, name')->whereIn('id', $groupIds)->orderBy('name')->get()->getResultArray(),
            'link'      => array_filter(['company' => $filters['company'] ?? 0, 'group' => 0]),
        ]);
    }

    /**
     * Every outstanding invoice in the user's view as a spreadsheet, most overdue first.
     */
    public function outstandingCsv(): ResponseInterface
    {
        $uid     = (int) auth()->id();
        $reports = new ReportService();
        $rows    = $reports->outstanding($uid, $this->filters($reports, $uid));

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['company_code'], $r['company_name'], $r['group_name'] ?? '', $r['invoice_number'], $r['supplier'],
                $r['amount'] !== null ? (float) $r['amount'] : '', $r['currency'], $r['due_date'],
                $r['days_past_due'], ReportService::BANDS[$r['band']][0], ReportService::isCritical((int) $r['days_past_due']) ? 'Yes' : 'No',
                ucfirst(str_replace('_', ' ', (string) $r['status'])), $r['step_label'], $r['days_waiting'],
            ];
        }

        (new InvoiceAudit())->log('report.exported', $uid, null, null, ['report' => 'outstanding', 'rows' => count($out)]);

        $csv = Csv::build(
            ['Company code', 'Company', 'Sub-group', 'Invoice number', 'Supplier', 'Amount', 'Currency', 'Due date', 'Days past due', 'Age band', 'Critical (61+)', 'Status', 'With', 'Days waiting'],
            $out,
        );

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="outstanding-invoices-' . date('Y-m-d') . '.csv"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($csv);
    }

    /**
     * Outstanding invoices by supplier, and invoices submitted per supplier by week, month, quarter or year.
     */
    public function suppliers(): string
    {
        $uid     = (int) auth()->id();
        $reports = new ReportService();
        $filters = $this->filters($reports, $uid);
        [$type, $anchor] = $this->periodChoice();

        $companies = $reports->visibleCompanies($uid);
        $groupIds  = array_values(array_unique(array_filter(array_column($companies, 'group_id'))));

        return view('payables/reports/suppliers', [
            'title'       => 'Reports by supplier',
            'outstanding' => ReportService::outstandingBySupplier($reports->outstanding($uid, $filters)),
            'totals'      => $reports->totalsBySupplier($uid, $filters, $type, $anchor),
            'codes'       => $reports->supplierCodes(),
            'type'        => $type,
            'anchor'      => $anchor,
            'f'           => $filters,
            'companies'   => $companies,
            'groups'      => $groupIds === [] ? [] : db_connect()->table('company_groups')->select('id, name')->whereIn('id', $groupIds)->orderBy('name')->get()->getResultArray(),
        ]);
    }

    /**
     * CSV of either supplier report: report=outstanding, or report=totals with period and date.
     */
    public function suppliersCsv(): ResponseInterface
    {
        $uid     = (int) auth()->id();
        $reports = new ReportService();
        $filters = $this->filters($reports, $uid);
        $codes   = $reports->supplierCodes();
        $code    = static fn (string $name): string => $codes[mb_strtolower($name)] ?? '';

        $out = [];
        if ((string) $this->request->getGet('report') === 'outstanding') {
            $name = 'outstanding-by-supplier';
            foreach (ReportService::outstandingBySupplier($reports->outstanding($uid, $filters)) as $r) {
                $out[] = [$code($r['supplier']), $r['supplier'], $r['count'], ReportService::formatAmounts($r['amount']), $r['overdue'], $r['critical'], $r['worst']];
            }
            $csv = Csv::build(['Supplier code', 'Supplier', 'Outstanding invoices', 'Amount', 'Overdue', 'Critical (61+)', 'Most days past due'], $out);
        } else {
            [$type, $anchor] = $this->periodChoice();
            $name            = 'invoices-by-supplier-' . $type;
            $totals          = $reports->totalsBySupplier($uid, $filters, $type, $anchor);
            foreach ($totals['suppliers'] as $r) {
                foreach ($totals['periods'] as $i => $p) {
                    $cell = $r['cells'][$i];
                    if ($cell['count'] === 0) {
                        continue;
                    }
                    $n = $cell['count'];   // an invoice count covers every currency, so it is given once per period, not per currency
                    foreach ($cell['amount'] as $cur => $amt) {
                        $out[] = [$code($r['supplier']), $r['supplier'], $p['label'], $p['from'], $p['to'], $n, $cur, $amt];
                        $n     = 0;
                    }
                }
            }
            $csv = Csv::build(['Supplier code', 'Supplier', 'Period', 'From', 'To', 'Invoices (counted once per period)', 'Currency', 'Amount'], $out);
        }

        (new InvoiceAudit())->log('report.exported', $uid, null, null, ['report' => $name, 'rows' => count($out)]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($csv);
    }

    /**
     * @return array{0: string, 1: string} period type (week, month, quarter, year) and the date its last period contains
     */
    private function periodChoice(): array
    {
        $type = (string) $this->request->getGet('period');
        $type = isset(ReportService::SUPPLIER_PERIODS[$type]) ? $type : 'month';

        $date   = (string) $this->request->getGet('date');
        $parsed = date_create_from_format('Y-m-d', $date);
        $anchor = $parsed !== false && $parsed->format('Y-m-d') === $date && $date <= service('timezone')->todayFor() ? $date : service('timezone')->todayFor();

        return [$type, $anchor];
    }

    /**
     * @return array{company?: int, group?: int}
     */
    private function filters(ReportService $reports, int $uid): array
    {
        $out     = [];
        $company = (int) $this->request->getGet('company');
        $group   = (int) $this->request->getGet('group');

        // Nothing chosen on this page: fall back to the company picked in the top bar (it only narrows).
        $company = $this->scopedCompany($company);

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

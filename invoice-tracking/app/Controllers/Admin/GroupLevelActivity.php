<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AuditLogger;
use App\Services\Csv;
use App\Services\GroupLevelReport;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accountability for group-level access. Administrators only (route filter).
 */
class GroupLevelActivity extends BaseController
{
    private const PER_PAGE = 50;

    public function index(): string
    {
        $report  = new GroupLevelReport();
        $filters = $this->filters();
        $page    = max(1, (int) $this->request->getGet('page'));
        $total   = $report->count($filters);

        return view('admin/reports/group_level', [
            'title'     => 'Group-level activity',
            'rows'      => $report->actions($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'byUser'    => $report->byUser($filters),
            'changes'   => $report->boundaryChanges(30),
            'actors'    => $report->actors(),
            'companies' => db_connect()->table('companies')->select('id, code')->orderBy('code')->get()->getResultArray(),
            'f'         => $filters,
            'total'     => $total,
            'page'      => $page,
            'pages'     => (int) max(1, ceil($total / self::PER_PAGE)),
        ]);
    }

    public function csv(): ResponseInterface
    {
        $rows = (new GroupLevelReport())->actions($this->filters(), 100000);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['acted_at'], $r['actor'], $r['action'], $r['company_code'], $r['invoice_number'], $r['supplier'],
                $r['amount'] !== null ? (float) $r['amount'] : '', $r['currency'], $r['step_label'], $r['on_behalf_name'] ?? '',
                $r['reason'] ?? '', ucfirst(str_replace('_', ' ', (string) $r['status'])),
            ];
        }

        (new AuditLogger())->log('report.exported', (int) auth()->id(), null, null, ['report' => 'group_level_activity', 'rows' => count($out)]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="group-level-activity-' . date('Y-m-d') . '.csv"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(Csv::build(
                ['When', 'Group-level user', 'Action', 'Company', 'Invoice number', 'Supplier', 'Amount', 'Currency', 'Step', 'On behalf of', 'Reason', 'Invoice status now'],
                $out,
            ));
    }

    /**
     * @return array{user?: int, company?: int, from?: string, to?: string}
     */
    private function filters(): array
    {
        $f    = [];
        $user = (int) $this->request->getGet('user');
        $co   = (int) $this->request->getGet('company');
        $from = trim((string) $this->request->getGet('from'));
        $to   = trim((string) $this->request->getGet('to'));

        if ($user > 0) {
            $f['user'] = $user;
        }
        if ($co > 0) {
            $f['company'] = $co;
        }
        if ($from !== '' && GroupLevelReport::isDate($from)) {
            $f['from'] = $from;
        }
        if ($to !== '' && GroupLevelReport::isDate($to)) {
            $f['to'] = $to;
        }

        return $f;
    }
}

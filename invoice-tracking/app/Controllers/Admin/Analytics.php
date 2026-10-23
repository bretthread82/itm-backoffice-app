<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Analytics\AnalyticsService;
use App\Services\AuditLogger;
use App\Services\Csv;
use App\Services\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Long-term trends from the separate analytics database (managers and administrators). The data is a
 * derived copy refreshed nightly; if that database is unavailable these screens say so and nothing else is affected.
 */
class Analytics extends BaseController
{
    private const STEP_VIEWS = ['type' => 'By step type', 'assignee' => 'By assigned role / team', 'actor' => 'By person who acted'];

    public function index(): string
    {
        return $this->page('analytics/volume', 'Volume & spend', false, function (AnalyticsService $svc, int $uid, array $f): array {
            $v = $svc->volume($uid, $f);

            $counts = ['labels' => $v['periods'], 'submitted' => [], 'processed' => [], 'paid' => [], 'prior' => []];
            $money  = [];
            foreach ($v['periods'] as $p) {
                $prior = AnalyticsService::priorYear($p);
                foreach (['submitted', 'processed', 'paid'] as $m) {
                    $counts[$m][] = $this->sum($v['rows'][$p] ?? [], $m, 0);
                }
                $counts['prior'][] = $this->sum($v['rows'][$prior] ?? [], 'submitted', 0);
                foreach ($v['currencies'] as $cur) {
                    $money[$cur]['labels'][]    = $p;
                    $money[$cur]['submitted'][] = $v['rows'][$p][$cur]['submitted'][1] ?? 0;
                    $money[$cur]['paid'][]      = $v['rows'][$p][$cur]['paid'][1] ?? 0;
                    $money[$cur]['prior'][]     = $v['rows'][$prior][$cur]['submitted'][1] ?? 0;
                }
            }

            return ['v' => $v, 'counts' => $counts, 'money' => $money, 'top' => $svc->topSuppliers($uid, $f)];
        });
    }

    public function cycleTime(): string
    {
        $by = $this->stepView();

        return $this->page('analytics/cycle', 'Cycle time', false, static function (AnalyticsService $svc, int $uid, array $f) use ($by): array {
            return ['c' => $svc->cycle($uid, $f), 'steps' => $svc->stepStats($uid, $f, $by), 'by' => $by, 'stepViews' => self::STEP_VIEWS];
        });
    }

    public function backlog(): string
    {
        $currency = strtoupper(substr((string) $this->request->getGet('currency'), 0, 3));

        return $this->page('analytics/backlog', 'Backlog history', true, static function (AnalyticsService $svc, int $uid, array $f) use ($currency): array {
            $b = $svc->backlog($uid, $f);

            return ['b' => $b, 'currency' => in_array($currency, $b['currencies'], true) ? $currency : ($b['currencies'][0] ?? '')];
        });
    }

    // ------------------------------------------------------------------ CSV

    public function volumeCsv(): ResponseInterface
    {
        [$svc, $uid, $f] = $this->context(false);
        $v   = $svc->volume($uid, $f);
        $out = [];
        foreach ($v['periods'] as $p) {
            foreach ($v['rows'][$p] ?? [] as $cur => $m) {
                $out[] = [$p, $cur, $m['submitted'][0] ?? 0, $m['submitted'][1] ?? 0, $m['processed'][0] ?? 0, $m['processed'][1] ?? 0, $m['paid'][0] ?? 0, $m['paid'][1] ?? 0];
            }
        }

        return $this->csv('analytics-volume', ['Period', 'Currency', 'Submitted', 'Submitted amount', 'Processed', 'Processed amount', 'Paid', 'Paid amount'], $out, $uid);
    }

    public function cycleCsv(): ResponseInterface
    {
        [$svc, $uid, $f] = $this->context(false);
        $c   = $svc->cycle($uid, $f);
        $out = [];
        foreach ($c['periods'] as $p) {
            $r = $c['rows'][$p] ?? null;
            if ($r !== null) {
                $out[] = [$p, $r['submitted'] ?? 0, $r['done'] ?? 0, $r['avg_processed'] ?? '', $r['avg_paid'] ?? '', $r['on_time_pct'] ?? '', $r['returned_pct'] ?? '', $r['resubmitted_pct'] ?? '', $r['rejected_pct'] ?? '', $r['overridden_pct'] ?? '', $r['sla_breach_pct'] ?? ''];
            }
        }

        return $this->csv('analytics-cycle-time', ['Period', 'Submitted', 'Processed', 'Avg days to processed', 'Avg days to paid', 'On time %', 'Returned %', 'Resubmitted %', 'Rejected %', 'Group-level override %', 'Step SLA breached %'], $out, $uid);
    }

    public function backlogCsv(): ResponseInterface
    {
        [$svc, $uid, $f] = $this->context(true);
        $out = [];
        foreach ($svc->backlog($uid, $f)['points'] as $pt) {
            foreach ($pt['by'] as $cur => $bands) {
                foreach ($bands as $band => [$n, $amt]) {
                    $out[] = [$pt['date'], $pt['estimated'] ? 'Estimated' : 'Recorded', $cur, ReportService::BANDS[$band][0] ?? $band, $n, $amt];
                }
            }
        }

        return $this->csv('analytics-backlog', ['Date', 'Basis', 'Currency', 'Age band', 'Invoices', 'Amount'], $out, $uid);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param callable(AnalyticsService, int, array<string, mixed>): array<string, mixed> $data
     */
    private function page(string $view, string $title, bool $allowDay, callable $data): string
    {
        $uid     = (int) auth()->id();
        $reports = new ReportService();
        $f       = AnalyticsService::normalise($this->request->getGet(), null, $allowDay);

        $companies = $reports->visibleCompanies($uid);
        $groupIds  = array_values(array_unique(array_filter(array_column($companies, 'group_id'))));
        $shared    = [
            'title'     => $title,
            'f'         => $f,
            'allowDay'  => $allowDay,
            'companies' => $companies,
            'groups'    => $groupIds === [] ? [] : db_connect()->table('company_groups')->select('id, name')->whereIn('id', $groupIds)->orderBy('name')->get()->getResultArray(),
            'status'    => ['last_run' => null, 'has_data' => false, 'since' => null],
            'types'     => [],
            'error'     => false,
        ];

        try {
            $svc              = new AnalyticsService();
            $shared['status'] = $svc->status();
            $shared['types']  = $svc->typeNames($uid);
            $shared          += $data($svc, $uid, $f);
        } catch (\Throwable $e) {
            log_message('error', 'Analytics unavailable: ' . $e->getMessage());
            $shared['error'] = true;
        }

        return view($view, $shared);
    }

    /**
     * @return array{0: AnalyticsService, 1: int, 2: array<string, mixed>}
     */
    private function context(bool $allowDay): array
    {
        return [new AnalyticsService(), (int) auth()->id(), AnalyticsService::normalise($this->request->getGet(), null, $allowDay)];
    }

    private function stepView(): string
    {
        $by = (string) $this->request->getGet('by');

        return isset(self::STEP_VIEWS[$by]) ? $by : 'type';
    }

    /**
     * Invoice counts are not money, so they may be added across currencies; amounts never are.
     *
     * @param array<string, array<string, array{0: int, 1: float}>> $byCurrency
     */
    private function sum(array $byCurrency, string $metric, int $index): int|float
    {
        $t = 0;
        foreach ($byCurrency as $m) {
            $t += $m[$metric][$index] ?? 0;
        }

        return $t;
    }

    /**
     * @param list<string>             $head
     * @param list<list<mixed>>        $rows
     */
    private function csv(string $name, array $head, array $rows, int $uid): ResponseInterface
    {
        (new AuditLogger())->log('report.exported', $uid, null, null, ['report' => $name, 'rows' => count($rows)]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(Csv::build($head, $rows));
    }
}

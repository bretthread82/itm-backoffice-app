<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Payables\ActivityFeed;
use App\Core\Payables\ApprovalQueue;
use App\Core\Payables\ReportService;

/**
 * The invoices overview: KPIs, ageing, trend and recent activity for the companies the user may see,
 * narrowed by the company switcher.
 */
class PayablesOverview extends BaseController
{
    public function index(): string
    {
        $userId  = (int) auth()->id();
        $scope   = service('scope');
        $queue   = new ApprovalQueue();
        $reports = new ReportService();
        $db      = db_connect();

        $companies = $reports->visibleCompanies($userId);
        $company   = $this->scopedCompany(0);
        $filters   = $company > 0 ? ['company' => $company] : [];

        $rows = $reports->outstanding($userId, $filters);
        $agg  = ReportService::aggregate($rows);
        $ytd  = $reports->invoicedYearToDate($userId, $filters);

        // Change against the same period last year, in the currency with the most invoiced this year.
        $change = null;

        if ($ytd['year'] !== []) {
            arsort($ytd['year']);
            $cur = (string) array_key_first($ytd['year']);

            if (($ytd['prior'][$cur] ?? 0.0) > 0) {
                $change = round(($ytd['year'][$cur] - $ytd['prior'][$cur]) / $ytd['prior'][$cur] * 100);
            }
        }

        $inApproval = array_filter($rows, static fn (array $r): bool => in_array($r['status'], ['in_approval', 'approved'], true));
        $companyName = null;

        foreach ($companies as $c) {
            if ($company === (int) $c['id']) {
                $companyName = (string) $c['name'];
            }
        }

        return view('payables/overview', [
            'title'       => 'Invoice overview',
            'companies'   => $companies,
            'companyName' => $companyName,
            'groupLevel'  => $scope->isGroupLevel($userId),
            'waiting'     => $queue->pendingFor($userId),
            'mine'        => $queue->mine($userId),
            'canSubmit'   => $db->table('user_companies')->where(['user_id' => $userId, 'can_submit' => 1])->countAllResults() > 0,
            'agg'         => $agg,
            'ytd'         => $ytd['year'],
            'ytdChange'   => $change,
            'awaiting'    => ReportService::aggregate(array_values($inApproval))['total'],
            'dueSoon'     => ReportService::dueWithin($rows, 30),
            'over30'      => count(array_filter($rows, static fn (array $r): bool => (int) $r['days_past_due'] > 30)),
            'trend'       => $reports->monthlyTrend($userId, $filters),
            'activity'    => (new ActivityFeed())->recent($userId, $company, 20),
            'link'        => $company > 0 ? ['company' => $company] : [],
        ]);
    }
}

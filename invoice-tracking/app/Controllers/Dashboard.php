<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccessScope;
use App\Services\ActivityFeed;
use App\Services\ApprovalQueue;
use App\Services\LogoStore;
use App\Services\ReportService;

class Dashboard extends BaseController
{
    public function index(): string
    {
        $userId  = (int) auth()->id();
        $scope   = new AccessScope();
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
        $awaiting   = ReportService::aggregate(array_values($inApproval))['total'];

        $companyName = null;
        foreach ($companies as $c) {
            if ($company === (int) $c['id']) {
                $companyName = (string) $c['name'];
            }
        }

        $trend = $reports->monthlyTrend($userId, $filters);

        // Logo: the selected company's (or a lone company's), else the group logo, else none.
        $logoCompany = $company > 0 ? $company : (! $scope->isGroupLevel($userId) && count($companies) === 1 ? (int) $companies[0]['id'] : 0);
        $logoUrl     = null;
        $logoAlt     = 'Maloney Group';
        if ($logoCompany > 0) {
            $row = $db->table('companies')->select('name, logo_file')->where('id', $logoCompany)->get()->getRowArray();
            if ($row !== null && LogoStore::validName($row['logo_file']) !== null) {
                $logoUrl = site_url('logo/company/' . $logoCompany);
                $logoAlt = (string) $row['name'];
            }
        }
        if ($logoUrl === null && LogoStore::validName((string) setting('AppSettings.groupLogoFile')) !== null) {
            $logoUrl = site_url('logo/group');
            $logoAlt = 'Maloney Group';
        }

        return view('dashboard/index', [
            'title'       => 'Dashboard',
            'companies'   => $companies,
            'companyName' => $companyName,
            'groupLevel'  => $scope->isGroupLevel($userId),
            'waiting'     => $queue->pendingFor($userId),
            'mine'        => $queue->mine($userId),
            'canSubmit'   => $db->table('user_companies')->where(['user_id' => $userId, 'can_submit' => 1])->countAllResults() > 0,
            'agg'         => $agg,
            'ytd'         => $ytd['year'],
            'ytdChange'   => $change,
            'awaiting'    => $awaiting,
            'dueSoon'     => ReportService::dueWithin($rows, 30),
            'over30'      => count(array_filter($rows, static fn (array $r): bool => (int) $r['days_past_due'] > 30)),
            'trend'       => $trend,
            'activity'    => (new ActivityFeed())->recent($userId, $company, 20),
            'link'        => $company > 0 ? ['company' => $company] : [],
            'logoUrl'     => $logoUrl,
            'logoAlt'     => $logoAlt,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Dashboard\DashboardTab as DashboardTabContract;
use App\Core\Payables\ReportService;
use App\Core\Tenancy\AccessScope;

/**
 * The receivables tab on the dashboard: what is waiting on the user, what came back to them, what is overdue to chase,
 * what falls due soon and what was received lately. Everything is limited to what the user may see through AccessScope.
 */
final class ArDashboardTab implements DashboardTabContract
{
    /** Waiting on the user plus sent back to them. */
    public function badge(int $userId): int
    {
        $counts = ArNavCounts::for($userId);

        return $counts['waiting'] + $counts['returned'];
    }

    public function data(int $userId): array
    {
        $queue = new ArApprovalQueue();
        $scope = new AccessScope();

        return [
            'waiting'     => $queue->pendingFor($userId),
            'mine'        => $queue->mine($userId),
            'overdue'     => $queue->overdueFor($userId),
            'summary'     => (new ArQuery($scope))->summary($userId, []),
            'dueSoon'     => $this->dueWithin($userId, $scope, 7),
            'received'    => $this->receivedSince($userId, $scope, 7),
            'subscriptions' => $this->subscriptionsDue($userId, $scope, 14),
            'canSubmit'   => $scope->companiesWith($userId, 'receivables.submit') !== [],
            'activity'    => (new ArActivityFeed())->recent($userId, 0, 6),
        ];
    }

    /**
     * Balances still owed on issued and part-paid invoices that fall due in the next $days days (today included),
     * per currency.
     *
     * @return array{count: int, amount: array<string, float>}
     */
    private function dueWithin(int $userId, AccessScope $scope, int $days): array
    {
        $today = service('timezone')->todayFor();
        $b     = db_connect()->table('ar_invoices i')
            ->select('i.currency, COUNT(*) AS n, SUM(COALESCE(i.amount, 0) - COALESCE(i.amount_paid, 0)) AS total')
            ->whereIn('i.status', ReceivablesEngine::COLLECTING)
            ->where('i.due_date >=', $today)->where('i.due_date <=', date('Y-m-d', strtotime($today . ' +' . $days . ' days')))
            ->groupBy('i.currency');
        $scope->restrict($b, $userId, 'i.company_id');

        return $this->bucket($b->get()->getResultArray());
    }

    /**
     * Active subscriptions that will be billed in the next $days days (today included), per currency.
     *
     * @return array{count: int, amount: array<string, float>}
     */
    private function subscriptionsDue(int $userId, AccessScope $scope, int $days): array
    {
        $today = service('timezone')->todayFor();
        $b     = db_connect()->table('ar_subscriptions s')
            ->select('s.currency, COUNT(*) AS n, SUM(s.amount) AS total')
            ->where('s.status', 'active')
            ->where('s.next_bill_date <=', date('Y-m-d', strtotime($today . ' +' . $days . ' days')))
            ->groupBy('s.currency');
        $scope->restrict($b, $userId, 's.company_id');

        return $this->bucket($b->get()->getResultArray());
    }

    /**
     * Money received in the last $days days, net of reversals, per currency.
     *
     * @return array{count: int, amount: array<string, float>}
     */
    private function receivedSince(int $userId, AccessScope $scope, int $days): array
    {
        $today = service('timezone')->todayFor();
        $b     = db_connect()->table('ar_payments p')
            ->select('i.currency, COUNT(DISTINCT p.invoice_id) AS n, SUM(p.amount) AS total')
            ->join('ar_invoices i', 'i.id = p.invoice_id')
            ->where('p.received_date >=', date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days')))
            ->groupBy('i.currency');
        $scope->restrict($b, $userId, 'i.company_id');

        return $this->bucket($b->get()->getResultArray());
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{count: int, amount: array<string, float>}
     */
    private function bucket(array $rows): array
    {
        $out = ['count' => 0, 'amount' => []];
        foreach ($rows as $r) {
            $out['count']                              += (int) $r['n'];
            $out['amount'][(string) ($r['currency'] ?: '—')] = round((float) $r['total'], 2);
        }

        return $out;
    }
}

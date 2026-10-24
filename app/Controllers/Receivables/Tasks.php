<?php

declare(strict_types=1);

namespace App\Controllers\Receivables;

use App\Controllers\BaseController;
use App\Core\Receivables\ArApprovalQueue;
use App\Core\Tenancy\AccessScope;

/**
 * My tasks for customer invoices: what is waiting on the user, what came back to them, their own invoices in progress and
 * the overdue invoices they are expected to chase. Group-level users also see everything in approval.
 */
class Tasks extends BaseController
{
    public function index(): string
    {
        $uid   = (int) auth()->id();
        $queue = new ArApprovalQueue();
        $group = (new AccessScope())->isGroupLevel($uid);

        return view('receivables/tasks/index', [
            'title'      => 'My customer invoice tasks',
            'waiting'    => $queue->pendingFor($uid),
            'mine'       => $queue->mine($uid),
            'overdue'    => $queue->overdueFor($uid),
            'all'        => $group ? $queue->awaitingAll($uid) : [],
            'groupLevel' => $group,
        ]);
    }
}

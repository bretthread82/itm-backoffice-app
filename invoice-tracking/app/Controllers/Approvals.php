<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccessScope;
use App\Services\ApprovalQueue;

class Approvals extends BaseController
{
    public function index(): string
    {
        $uid   = (int) auth()->id();
        $queue = new ApprovalQueue();
        $group = (new AccessScope())->isGroupLevel($uid);

        return view('approvals/index', [
            'title'      => 'Approvals',
            'waiting'    => $queue->pendingFor($uid),
            'mine'       => $queue->mine($uid),
            'all'        => $group ? $queue->awaitingAll($uid) : [],
            'groupLevel' => $group,
        ]);
    }
}

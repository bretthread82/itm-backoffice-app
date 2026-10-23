<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\ApiController;
use App\Services\ApiPresenter;
use App\Services\ApprovalQueue;
use CodeIgniter\HTTP\ResponseInterface;

class Approvals extends ApiController
{
    /**
     * ?view=waiting (default): invoices waiting on the token owner, oldest first.
     * ?view=mine: the owner's own unfinished invoices, grouped.
     */
    public function index(): ResponseInterface
    {
        $view  = (string) ($this->request->getGet('view') ?: 'waiting');
        $queue = new ApprovalQueue();

        if ($view === 'waiting') {
            return $this->ok(['data' => array_map(ApiPresenter::queueItem(...), $queue->pendingFor($this->uid()))]);
        }
        if ($view === 'mine') {
            $mine = $queue->mine($this->uid());

            return $this->ok(['data' => array_map(
                static fn (array $items): array => array_map(ApiPresenter::queueItem(...), $items),
                $mine,
            )]);
        }

        return $this->error(422, 'invalid_filter', 'view must be waiting or mine.');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Lists of invoices that need someone's attention. Always limited to what the user may see.
 */
final class ApprovalQueue
{
    public function __construct(
        private ?BaseConnection $db = null,
        private ?AccessScope $scope = null,
        private ?WorkflowEngine $engine = null,
    ) {
        $this->db     ??= db_connect();
        $this->scope  ??= new AccessScope($this->db);
        $this->engine ??= new WorkflowEngine($this->db, $this->scope);
    }

    /**
     * Invoices waiting on this user as the named approver or an active delegate, team steps this
     * user has accepted, and team steps still waiting for someone on the user's team to accept
     * (claim_state "available").
     * (Group-level users see everything else in awaitingAll(); that is not "my" queue.)
     *
     * @return list<array<string, mixed>> oldest first
     */
    public function pendingFor(int $userId): array
    {
        $out = [];
        foreach ($this->activeInvoices($userId) as $inv) {
            $check = $this->engine->canAct((int) $inv['id'], $userId);
            if ($check['can_act'] && in_array($check['as'], ['normal', 'delegate', 'team'], true)) {
                $out[] = $this->decorate($inv, $check['step'], $check['as']) + ['claim_state' => $check['as'] === 'team' ? 'mine' : null];
            } elseif ($check['can_claim']) {
                // A team step nobody has accepted yet: every member sees it, and one of them takes it.
                $out[] = $this->decorate($inv, $this->engine->currentStep($inv), 'team') + ['claim_state' => 'available'];
            }
        }

        return $this->oldestFirst($out);
    }

    /**
     * Every invoice currently in approval that the user can see (used by group-level users).
     *
     * @return list<array<string, mixed>> oldest first
     */
    public function awaitingAll(int $userId): array
    {
        $out = [];
        foreach ($this->activeInvoices($userId) as $inv) {
            $out[] = $this->decorate($inv, $this->engine->currentStep($inv), null);
        }

        return $this->oldestFirst($out);
    }

    /**
     * The user's own invoices that are not finished.
     *
     * @return array{waiting: list<array<string, mixed>>, needs_action: list<array<string, mixed>>, drafts: list<array<string, mixed>>}
     */
    public function mine(int $userId): array
    {
        $rows = $this->db->table('invoices i')
            ->select('i.*, c.code AS company_code, t.name AS type_name')
            ->join('companies c', 'c.id = i.company_id')->join('invoice_types t', 't.id = i.invoice_type_id')
            ->where('i.submitted_by', $userId)
            ->groupStart()->whereIn('i.status', ['draft', 'in_approval', 'approved', 'returned', 'rejected'])
            ->orGroupStart()->where('i.status', 'processed')->where('i.current_step_order IS NOT NULL', null, false)->groupEnd()->groupEnd()
            ->orderBy('i.id')->get()->getResultArray();

        $out = ['waiting' => [], 'needs_action' => [], 'drafts' => []];
        foreach ($rows as $inv) {
            $item = $this->decorate($inv, $this->engine->currentStep($inv), null);
            if ($inv['status'] === 'draft') {
                $out['drafts'][] = $item;
            } elseif (in_array($inv['status'], ['returned', 'rejected'], true)) {
                $out['needs_action'][] = $item;
            } else {
                $out['waiting'][] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeInvoices(int $userId): array
    {
        $builder = $this->db->table('invoices i')
            ->select('i.*, c.code AS company_code, t.name AS type_name')
            ->join('companies c', 'c.id = i.company_id')->join('invoice_types t', 't.id = i.invoice_type_id')
            ->whereIn('i.status', WorkflowEngine::ACTIVE)->where('i.current_step_order IS NOT NULL', null, false);
        $this->scope->restrict($builder, $userId, 'i.company_id');

        return $builder->get()->getResultArray();
    }

    /**
     * @param array<string, mixed>      $inv
     * @param array<string, mixed>|null $step
     *
     * @return array<string, mixed>
     */
    private function decorate(array $inv, ?array $step, ?string $as): array
    {
        $since = $this->db->table('invoice_actions')->selectMax('acted_at')->where('invoice_id', $inv['id'])->where('action !=', 'attach')->get()->getRowArray()['acted_at']
            ?? ($inv['submitted_at'] ?: $inv['created_at']);
        $since = $since ?: $inv['created_at'];

        $waiting = max(0, (int) floor((time() - (int) strtotime((string) $since)) / 86400));
        $pastDue = $inv['due_date'] ? max(0, (int) floor((time() - (int) strtotime((string) $inv['due_date'] . ' 23:59:59')) / 86400)) : 0;

        return $inv + [
            'step_label'   => $step !== null ? $this->engine->label($step) : '',
            'acting_as'    => $as,
            'days_waiting' => $waiting,
            'days_past_due' => $pastDue,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function oldestFirst(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => $b['days_waiting'] <=> $a['days_waiting'] ?: $a['id'] <=> $b['id']);

        return $items;
    }
}

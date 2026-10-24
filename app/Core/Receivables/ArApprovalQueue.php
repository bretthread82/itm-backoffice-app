<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use App\Core\Tenancy\AccessScope;
use CodeIgniter\Database\BaseConnection;

/**
 * Lists of customer invoices that need someone's attention. Always limited to what the user may see.
 */
final class ArApprovalQueue
{
    public function __construct(
        private ?BaseConnection $db = null,
        private ?AccessScope $scope = null,
        private ?ReceivablesEngine $engine = null,
    ) {
        $this->db     ??= db_connect();
        $this->scope  ??= new AccessScope($this->db);
        $this->engine ??= new ReceivablesEngine($this->db, $this->scope);
    }

    /**
     * Invoices waiting on this user as the named approver or an active delegate, team steps this user has accepted, and
     * team steps still waiting for someone on the user's team to accept (claim_state "available").
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
     * The user's own invoices that are not finished and not yet with the customer.
     *
     * @return array{waiting: list<array<string, mixed>>, needs_action: list<array<string, mixed>>, drafts: list<array<string, mixed>>}
     */
    public function mine(int $userId): array
    {
        $rows = $this->db->table('ar_invoices i')
            ->select('i.*, c.code AS company_code, t.name AS type_name')
            ->join('companies c', 'c.id = i.company_id')->join('ar_invoice_types t', 't.id = i.invoice_type_id')
            ->where('i.submitted_by', $userId)
            ->whereIn('i.status', ['draft', 'in_approval', 'approved', 'returned', 'rejected'])
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
     * Overdue invoices this user is expected to chase: the ones they raised and the ones in companies where they hold
     * the right to record payments. Most overdue first.
     *
     * @return list<array<string, mixed>>
     */
    public function overdueFor(int $userId): array
    {
        $out = [];
        foreach ((new Collections($this->db))->overdue() as $inv) {
            $companyId = (int) $inv['company_id'];
            $mine      = (int) $inv['submitted_by'] === $userId;

            if (! $this->scope->canViewCompany($userId, $companyId) || ! ($mine || $this->scope->can($userId, $companyId, 'receivables.collect'))) {
                continue;
            }

            $row = $this->db->table('ar_invoices i')
                ->select('i.*, c.code AS company_code, t.name AS type_name')
                ->join('companies c', 'c.id = i.company_id')->join('ar_invoice_types t', 't.id = i.invoice_type_id')
                ->where('i.id', (int) $inv['id'])->get()->getRowArray();

            $out[] = $row + ['step_label' => '', 'acting_as' => null, 'days_waiting' => 0, 'days_past_due' => (int) $inv['days_overdue'], 'outstanding' => Collections::outstanding($inv)];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeInvoices(int $userId): array
    {
        $builder = $this->db->table('ar_invoices i')
            ->select('i.*, c.code AS company_code, t.name AS type_name')
            ->join('companies c', 'c.id = i.company_id')->join('ar_invoice_types t', 't.id = i.invoice_type_id')
            ->whereIn('i.status', ReceivablesEngine::ACTIVE)->where('i.current_step_order IS NOT NULL', null, false);
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
        $since = $this->db->table('ar_actions')->selectMax('acted_at')->where('invoice_id', $inv['id'])->where('action !=', 'attach')->get()->getRowArray()['acted_at']
            ?? ($inv['submitted_at'] ?: $inv['created_at']);
        $since = $since ?: $inv['created_at'];

        return $inv + [
            'step_label'    => $step !== null ? $this->engine->label($step) : '',
            'acting_as'     => $as,
            'days_waiting'  => max(0, (int) floor((time() - (int) strtotime((string) $since)) / 86400)),
            'days_past_due' => ar_days_overdue($inv),
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

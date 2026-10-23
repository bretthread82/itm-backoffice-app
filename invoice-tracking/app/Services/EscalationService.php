<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Chases invoices that sit too long at one approval step. Each route step may carry `sla_days`
 * (copied into the invoice's route snapshot at submission). Two stages, each sent once per visit
 * to a step:
 *
 *   reminder    after sla_days    -> whoever is expected to act (named user, delegates, the team
 *                                    member who accepted it, or all team members, plus the team's
 *                                    group mailbox; outside party by email)
 *   escalation  after 2*sla_days  -> every active group-level user
 *
 * The clock starts when the invoice reached the step: the latest submit, resubmit or approve.
 * Accepting or releasing a team step does not reset it. Steps without sla_days are never chased.
 * Notification problems never propagate (NotificationService swallows email failures).
 */
final class EscalationService
{
    public const REMINDER   = 'reminder';
    public const ESCALATION = 'escalation';

    public function __construct(
        private ?BaseConnection $db = null,
        private ?NotificationService $notifier = null,
        private ?WorkflowEngine $engine = null,
        private ?AuditLogger $audit = null,
    ) {
        $this->db       ??= db_connect();
        $this->notifier ??= new NotificationService($this->db);
        $this->engine   ??= new WorkflowEngine($this->db);
        $this->audit    ??= new AuditLogger();
    }

    /**
     * @return array{checked: int, reminders: int, escalations: int}
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $out   = ['checked' => 0, 'reminders' => 0, 'escalations' => 0];

        $invoices = $this->db->table('invoices')->whereIn('status', WorkflowEngine::ACTIVE)->where('current_step_order IS NOT NULL', null, false)->orderBy('id')->get()->getResultArray();

        foreach ($invoices as $inv) {
            $step = $this->engine->currentStep($inv);
            $sla  = $step !== null ? (int) ($step['sla_days'] ?? 0) : 0;
            if ($step === null || $sla <= 0) {
                continue;
            }
            $out['checked']++;

            $entered = $this->stepEnteredAt($inv);
            $waited  = $now - (int) strtotime($entered);

            try {
                if ($waited >= $sla * 86400 && $this->record($inv, $step, $entered, self::REMINDER)) {
                    $this->remind($inv, $step, $waited);
                    $out['reminders']++;
                }
                if ($waited >= 2 * $sla * 86400 && $this->record($inv, $step, $entered, self::ESCALATION)) {
                    $this->escalate($inv, $step, $waited);
                    $out['escalations']++;
                }
            } catch (Throwable $e) {
                log_message('error', 'Escalation for invoice {id} failed: {error}', ['id' => $inv['id'], 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * When the invoice reached its current step.
     *
     * @param array<string, mixed> $inv
     */
    public function stepEnteredAt(array $inv): string
    {
        $row = $this->db->table('invoice_actions')->selectMax('acted_at')
            ->where('invoice_id', $inv['id'])->whereIn('action', ['submit', 'resubmit', 'approve', 'return_previous'])->get()->getRowArray();

        return (string) ($row['acted_at'] ?? $inv['submitted_at'] ?? $inv['created_at']);
    }

    /**
     * Claims the (invoice, step, visit, level) slot. False if it was already sent.
     *
     * @param array<string, mixed> $inv
     * @param array<string, mixed> $step
     */
    private function record(array $inv, array $step, string $entered, string $level): bool
    {
        $this->db->table('step_escalations')->ignore(true)->insert([
            'invoice_id' => (int) $inv['id'], 'step_order' => (int) $step['order'], 'step_entered_at' => $entered,
            'level' => $level, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->db->affectedRows() > 0;
    }

    /**
     * @param array<string, mixed> $inv
     * @param array<string, mixed> $step
     */
    private function remind(array $inv, array $step, int $waited): void
    {
        $companyId = (int) $inv['company_id'];
        $who       = $this->engine->approversOf($step, $companyId);
        $userIds   = $who['user_ids'];

        // A team step someone has already accepted is that person's to chase.
        if ($this->engine->isTeamStep($step) && ($claim = $this->engine->openClaim((int) $inv['id'], (int) $step['order'])) !== null) {
            $userIds = [(int) $claim['claimed_by']];
        }

        $ctx = ['actor_id' => 0, 'step_label' => $this->engine->label($step), 'days' => $this->days($waited), 'sla_days' => (int) $step['sla_days']];
        $this->notifier->dispatch('invoice.reminder', (int) $inv['id'], $userIds, $ctx);
        if ($who['email'] !== null && $who['email'] !== '') {
            $this->notifier->dispatchToAddress('invoice.reminder', (int) $inv['id'], $who['email'], $ctx);
        }

        $this->audit->log('invoice.reminder', null, 'invoice', (int) $inv['id'], ['step' => (int) $step['order'], 'days' => $ctx['days']]);
    }

    /**
     * @param array<string, mixed> $inv
     * @param array<string, mixed> $step
     */
    private function escalate(array $inv, array $step, int $waited): void
    {
        $ids = array_map('intval', array_column(
            $this->db->table('group_level_users g')->select('g.user_id')->join('users u', 'u.id = g.user_id')->where('u.active', 1)->get()->getResultArray(),
            'user_id',
        ));

        $ctx = ['actor_id' => 0, 'step_label' => $this->engine->label($step), 'days' => $this->days($waited), 'sla_days' => (int) $step['sla_days']];
        $this->notifier->dispatch('invoice.escalated', (int) $inv['id'], $ids, $ctx);

        $this->audit->log('invoice.escalated', null, 'invoice', (int) $inv['id'], ['step' => (int) $step['order'], 'days' => $ctx['days'], 'notified' => count($ids)]);
    }

    private function days(int $seconds): int
    {
        return max(0, (int) floor($seconds / 86400));
    }
}

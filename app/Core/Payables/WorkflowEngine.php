<?php

declare(strict_types=1);

namespace App\Core\Payables;

use App\Core\Tenancy\AccessScope;

use App\Models\RouteVariantModel;
use CodeIgniter\Database\BaseConnection;

/**
 * The invoice state machine. Every transition lives here so the rules are in one place:
 * who may act, which notes are mandatory, what is recorded, who is told.
 *
 *  draft -> in_approval -> (approved) -> processed -> paid
 *              |-> returned -> (resubmit) -> in_approval
 *              |-> rejected -> (resubmit) -> in_approval
 *  draft/returned/rejected -> cancelled
 *
 * Route steps are snapshotted at submission, so editing a route never changes invoices in flight.
 * Step types: submit (done by the submitter at submission), approve, process (the invoice is handled/entered),
 * pay (payment made; records the payment reference and date), notify (told, then skipped).
 * "approved" means the invoice is at its process step. "processed" means the process step is done: the invoice then
 * waits at its pay step, or, on a route without one, ends there. "paid" is the end of a route with a pay step.
 * SharePoint filing is a phase 2 hook on markProcessed().
 */
final class WorkflowEngine
{
    public const EDITABLE = ['draft', 'returned', 'rejected'];
    public const ACTIVE   = ['in_approval', 'approved', 'processed'];

    /** Step types somebody has to act on (everything except submit and notify). */
    public const ACTION_STEPS = ['approve', 'process', 'pay'];

    public function __construct(
        private ?BaseConnection $db = null,
        private ?AccessScope $scope = null,
        private ?DelegationResolver $delegations = null,
        private ?InvoiceNotifier $notifier = null,
        private ?InvoiceAudit $audit = null,
        private ?InvoiceFiles $files = null,
    ) {
        $this->db          ??= db_connect();
        $this->scope       ??= new AccessScope($this->db);
        $this->delegations ??= new DelegationResolver($this->db, $this->scope);
        $this->notifier    ??= new InvoiceNotifier($this->db);
        $this->audit       ??= new InvoiceAudit($this->db);
        $this->files       ??= new InvoiceFiles($this->db);
    }

    // ------------------------------------------------------------------ drafts

    /**
     * @param array<string, mixed> $in
     */
    public function createDraft(int $userId, array $in): int
    {
        $companyId = (int) ($in['company_id'] ?? 0);
        $typeId    = (int) ($in['invoice_type_id'] ?? 0);

        $this->assertCanSubmitFor($userId, $companyId);
        $this->assertTypeBelongs($typeId, $companyId);

        $this->db->table('inv_invoices')->insert($this->details($in) + [
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'submitted_by' => $userId,
            'status' => 'draft', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();
        $this->audit->log('payables.draft_created', $userId, 'invoice', $id);

        return $id;
    }

    /**
     * @param array<string, mixed> $in
     */
    public function updateDetails(int $invoiceId, int $userId, array $in): void
    {
        $inv = $this->invoice($invoiceId);
        $this->assertSubmitter($inv, $userId);
        if (! in_array($inv['status'], self::EDITABLE, true)) {
            throw new WorkflowException('This invoice can no longer be edited.');
        }

        $companyId = (int) ($in['company_id'] ?? $inv['company_id']);
        $typeId    = (int) ($in['invoice_type_id'] ?? $inv['invoice_type_id']);
        $this->assertCanSubmitFor($userId, $companyId);
        $this->assertTypeBelongs($typeId, $companyId);

        $this->db->table('inv_invoices')->where('id', $invoiceId)->update($this->details($in) + [
            'company_id' => $companyId, 'invoice_type_id' => $typeId, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<string> reasons the invoice cannot be submitted yet
     */
    public function problemsForSubmit(int $invoiceId): array
    {
        $inv = $this->invoice($invoiceId);
        $p   = [];

        foreach (['supplier' => 'Supplier', 'invoice_number' => 'Invoice number', 'invoice_date' => 'Invoice date', 'due_date' => 'Due date'] as $k => $label) {
            if ($inv[$k] === null || trim((string) $inv[$k]) === '') {
                $p[] = $label . ' is required.';
            }
        }
        if ($inv['amount'] === null || (float) $inv['amount'] <= 0) {
            $p[] = 'Amount must be more than zero.';
        }
        if ($inv['invoice_date'] && $inv['due_date'] && $inv['due_date'] < $inv['invoice_date']) {
            $p[] = 'The due date cannot be before the invoice date.';
        }
        if ($this->files->current($invoiceId) === null) {
            $p[] = 'Attach the invoice PDF.';
        }

        return $p;
    }

    // ------------------------------------------------------------------ submit / resubmit

    public function submit(int $invoiceId, int $userId): void
    {
        $inv = $this->invoice($invoiceId);
        $this->assertSubmitter($inv, $userId);
        if ($inv['status'] !== 'draft') {
            throw new WorkflowException('Only a draft can be submitted.');
        }

        $this->start($inv, $userId, 'submit', null);
    }

    public function resubmit(int $invoiceId, int $userId, string $note): void
    {
        $inv = $this->invoice($invoiceId);
        $this->assertSubmitter($inv, $userId);
        if (! in_array($inv['status'], ['returned', 'rejected'], true)) {
            throw new WorkflowException('Only a returned or rejected invoice can be resubmitted.');
        }

        $this->start($inv, $userId, 'resubmit', $this->requireNote($note, 'Explain what you changed before resubmitting.'));
    }

    /**
     * @param array<string, mixed> $inv
     */
    private function start(array $inv, int $userId, string $action, ?string $note): void
    {
        $invoiceId = (int) $inv['id'];

        $this->assertCanSubmitFor($userId, (int) $inv['company_id']);
        $problems = $this->problemsForSubmit($invoiceId);
        if ($problems !== []) {
            throw new WorkflowException(implode(' ', $problems));
        }

        $route = $this->selectRoute($inv);
        $steps = $route['steps'];
        if (array_filter($steps, static fn (array $s): bool => in_array($s['step_type'], self::ACTION_STEPS, true)) === []) {
            throw new WorkflowException('The route for this invoice type has no approval steps. Ask an administrator to fix it.');
        }

        $snapshot = json_encode(['route_id' => (int) $route['id'], 'version' => (int) $route['version'], 'variant' => $route['variant'], 'steps' => $steps], JSON_UNESCAPED_UNICODE);
        $events   = [];
        // Captured now so changing the type later never alters an invoice already in flight.
        $type     = $this->db->table('inv_invoice_types')->select('high_priority')->where('id', (int) $inv['invoice_type_id'])->get()->getRowArray();

        $this->db->transBegin();
        try {
            $noteId = $note !== null ? $this->insertNote($invoiceId, $userId, $action === 'resubmit' ? 'resubmit_reason' : 'note', $note) : null;
            $this->db->table('inv_invoices')->where('id', $invoiceId)->update([
                'route_id' => (int) $route['id'], 'route_snapshot' => $snapshot, 'status' => 'in_approval',
                'high_priority' => (int) ($type['high_priority'] ?? 0), 'submitted_at' => date('Y-m-d H:i:s'), 'ended_at' => null, 'completed_at' => null, 'processed_at' => null, 'paid_at' => null,
                'current_step_order' => null, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->record($invoiceId, null, $userId, null, 'normal', $action, $noteId);

            $events = $this->moveToNextStep($invoiceId, $steps, 0, $userId);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof WorkflowException ? $e : new WorkflowException('Could not submit the invoice: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('payables.' . $action, $userId, 'invoice', $invoiceId, ['route' => $route['id'], 'version' => $route['version']]);

        $ctx = ['actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'note' => $note ?? ''];
        if ($action === 'submit') {
            $this->notifier->dispatch('payables.received', $invoiceId, [(int) $inv['submitted_by']], ['actor_id' => 0] + $ctx + ['step_label' => $events['step_label'] ?? '']);
        }
        $this->announce($invoiceId, $events, $ctx, $action === 'resubmit' ? 'payables.resubmitted' : 'payables.submitted');
    }

    // ------------------------------------------------------------------ approve / return / reject

    /**
     * @param array{payment_reference?: mixed, payment_date?: mixed} $payment required on a pay step, ignored otherwise
     */
    public function approve(int $invoiceId, int $userId, ?string $note = null, array $payment = []): void
    {
        [$inv, $step, $as, $onBehalfOf] = $this->actorContext($invoiceId, $userId);

        $note = $note !== null ? trim($note) : '';
        if ($as === 'group_level' && $note === '') {
            throw new WorkflowException('Give a reason: you are approving as a group-level user on someone else\'s step.');
        }

        $type = (string) $step['step_type'];
        $paid = $type === 'pay' ? $this->paymentDetails($payment) : null;

        $steps  = $this->stepsOf($inv);
        $events = [];

        $this->db->transBegin();
        try {
            $noteId = $note !== '' ? $this->insertNote($invoiceId, $userId, $as === 'group_level' ? 'override_reason' : 'note', $note) : null;
            $this->record($invoiceId, (int) $step['order'], $userId, $onBehalfOf, $as, 'approve', $noteId);
            if ($paid !== null) {
                $now = date('Y-m-d H:i:s');
                $this->db->table('inv_invoices')->where('id', $invoiceId)->update($paid + ['paid_at' => $now, 'updated_at' => $now]);
            }
            if ($type === 'process') {
                $this->markProcessed($invoiceId, $userId);
            }
            $events = $this->moveToNextStep($invoiceId, $steps, (int) $step['order'], $userId);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof WorkflowException ? $e : new WorkflowException('Could not save the approval: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('payables.approved', $userId, 'invoice', $invoiceId, ['step' => $step['order'], 'as' => $as]);
        if ($type === 'pay') {
            $this->audit->log('payables.paid', $userId, 'invoice', $invoiceId, ['step' => $step['order']]);
        }

        $ctx = ['actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'note' => $note, 'step_label' => $events['step_label'] ?? ''];
        $this->tellOverride($as, $inv, $step, $ctx);

        if ($type === 'pay') {
            $this->notifier->dispatch('payables.paid', $invoiceId, [(int) $inv['submitted_by']], $ctx);
        } elseif ($type === 'process' || ($events['completed'] ?? false)) {
            $this->notifier->dispatch('payables.processed', $invoiceId, [(int) $inv['submitted_by']], $ctx);
        } elseif ($as !== 'group_level') {
            $this->notifier->dispatch('payables.step_approved', $invoiceId, [(int) $inv['submitted_by']], $ctx);
        }
        $this->announce($invoiceId, $events, $ctx, 'payables.submitted');
    }

    public function returnForChanges(int $invoiceId, int $userId, string $note): void
    {
        $this->sendBack($invoiceId, $userId, $note, 'return', 'returned', 'payables.returned', 'return_reason', 'Explain what needs to change before returning the invoice.');
    }

    /**
     * The nearest earlier approve/process step, or null when this is the first one (or the invoice is already
     * at the pay step, where the invoice has been processed and filed and cannot be wound back).
     *
     * @param array<string, mixed> $inv
     * @param array<string, mixed> $step the current step
     *
     * @return array<string, mixed>|null
     */
    public function previousStep(array $inv, array $step): ?array
    {
        if (($step['step_type'] ?? '') === 'pay') {
            return null;
        }

        $steps = $this->stepsOf($inv);
        usort($steps, static fn (array $a, array $b): int => $b['order'] <=> $a['order']);
        foreach ($steps as $s) {
            if ((int) $s['order'] < (int) $step['order'] && in_array($s['step_type'], ['approve', 'process'], true)) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Sends the invoice back one step to the previous approver instead of to the submitter. It stays in
     * approval, so nobody has to resubmit.
     */
    public function returnToPreviousApprover(int $invoiceId, int $userId, string $note): void
    {
        [$inv, $step, $as, $onBehalfOf] = $this->actorContext($invoiceId, $userId);
        $note = $this->requireNote($note, 'Explain what needs to change before returning the invoice.');

        $previous = $this->previousStep($inv, $step);
        if ($previous === null) {
            throw new WorkflowException('There is no earlier approver to return this invoice to. Return it to the submitter instead.');
        }

        $this->db->transBegin();
        try {
            $noteId = $this->insertNote($invoiceId, $userId, 'return_reason', $note);
            $this->record($invoiceId, (int) $step['order'], $userId, $onBehalfOf, $as, 'return_previous', $noteId);
            $this->closeClaims($invoiceId);
            $this->db->table('inv_invoices')->where('id', $invoiceId)->update([
                'current_step_order' => (int) $previous['order'],
                'status'             => match ($previous['step_type']) {
                    'process' => 'approved',
                    'pay'     => 'processed',
                    default   => 'in_approval',
                },
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof WorkflowException ? $e : new WorkflowException('Could not save: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('payables.returned_previous', $userId, 'invoice', $invoiceId, ['from_step' => $step['order'], 'to_step' => $previous['order'], 'as' => $as]);

        $ctx = ['actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'note' => $note, 'step_label' => $this->label($previous)];
        $this->announce($invoiceId, ['step' => $previous, 'step_label' => $this->label($previous)], $ctx, 'payables.returned_step');
        $this->tellOverride($as, $inv, $step, $ctx, false);
    }

    public function reject(int $invoiceId, int $userId, string $note): void
    {
        $this->sendBack($invoiceId, $userId, $note, 'reject', 'rejected', 'payables.rejected', 'reject_reason', 'Give a reason for rejecting the invoice.');
    }

    private function sendBack(int $invoiceId, int $userId, string $note, string $action, string $status, string $event, string $kind, string $message): void
    {
        [$inv, $step, $as, $onBehalfOf] = $this->actorContext($invoiceId, $userId);
        $note = $this->requireNote($note, $message);

        $this->db->transBegin();
        try {
            $noteId = $this->insertNote($invoiceId, $userId, $kind, $note);
            $this->record($invoiceId, (int) $step['order'], $userId, $onBehalfOf, $as, $action, $noteId);
            $this->closeClaims($invoiceId);
            $this->db->table('inv_invoices')->where('id', $invoiceId)->update([
                'status' => $status, 'ended_at' => $status === 'rejected' ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof WorkflowException ? $e : new WorkflowException('Could not save: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('payables.' . $status, $userId, 'invoice', $invoiceId, ['step' => $step['order'], 'as' => $as]);

        $ctx = ['actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'note' => $note, 'step_label' => $this->label($step)];
        $this->notifier->dispatch($event, $invoiceId, [(int) $inv['submitted_by']], ['actor_id' => 0] + $ctx);
        $this->tellOverride($as, $inv, $step, $ctx, false);
    }

    public function cancel(int $invoiceId, int $userId): void
    {
        $inv = $this->invoice($invoiceId);
        $this->assertSubmitter($inv, $userId);
        if (! in_array($inv['status'], self::EDITABLE, true)) {
            throw new WorkflowException('Only a draft, returned or rejected invoice can be cancelled.');
        }

        $this->db->table('inv_invoices')->where('id', $invoiceId)->update(['status' => 'cancelled', 'ended_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->record($invoiceId, null, $userId, null, 'normal', 'cancel', null);
        $this->audit->log('payables.cancelled', $userId, 'invoice', $invoiceId);
    }

    public function addNote(int $invoiceId, int $userId, string $body): int
    {
        $this->invoice($invoiceId);
        if (! $this->canViewInvoice($userId, $invoiceId)) {
            throw new WorkflowException('You cannot see this invoice.');
        }

        return $this->insertNote($invoiceId, $userId, 'note', $this->requireNote($body, 'Write a note first.'));
    }

    /**
     * Who may attach a supporting document right now: whoever can act on the invoice (named approver, delegate,
     * team member who accepted it, group-level user) or the person who submitted it, while it is in approval
     * or waiting to be corrected.
     *
     * @return array{allowed: bool, as: string|null, reason_required: bool}
     */
    public function canAttach(int $invoiceId, int $userId): array
    {
        $no  = ['allowed' => false, 'as' => null, 'reason_required' => false];
        $inv = $this->db->table('inv_invoices')->where('id', $invoiceId)->get()->getRowArray();
        if ($inv === null || ! $this->canViewInvoice($userId, $invoiceId)) {
            return $no;
        }

        $act = $this->canAct($invoiceId, $userId);
        if ($act['can_act']) {
            return ['allowed' => true, 'as' => (string) $act['as'], 'reason_required' => $act['reason_required']];
        }
        if ((int) $inv['submitted_by'] === $userId && in_array($inv['status'], [...self::ACTIVE, ...self::EDITABLE], true)) {
            return ['allowed' => true, 'as' => 'normal', 'reason_required' => false];
        }

        return $no;
    }

    /**
     * Attaches a supporting document. The upload is recorded in the history (and the audit log); an optional
     * note says why it is needed, and a group-level user who is not the named approver must give one.
     *
     * @throws WorkflowException
     */
    public function addDocument(int $invoiceId, int $userId, string $tmpPath, string $originalName, ?string $note = null): int
    {
        $inv   = $this->invoice($invoiceId);
        $right = $this->canAttach($invoiceId, $userId);
        if (! $right['allowed']) {
            throw new WorkflowException('You cannot attach documents to this invoice right now.');
        }

        $note = trim((string) $note);
        if ($right['reason_required'] && $note === '') {
            throw new WorkflowException('Say why you are attaching this document: you are acting as a group-level user.');
        }

        $as   = (string) $right['as'];
        $step = in_array($inv['status'], self::ACTIVE, true) ? $this->currentStep($inv) : null;
        $docs = new InvoiceDocuments($this->db);

        // Validate and store first; a refused file leaves nothing behind.
        $docId = $docs->store($invoiceId, $userId, $tmpPath, $originalName, $as);

        $this->db->transBegin();
        try {
            $noteId = $note !== '' ? $this->insertNote($invoiceId, $userId, $as === 'group_level' ? 'override_reason' : 'note', mb_substr($note, 0, 5000)) : null;
            if ($noteId !== null) {
                $this->db->table('inv_documents')->where('id', $docId)->update(['note_id' => $noteId]);
            }
            $this->record($invoiceId, $step !== null ? (int) $step['order'] : null, $userId, null, $as, 'attach', $noteId);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw new WorkflowException('Could not attach the document: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('payables.document_added', $userId, 'invoice', $invoiceId, ['document' => $docId, 'as' => $as]);

        if ((int) $inv['submitted_by'] !== $userId) {
            $this->notifier->dispatch('payables.document_added', $invoiceId, [(int) $inv['submitted_by']], [
                'actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'note' => $note !== '' ? $note : null,
                'document_name' => InvoiceDocuments::cleanName($originalName),
            ]);
        }

        return $docId;
    }

    // ------------------------------------------------------------------ who can act

    /**
     * What this user can do with the invoice right now.
     *
     * On a team step, "claim" says who has accepted it and whether this user may accept it
     * (a team member can act only after accepting).
     *
     * "previous_step" is the earlier step the invoice can be returned to, or null when only the submitter is possible.
     *
     * @return array{can_act: bool, as: string|null, reason_required: bool, step: array<string, mixed>|null, on_behalf_of: int|null, claim: array<string, mixed>|null, can_claim: bool, can_release: bool, previous_step: array<string, mixed>|null}
     */
    public function canAct(int $invoiceId, int $userId): array
    {
        $none = ['can_act' => false, 'as' => null, 'reason_required' => false, 'step' => null, 'on_behalf_of' => null, 'claim' => null, 'can_claim' => false, 'can_release' => false, 'previous_step' => null];
        $inv  = $this->db->table('inv_invoices')->where('id', $invoiceId)->get()->getRowArray();
        if ($inv === null || ! in_array($inv['status'], self::ACTIVE, true)) {
            return $none;
        }

        $step = $this->currentStep($inv);
        if ($step === null) {
            return $none;
        }

        $claim  = $this->isTeamStep($step) ? $this->openClaim($invoiceId, (int) $step['order']) : null;
        $isTeam = $this->isTeamStep($step);
        $role   = $this->roleFor($inv, $step, $userId);

        $canClaim = $isTeam && $claim === null && $this->isTeamMember($step, $userId, (int) $inv['company_id']);
        $mine     = $claim !== null && (int) $claim['claimed_by'] === $userId;
        $release  = $claim !== null && ($mine || $role !== null && $role['as'] === 'group_level');

        $base = ['claim' => $claim, 'can_claim' => $canClaim, 'can_release' => $release];
        if ($role === null) {
            return $base + $none;
        }

        return $base + ['can_act' => true, 'as' => $role['as'], 'reason_required' => $role['as'] === 'group_level', 'step' => $step, 'on_behalf_of' => $role['on_behalf_of'], 'previous_step' => $this->previousStep($inv, $step)];
    }

    // ------------------------------------------------------------------ team steps: accept / release

    /**
     * A member of the step's team accepts the invoice. From then on only they can act on it.
     */
    public function claim(int $invoiceId, int $userId): void
    {
        $this->db->transBegin();
        try {
            $inv  = $this->lockedInvoice($invoiceId);
            $step = in_array($inv['status'], self::ACTIVE, true) ? $this->currentStep($inv) : null;
            if ($step === null || ! $this->isTeamStep($step)) {
                throw new WorkflowException('This invoice is not waiting on a team.');
            }
            if (! $this->isTeamMember($step, $userId, (int) $inv['company_id'])) {
                throw new WorkflowException('You are not on the team this step is assigned to.');
            }
            if (($open = $this->openClaim($invoiceId, (int) $step['order'])) !== null) {
                throw new WorkflowException((int) $open['claimed_by'] === $userId ? 'You have already accepted this invoice.' : 'Someone else on the team has already accepted this invoice.');
            }

            $this->db->table('inv_step_claims')->insert([
                'invoice_id' => $invoiceId, 'step_order' => (int) $step['order'], 'team_id' => (int) $step['team_id'],
                'claimed_by' => $userId, 'claimed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->record($invoiceId, (int) $step['order'], $userId, null, 'team', 'claim', null);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof WorkflowException ? $e : new WorkflowException('Could not accept the invoice: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('payables.claimed', $userId, 'invoice', $invoiceId, ['step' => $step['order'], 'team' => $step['team_id']]);

        $ctx = ['actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'step_label' => $this->label($step)];
        $this->notifier->dispatch('payables.claimed', $invoiceId, [(int) $inv['submitted_by'], ...$this->teamMemberIds($step, (int) $inv['company_id'])], $ctx);
    }

    /**
     * Gives an accepted invoice back to the team. The acceptor can do it; a group-level user can too,
     * but must say why.
     */
    public function release(int $invoiceId, int $userId, ?string $note = null): void
    {
        $note = trim((string) $note);

        $this->db->transBegin();
        try {
            $inv  = $this->lockedInvoice($invoiceId);
            $step = in_array($inv['status'], self::ACTIVE, true) ? $this->currentStep($inv) : null;
            if ($step === null || ! $this->isTeamStep($step)) {
                throw new WorkflowException('This invoice is not waiting on a team.');
            }
            $open = $this->openClaim($invoiceId, (int) $step['order']);
            if ($open === null) {
                throw new WorkflowException('Nobody has accepted this invoice.');
            }

            $as = 'team';
            if ((int) $open['claimed_by'] !== $userId) {
                if (! $this->scope->isGroupLevel($userId) || ! $this->isActive($userId)) {
                    throw new WorkflowException('Only the person who accepted this invoice can release it.');
                }
                if ($note === '') {
                    throw new WorkflowException('Give a reason: you are releasing an invoice someone else accepted.');
                }
                $as = 'group_level';
            }

            $noteId = $note !== '' ? $this->insertNote($invoiceId, $userId, $as === 'group_level' ? 'override_reason' : 'note', mb_substr($note, 0, 5000)) : null;
            $this->db->table('inv_step_claims')->where('id', $open['id'])->update(['released_at' => date('Y-m-d H:i:s'), 'released_by' => $userId]);
            $this->record($invoiceId, (int) $step['order'], $userId, (int) $open['claimed_by'] !== $userId ? (int) $open['claimed_by'] : null, $as, 'release', $noteId);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('A database error occurred.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e instanceof WorkflowException ? $e : new WorkflowException('Could not release the invoice: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log('payables.released', $userId, 'invoice', $invoiceId, ['step' => $step['order'], 'as' => $as]);

        $ctx = ['actor_id' => $userId, 'actor_name' => $this->nameOf($userId), 'step_label' => $this->label($step), 'note' => $note];
        $who = [...$this->teamMemberIds($step, (int) $inv['company_id']), (int) $open['claimed_by']];
        $this->notifier->dispatch('payables.released', $invoiceId, $who, $ctx);
    }

    /**
     * @param array<string, mixed> $step
     */
    public function isTeamStep(array $step): bool
    {
        return ($step['assignee_kind'] ?? '') === 'team' && ! empty($step['team_id']);
    }

    /**
     * The open (current) acceptance of a step, or null. An acceptance by someone whose account has
     * since been switched off no longer counts, so the team can pick the invoice up again.
     *
     * @return array<string, mixed>|null
     */
    public function openClaim(int $invoiceId, int $stepOrder): ?array
    {
        $row = $this->db->table('inv_step_claims c')
            ->select("c.*, COALESCE(NULLIF(u.display_name, ''), u.username) AS claimed_by_name", false)
            ->join('inv_users u', 'u.id = c.claimed_by')
            ->where(['c.invoice_id' => $invoiceId, 'c.step_order' => $stepOrder, 'u.active' => 1])->where('c.released_at IS NULL')
            ->orderBy('c.id', 'DESC')->get(1)->getRowArray();

        return $row;
    }

    /**
     * Active members of the step's team who can see this company's invoices and may approve for it.
     *
     * @param array<string, mixed> $step
     *
     * @return list<int>
     */
    public function teamMemberIds(array $step, int $companyId): array
    {
        if (! $this->isTeamStep($step)) {
            return [];
        }

        $rows = $this->db->table('inv_team_members m')->select('m.user_id')
            ->join('inv_users u', 'u.id = m.user_id')->where('m.team_id', (int) $step['team_id'])->where('u.active', 1)
            ->orderBy('m.user_id')->get()->getResultArray();

        $ids = [];
        foreach ($rows as $r) {
            if ($this->scope->canViewCompany((int) $r['user_id'], $companyId) && $this->holdsApproveRight((int) $r['user_id'], $companyId)) {
                $ids[] = (int) $r['user_id'];
            }
        }

        return $ids;
    }

    /**
     * The team's group mailbox, read live so a change applies to invoices already in flight.
     */
    private function teamGroupEmail(int $teamId): ?string
    {
        $row = $this->db->table('inv_teams')->select('group_email')->where('id', $teamId)->get()->getRowArray();
        $email = trim((string) ($row['group_email'] ?? ''));

        return $email === '' ? null : $email;
    }

    /**
     * @param array<string, mixed> $step
     */
    public function isTeamMember(array $step, int $userId, int $companyId): bool
    {
        return in_array($userId, $this->teamMemberIds($step, $companyId), true);
    }

    private function closeClaims(int $invoiceId): void
    {
        $this->db->table('inv_step_claims')->where('invoice_id', $invoiceId)->where('released_at IS NULL')
            ->update(['released_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Reads the invoice row and, on MySQL, locks it so two people accepting at once cannot both win.
     * Must be called inside a transaction.
     *
     * @return array<string, mixed>
     */
    private function lockedInvoice(int $invoiceId): array
    {
        $row = $this->db->DBDriver === 'MySQLi'
            ? $this->db->query('SELECT * FROM inv_invoices WHERE id = ? FOR UPDATE', [$invoiceId])->getRowArray()
            : $this->db->table('inv_invoices')->where('id', $invoiceId)->get()->getRowArray();
        if ($row === null) {
            throw new WorkflowException('Invoice not found.');
        }

        return $row;
    }

    /**
     * Whether the user has been given the right to approve for this company (user_companies.can_approve).
     * Group-level users are not tested here: they act through the audited override path.
     */
    public function holdsApproveRight(int $userId, int $companyId): bool
    {
        return $this->db->table('user_companies uc')->join('companies c', 'c.id = uc.company_id')
            ->where(['uc.user_id' => $userId, 'uc.company_id' => $companyId, 'uc.can_approve' => 1, 'c.active' => 1])
            ->countAllResults() > 0;
    }

    private function canViewInvoice(int $userId, int $invoiceId): bool
    {
        $row = $this->db->table('inv_invoices')->select('company_id')->where('id', $invoiceId)->get()->getRowArray();

        return $row !== null && $this->scope->canViewCompany($userId, (int) $row['company_id']);
    }

    private function isActive(int $userId): bool
    {
        return $this->db->table('inv_users')->where('id', $userId)->where('active', 1)->countAllResults() > 0;
    }

    /**
     * @param array<string, mixed> $inv
     *
     * @return array<string, mixed>|null the current actionable step from the snapshot
     */
    public function currentStep(array $inv): ?array
    {
        if ($inv['current_step_order'] === null) {
            return null;
        }
        foreach ($this->stepsOf($inv) as $s) {
            if ((int) $s['order'] === (int) $inv['current_step_order']) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Who is expected to act on a step: the named user and anyone they have delegated to.
     *
     * @param array<string, mixed> $step
     *
     * @return array{user_ids: list<int>, email: string|null}
     */
    public function approversOf(array $step, int $companyId): array
    {
        if ($this->isTeamStep($step)) {
            return ['user_ids' => $this->teamMemberIds($step, $companyId), 'email' => $this->teamGroupEmail((int) $step['team_id'])];
        }
        if (($step['assignee_kind'] ?? 'user') !== 'user' || empty($step['user_id'])) {
            return ['user_ids' => [], 'email' => $step['email'] ?? null];
        }

        $id = (int) $step['user_id'];

        return ['user_ids' => array_values(array_unique([$id, ...$this->delegations->delegatesOf($id, $companyId)])), 'email' => null];
    }

    /**
     * @param array<string, mixed> $inv
     * @param array<string, mixed> $step
     *
     * @return array{as: string, on_behalf_of: int|null}|null
     */
    private function roleFor(array $inv, array $step, int $userId): ?array
    {
        $active = $this->db->table('inv_users')->where('id', $userId)->where('active', 1)->countAllResults() > 0;
        if (! $active) {
            return null;
        }

        // Team steps: only the member who accepted the invoice acts as the team (delegation does not
        // apply). Group-level users may still act, with a reason, on behalf of the acceptor.
        if ($this->isTeamStep($step)) {
            $claim = $this->openClaim((int) $inv['id'], (int) $step['order']);
            if ($claim !== null && (int) $claim['claimed_by'] === $userId && $this->holdsApproveRight($userId, (int) $inv['company_id'])) {
                return ['as' => 'team', 'on_behalf_of' => null];
            }

            return $this->scope->isGroupLevel($userId) ? ['as' => 'group_level', 'on_behalf_of' => $claim !== null ? (int) $claim['claimed_by'] : null] : null;
        }

        $assignee = ($step['assignee_kind'] ?? 'user') === 'user' && ! empty($step['user_id']) ? (int) $step['user_id'] : null;

        $mayApprove = $this->holdsApproveRight($userId, (int) $inv['company_id']);

        if ($assignee !== null && $assignee === $userId && $mayApprove) {
            return ['as' => 'normal', 'on_behalf_of' => null];
        }
        if ($assignee !== null && $mayApprove && $this->delegations->isDelegate($assignee, $userId, (int) $inv['company_id'])) {
            return ['as' => 'delegate', 'on_behalf_of' => $assignee];
        }
        // Shared-mailbox and outside-party steps have no one to sign in as, so group-level users act for them.
        if ($this->scope->isGroupLevel($userId)) {
            return ['as' => 'group_level', 'on_behalf_of' => $assignee];
        }

        return null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: int|null}
     */
    private function actorContext(int $invoiceId, int $userId): array
    {
        $inv = $this->invoice($invoiceId);
        if (! in_array($inv['status'], self::ACTIVE, true)) {
            throw new WorkflowException('This invoice is not waiting for approval.');
        }
        $step = $this->currentStep($inv);
        if ($step === null) {
            throw new WorkflowException('This invoice has no current step.');
        }
        $role = $this->roleFor($inv, $step, $userId);
        if ($role === null) {
            throw new WorkflowException($this->isTeamStep($step) && $this->isTeamMember($step, $userId, (int) $inv['company_id'])
                ? 'Accept this invoice first. It is assigned to a team, and only the person who accepts it can act on it.'
                : 'You are not an approver for this step.');
        }

        return [$inv, $step, $role['as'], $role['on_behalf_of']];
    }

    // ------------------------------------------------------------------ moving along the route

    /**
     * Skips submit steps, tells notify steps, stops at the next approve/process/pay step, or finishes the route.
     * Runs inside the caller's transaction and returns what to announce afterwards.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return array{completed?: bool, step?: array<string, mixed>, step_label?: string, notify?: list<array<string, mixed>>}
     */
    private function moveToNextStep(int $invoiceId, array $steps, int $fromOrder, int $actorId): array
    {
        usort($steps, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $notify = [];
        $this->closeClaims($invoiceId);

        foreach ($steps as $s) {
            if ((int) $s['order'] <= $fromOrder) {
                continue;
            }
            if ($s['step_type'] === 'notify') {
                $notify[] = $s;
                continue;
            }
            if ($s['step_type'] === 'submit') {
                continue;
            }

            $this->db->table('inv_invoices')->where('id', $invoiceId)->update([
                'current_step_order' => (int) $s['order'],
                'status'             => match ($s['step_type']) {
                    'process' => 'approved',
                    'pay'     => 'processed',
                    default   => 'in_approval',
                },
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

            return ['step' => $s, 'step_label' => $this->label($s), 'notify' => $notify];
        }

        $lastType = '';
        foreach ($steps as $s) {
            if ((int) $s['order'] === $fromOrder) {
                $lastType = (string) $s['step_type'];
            }
        }
        $this->complete($invoiceId, $actorId, $lastType);

        return ['completed' => true, 'notify' => $notify];
    }

    /**
     * The route has no steps left: the invoice is paid when the last step was the pay step, otherwise processed.
     * A route with no process step (approvals only) is processed at this point, which is also when it is filed.
     */
    private function complete(int $invoiceId, int $actorId, string $lastType): void
    {
        $now = date('Y-m-d H:i:s');
        $inv = $this->db->table('inv_invoices')->where('id', $invoiceId)->get()->getRowArray();

        if (empty($inv['processed_at'])) {
            $this->markProcessed($invoiceId, $actorId);
        }

        $this->db->table('inv_invoices')->where('id', $invoiceId)->update([
            'status' => $lastType === 'pay' ? 'paid' : 'processed', 'completed_at' => $now, 'ended_at' => $now, 'current_step_order' => null,
            'updated_at' => $now,
        ]);
    }

    /**
     * The process step is done: stamp it. Runs once per submission (a resubmitted invoice starts again,
     * so submission clears the stamps). SharePoint filing is queued here in phase 2.
     */
    private function markProcessed(int $invoiceId, int $actorId): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('inv_invoices')->where('id', $invoiceId)->update(['processed_at' => $now, 'updated_at' => $now]);

        $this->audit->log('payables.processed', $actorId, 'invoice', $invoiceId);
    }

    /**
     * Tells the people the invoice is now with, and anyone on notify steps passed on the way.
     *
     * @param array<string, mixed> $events
     * @param array<string, mixed> $ctx
     */
    private function announce(int $invoiceId, array $events, array $ctx, string $eventForNextApprover): void
    {
        $inv = $this->db->table('inv_invoices')->select('company_id')->where('id', $invoiceId)->get()->getRowArray();

        if (isset($events['step'])) {
            $who = $this->approversOf($events['step'], (int) $inv['company_id']);
            $this->notifier->dispatch($eventForNextApprover, $invoiceId, $who['user_ids'], ['step_label' => $events['step_label']] + $ctx);
            if ($who['email'] !== null && $who['email'] !== '') {
                $this->notifier->dispatchToAddress($eventForNextApprover, $invoiceId, $who['email'], ['step_label' => $events['step_label']] + $ctx);
            }
        }

        foreach ($events['notify'] ?? [] as $n) {
            $who = $this->approversOf($n, (int) $inv['company_id']);
            $this->notifier->dispatch('payables.notify_step', $invoiceId, $who['user_ids'], ['step_label' => $this->label($n)] + $ctx);
            if ($who['email'] !== null && $who['email'] !== '') {
                $this->notifier->dispatchToAddress('payables.notify_step', $invoiceId, $who['email'], ['step_label' => $this->label($n)] + $ctx);
            }
        }
    }

    /**
     * @param array<string, mixed> $inv
     * @param array<string, mixed> $step
     * @param array<string, mixed> $ctx
     */
    private function tellOverride(string $as, array $inv, array $step, array $ctx, bool $includeSubmitter = true): void
    {
        if ($as !== 'group_level') {
            return;
        }

        $who = [];
        if (($step['assignee_kind'] ?? '') === 'user' && ! empty($step['user_id'])) {
            $who[] = (int) $step['user_id'];
        }
        if ($this->isTeamStep($step)) {
            $claim = $this->openClaim((int) $inv['id'], (int) $step['order']);
            array_push($who, ...($claim !== null ? [(int) $claim['claimed_by']] : $this->teamMemberIds($step, (int) $inv['company_id'])));
        }
        if ($includeSubmitter) {
            $who[] = (int) $inv['submitted_by'];
        }

        $this->notifier->dispatch('payables.override', (int) $inv['id'], $who, ['step_label' => $this->label($step)] + $ctx);
    }

    // ------------------------------------------------------------------ routes

    /**
     * Picks the standard route, or an extra-approval variant whose trigger applies.
     *
     * @param array<string, mixed> $inv
     *
     * @return array<string, mixed> route row plus "steps"
     */
    public function selectRoute(array $inv): array
    {
        $routes = $this->db->table('inv_routes')
            ->where(['company_id' => (int) $inv['company_id'], 'invoice_type_id' => (int) $inv['invoice_type_id'], 'active' => 1])
            ->get()->getResultArray();

        // Lowest variant order wins when several non-standard variants apply; unknown keys sort last.
        $order = (new RouteVariantModel())->sortOrders();
        usort($routes, static fn (array $a, array $b): int => [$order[$a['variant']] ?? PHP_INT_MAX, (int) $a['id']] <=> [$order[$b['variant']] ?? PHP_INT_MAX, (int) $b['id']]);

        $default = null;
        $chosen  = null;
        foreach ($routes as $r) {
            if ($r['variant'] === RouteVariantModel::STANDARD_KEY) {
                $default = $r;
                continue;
            }
            $applies = ($r['variant_trigger'] === 'manual_flag' && (int) $inv['extra_approval'] === 1)
                || ($r['variant_trigger'] === 'amount_threshold' && $inv['amount'] !== null && is_numeric((string) $r['trigger_value']) && (float) $inv['amount'] >= (float) $r['trigger_value']);
            if ($applies && $chosen === null) {
                $chosen = $r;
            }
        }

        $route = $chosen ?? $default;
        if ($route === null) {
            throw new WorkflowException('There is no active approval route for this company and invoice type. Ask an administrator to switch one on.');
        }

        $rows = $this->db->table('inv_route_steps s')
            ->select('s.*, j.name AS role')->join('inv_job_titles j', 'j.id = s.job_title_id', 'left')
            ->where('s.route_id', (int) $route['id'])->orderBy('s.step_order')->get()->getResultArray();

        $route['steps'] = array_map(static fn (array $s): array => [
            'order'         => (int) $s['step_order'],
            'step_type'     => (string) $s['step_type'],
            'role'          => (string) ($s['role'] ?? ''),
            'assignee_kind' => (string) $s['assignee_kind'],
            'user_id'       => $s['assignee_user_id'] !== null ? (int) $s['assignee_user_id'] : null,
            'team_id'       => ($s['team_id'] ?? null) !== null ? (int) $s['team_id'] : null,
            'label'        => (string) ($s['assignee_label'] ?? ''),
            'email'         => $s['assignee_email'],
            'sla_days'      => ($s['sla_days'] ?? null) !== null ? (int) $s['sla_days'] : null,
        ], $rows);

        return $route;
    }

    /**
     * @param array<string, mixed> $inv
     *
     * @return list<array<string, mixed>>
     */
    public function stepsOf(array $inv): array
    {
        $snap = json_decode((string) ($inv['route_snapshot'] ?? ''), true);

        return is_array($snap) ? ($snap['steps'] ?? []) : [];
    }

    /**
     * @param array<string, mixed> $step
     */
    public function label(array $step): string
    {
        $who = (string) ($step['label'] ?? '');

        return trim(($step['role'] ?? '') . ($who !== '' ? ' (' . $who . ')' : ''));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function invoice(int $invoiceId): array
    {
        $inv = $this->db->table('inv_invoices')->where('id', $invoiceId)->get()->getRowArray();
        if ($inv === null) {
            throw new WorkflowException('Invoice not found.');
        }

        return $inv;
    }

    /**
     * @param array<string, mixed> $inv
     */
    private function assertSubmitter(array $inv, int $userId): void
    {
        if ((int) $inv['submitted_by'] !== $userId) {
            throw new WorkflowException('Only the person who uploaded this invoice can do that.');
        }
    }

    private function assertCanSubmitFor(int $userId, int $companyId): void
    {
        $ok = $this->db->table('user_companies')->where(['user_id' => $userId, 'company_id' => $companyId, 'can_submit' => 1])->countAllResults() > 0
            && $this->db->table('companies')->where(['id' => $companyId, 'active' => 1])->countAllResults() > 0;

        if (! $ok) {
            throw new WorkflowException('You cannot submit invoices for that company.');
        }
    }

    private function assertTypeBelongs(int $typeId, int $companyId): void
    {
        if ($this->db->table('inv_invoice_types')->where(['id' => $typeId, 'company_id' => $companyId, 'active' => 1])->countAllResults() === 0) {
            throw new WorkflowException('Choose a valid invoice type for that company.');
        }
    }

    private function requireNote(string $note, string $message): string
    {
        $note = trim($note);
        if ($note === '') {
            throw new WorkflowException($message);
        }

        return mb_substr($note, 0, 5000);
    }

    private function insertNote(int $invoiceId, int $userId, string $kind, string $body): int
    {
        $this->db->table('inv_notes')->insert([
            'invoice_id' => $invoiceId, 'user_id' => $userId, 'kind' => $kind, 'body' => $body, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function record(int $invoiceId, ?int $stepOrder, int $actedBy, ?int $onBehalfOf, string $actedAs, string $action, ?int $noteId): void
    {
        $this->db->table('inv_actions')->insert([
            'invoice_id' => $invoiceId, 'step_order' => $stepOrder, 'acted_by' => $actedBy, 'on_behalf_of' => $onBehalfOf,
            'acted_as' => $actedAs, 'action' => $action, 'note_id' => $noteId, 'acted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function nameOf(int $userId): string
    {
        $u = $this->db->table('inv_users')->select('display_name, username')->where('id', $userId)->get()->getRowArray();

        return (string) (($u['display_name'] ?? '') ?: ($u['username'] ?? 'Someone'));
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array{payment_reference: string, payment_date: string}
     */
    private function paymentDetails(array $in): array
    {
        $ref  = mb_substr(trim((string) ($in['payment_reference'] ?? '')), 0, 100);
        $date = trim((string) ($in['payment_date'] ?? ''));
        $d    = $date !== '' ? date_create_from_format('Y-m-d', $date) : false;

        if ($ref === '') {
            throw new WorkflowException('Enter the payment reference before marking this invoice as paid.');
        }
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new WorkflowException('Enter the payment date (a valid date) before marking this invoice as paid.');
        }

        return ['payment_reference' => $ref, 'payment_date' => $date];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed> only the detail columns, cleaned
     */
    private function details(array $in): array
    {
        $text = static fn (mixed $v, int $max): ?string => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, $max);
        $date = static function (mixed $v): ?string {
            $v = trim((string) $v);

            return $v !== '' && ($t = date_create_from_format('Y-m-d', $v)) !== false && $t->format('Y-m-d') === $v ? $v : null;
        };

        $amount = trim((string) ($in['amount'] ?? ''));
        $amount = $amount !== '' ? str_replace([',', ' '], '', $amount) : '';

        $currency = strtoupper(trim((string) ($in['currency'] ?? '')));

        $supplier = $text($in['supplier'] ?? '', 200);
        // The name is kept on the invoice as a snapshot; the id links it to the shared supplier list (null when the
        // name is not on the list, e.g. an older invoice whose supplier has since been removed).
        $listed = $supplier !== null ? service('suppliers')->findByName($supplier) : null;

        return [
            'supplier'       => $supplier,
            'supplier_id'    => $listed !== null ? (int) $listed['id'] : null,
            'invoice_number' => $text($in['invoice_number'] ?? '', 100),
            'invoice_date'   => $date($in['invoice_date'] ?? ''),
            'due_date'       => $date($in['due_date'] ?? ''),
            'amount'         => $amount !== '' && is_numeric($amount) && (float) $amount >= 0 ? number_format((float) $amount, 2, '.', '') : null,
            'currency'       => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : (string) setting('InvoiceSettings.defaultCurrency'),
            'po_reference'   => $text($in['po_reference'] ?? '', 100),
            'extra_approval' => ! empty($in['extra_approval']) ? 1 : 0,
        ];
    }
}

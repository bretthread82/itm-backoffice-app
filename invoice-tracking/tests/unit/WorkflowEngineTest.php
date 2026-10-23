<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdminAccess;
use App\Services\DelegationResolver;
use App\Services\InvoiceFiles;
use App\Services\WorkflowEngine;
use App\Services\WorkflowException;
use Config\Services;
use Tests\Support\AppTestCase;
use Tests\Support\FakeMailTransport;

/**
 * @internal
 */
final class WorkflowEngineTest extends AppTestCase
{
    private const PAID = ['payment_reference' => 'EFT-1001', 'payment_date' => '2026-10-12'];

    private FakeMailTransport $mail;
    private WorkflowEngine $engine;
    private int $company;
    private int $type;
    private int $submitter;
    private int $acct;   // step 2
    private int $mgr;    // step 3
    private int $proc;   // step 4
    private int $groupUser;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mail = new FakeMailTransport();
        Services::injectMock('mailer', $this->mail);
        $this->engine = new WorkflowEngine($this->db);

        $this->adminId   = $this->makeUserWithEmail('Admin', 'admin@example.test', true);
        $this->company   = $this->makeCompany('ACME');
        $this->submitter = $this->makeUserWithEmail('Sue Submitter', 'sue@example.test');
        $this->acct      = $this->makeUserWithEmail('Alan Accountant', 'alan@example.test');
        $this->mgr       = $this->makeUserWithEmail('Maria Manager', 'maria@example.test');
        $this->proc      = $this->makeUserWithEmail('Pat Processor', 'pat@example.test');
        $this->groupUser = $this->makeUserWithEmail('Gary Group', 'gary@example.test');
        $this->join($this->submitter, $this->company, true);
        foreach ([$this->acct, $this->mgr, $this->proc] as $u) {
            $this->join($u, $this->company);
        }
        (new AdminAccess($this->db))->grantGroupLevel($this->adminId, $this->groupUser);

        $r          = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk', 'label' => 'Sue Submitter'],
            ['type' => 'approve', 'user' => $this->acct, 'role' => 'Accountant', 'label' => 'Alan Accountant'],
            ['type' => 'approve', 'user' => $this->mgr, 'role' => 'Manager', 'label' => 'Maria Manager'],
            ['type' => 'process', 'user' => $this->proc, 'role' => 'AP Processing', 'label' => 'Pat Processor'],
        ]);
        $this->type = $r['type'];
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        Services::resetSingle('mailer');
        parent::tearDown();
    }

    /** A complete draft with its PDF attached. */
    private function draft(array $override = []): int
    {
        $id = $this->engine->createDraft($this->submitter, $override + [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'supplier' => 'Acme Supplies',
            'invoice_number' => 'INV-1', 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '1,250.50', 'currency' => 'usd',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile(), 'inv.pdf');

        return $id;
    }

    private function submitted(array $override = []): int
    {
        $id = $this->draft($override);
        $this->engine->submit($id, $this->submitter);

        return $id;
    }

    private function bell(int $userId, string $event): int
    {
        return $this->db->table('user_notifications')->where(['user_id' => $userId, 'event' => $event])->countAllResults();
    }

    // -------------------------------------------------------------- drafts and submission

    public function testOnlyUsersWhoCanSubmitMayCreateDrafts(): void
    {
        $this->expectException(WorkflowException::class);
        $this->engine->createDraft($this->acct, ['company_id' => $this->company, 'invoice_type_id' => $this->type]);
    }

    public function testDetailsAreCleaned(): void
    {
        $inv = $this->invoiceRow($this->draft());

        $this->assertEqualsWithDelta(1250.50, (float) $inv['amount'], 0.001); // "1,250.50" typed with a thousands separator
        $this->assertSame('USD', $inv['currency']);
        $this->assertSame('draft', $inv['status']);
    }

    public function testSubmitListsEverythingThatIsMissing(): void
    {
        $id = $this->engine->createDraft($this->submitter, ['company_id' => $this->company, 'invoice_type_id' => $this->type]);

        try {
            $this->engine->submit($id, $this->submitter);
            $this->fail('Expected a WorkflowException.');
        } catch (WorkflowException $e) {
            foreach (['Supplier', 'Invoice number', 'Invoice date', 'Due date', 'Amount', 'PDF'] as $needle) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
        $this->assertSame('draft', $this->invoiceRow($id)['status']);
    }

    public function testSubmitStartsTheRouteAtTheFirstApprovalStepAndSnapshotsIt(): void
    {
        $id  = $this->submitted();
        $inv = $this->invoiceRow($id);

        $this->assertSame('in_approval', $inv['status']);
        $this->assertSame(2, (int) $inv['current_step_order']); // step 1 (submit) is the submitter's own
        $snap = json_decode($inv['route_snapshot'], true);
        $this->assertCount(4, $snap['steps']);
        $this->assertSame('submit', $this->actions($id)[0]['action']);

        // The approver is told and the submitter gets a receipt.
        $this->assertSame(1, $this->bell($this->acct, 'invoice.submitted'));
        $this->assertSame(1, $this->bell($this->submitter, 'invoice.received'));
        $this->assertCount(1, $this->mail->to('alan@example.test'));
        $this->assertCount(1, $this->mail->to('sue@example.test'));
    }

    public function testEditingTheRouteLaterDoesNotChangeAnInvoiceInFlight(): void
    {
        $id = $this->submitted();
        $this->db->table('route_steps')->where('step_order', 2)->update(['assignee_user_id' => $this->proc]);

        $this->assertSame($this->acct, (int) $this->engine->stepsOf($this->invoiceRow($id))[1]['user_id']);
        $this->assertTrue($this->engine->canAct($id, $this->acct)['can_act']);
        $this->assertFalse($this->engine->canAct($id, $this->proc)['can_act']);
    }

    public function testSubmitFailsWithoutAnActiveRoute(): void
    {
        $this->db->table('routes')->update(['active' => 0]);
        $id = $this->draft();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('no active approval route');
        $this->engine->submit($id, $this->submitter);
    }

    public function testOnlyTheSubmitterCanSubmit(): void
    {
        $id = $this->draft();

        $this->expectException(WorkflowException::class);
        $this->engine->submit($id, $this->acct);
    }

    // -------------------------------------------------------------- approving

    public function testApproveWalksTheRouteToCompletion(): void
    {
        $id = $this->submitted();

        $this->engine->approve($id, $this->acct);
        $this->assertSame(3, (int) $this->invoiceRow($id)['current_step_order']);
        $this->assertSame('in_approval', $this->invoiceRow($id)['status']);
        $this->assertSame(1, $this->bell($this->mgr, 'invoice.submitted'));
        $this->assertSame(1, $this->bell($this->submitter, 'invoice.step_approved'));

        $this->engine->approve($id, $this->mgr);
        $inv = $this->invoiceRow($id);
        $this->assertSame('approved', $inv['status']);                // only the process step remains
        $this->assertSame(4, (int) $inv['current_step_order']);

        $this->engine->approve($id, $this->proc, null, self::PAID);
        $inv = $this->invoiceRow($id);
        $this->assertSame('processed', $inv['status']);              // this route has no pay step, so it ends here
        $this->assertNull($inv['current_step_order']);
        $this->assertNotNull($inv['completed_at']);
        $this->assertNotNull($inv['ended_at']);
        $this->assertSame(1, $this->bell($this->submitter, 'invoice.processed'));

        $actions = array_column($this->actions($id), 'action');
        $this->assertSame(['submit', 'approve', 'approve', 'approve'], $actions);
    }

    public function testCompletionQueuesSharePointWithNotConfiguredWhenNoConnectionIsEnabled(): void
    {
        $id = $this->submitted();
        foreach ([$this->acct, $this->mgr, $this->proc] as $u) {
            $this->engine->approve($id, $u, null, self::PAID);
        }

        $this->assertSame('not_configured', $this->invoiceRow($id)['sync_status']);
        $queue = $this->db->table('sync_queue')->where('invoice_id', $id)->get()->getRowArray();
        $this->assertSame('not_configured', $queue['status']);
    }

    public function testCompletionIsPendingWhenAConnectionIsEnabled(): void
    {
        $this->db->table('sharepoint_connections')->insert([
            'name' => 'Main', 'enabled' => 1, 'is_default' => 1, 'tenant_id' => 'tenant', 'client_id' => 'client',
            'client_secret' => 'sealed', 'site_url' => 'https://example.sharepoint.com/sites/x',
        ]);
        $id = $this->submitted();
        foreach ([$this->acct, $this->mgr, $this->proc] as $u) {
            $this->engine->approve($id, $u, null, self::PAID);
        }

        $this->assertSame('pending', $this->invoiceRow($id)['sync_status']);
    }

    public function testAnEnabledButIncompleteConnectionNeverCountsAsConfigured(): void
    {
        $this->db->table('sharepoint_connections')->insert(['name' => 'Half done', 'enabled' => 1, 'is_default' => 1]);
        $id = $this->submitted();
        foreach ([$this->acct, $this->mgr, $this->proc] as $u) {
            $this->engine->approve($id, $u, null, self::PAID);
        }

        $this->assertSame('not_configured', $this->invoiceRow($id)['sync_status']);
    }

    public function testADisabledPlaceholderConnectionNeverCountsAsConfigured(): void
    {
        $this->db->table('sharepoint_connections')->insert(['name' => 'Placeholder', 'enabled' => 0, 'is_default' => 1]);
        $id = $this->submitted();
        foreach ([$this->acct, $this->mgr, $this->proc] as $u) {
            $this->engine->approve($id, $u, null, self::PAID);
        }

        $this->assertSame('not_configured', $this->invoiceRow($id)['sync_status']);
    }

    public function testSomeoneElseCannotApprove(): void
    {
        $id = $this->submitted();

        foreach ([$this->mgr, $this->proc, $this->submitter] as $u) {
            $this->assertFalse($this->engine->canAct($id, $u)['can_act']);
        }
        $this->expectException(WorkflowException::class);
        $this->engine->approve($id, $this->mgr); // manager's step is not yet current
    }

    public function testAnInactiveUserCannotApprove(): void
    {
        $id = $this->submitted();
        $this->db->table('users')->where('id', $this->acct)->update(['active' => 0]);

        $this->assertFalse($this->engine->canAct($id, $this->acct)['can_act']);
    }

    public function testNotifyStepsAreToldAndSkipped(): void
    {
        $fyi = $this->makeUserWithEmail('Fiona FYI', 'fiona@example.test');
        $this->join($fyi, $this->company);
        $member = $this->makeUserWithEmail('Tina Team', 'tina@example.test');
        $this->join($member, $this->company);
        $team = $this->makeTeam('Finance inbox', [$member], true, 'finance@example.test');
        $r = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => $this->acct, 'role' => 'Accountant'],
            ['type' => 'notify', 'user' => $fyi, 'role' => 'Observer'],
            ['type' => 'notify', 'team' => $team, 'label' => 'Finance inbox', 'role' => 'Mailbox'],
        ], 'With copies');

        $id = $this->engine->createDraft($this->submitter, [
            'company_id' => $this->company, 'invoice_type_id' => $r['type'], 'supplier' => 'S', 'invoice_number' => 'N1',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'amount' => '10',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile(), 'a.pdf');
        $this->engine->submit($id, $this->submitter);
        $this->engine->approve($id, $this->acct);

        $this->assertSame('processed', $this->invoiceRow($id)['status']);
        $this->assertSame(1, $this->bell($fyi, 'invoice.notify_step'));
        // A notified team reaches every member and its group mailbox.
        $this->assertCount(1, $this->mail->to('finance@example.test'));
        $this->assertSame(1, $this->bell($member, 'invoice.notify_step'));
    }

    // -------------------------------------------------------------- delegation

    public function testADelegateCanApproveAndIsRecordedAsSuch(): void
    {
        $deputy = $this->makeUserWithEmail('Dee Deputy', 'dee@example.test');
        $this->join($deputy, $this->company);
        (new DelegationResolver($this->db))->create($this->acct, $this->acct, $deputy, null, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 86400), 'On leave');

        $id = $this->submitted();
        $this->assertSame('delegate', $this->engine->canAct($id, $deputy)['as']);
        // The delegate is told too.
        $this->assertSame(1, $this->bell($deputy, 'invoice.submitted'));

        $this->engine->approve($id, $deputy);

        $action = $this->actions($id)[1];
        $this->assertSame('delegate', $action['acted_as']);
        $this->assertSame($this->acct, (int) $action['on_behalf_of']);
        $this->assertSame($deputy, (int) $action['acted_by']);
    }

    public function testExpiredOrRevokedOrUnrelatedDelegationsDoNotWork(): void
    {
        $deputy = $this->makeUser('Dee Deputy');
        $this->join($deputy, $this->company);
        $svc = new DelegationResolver($this->db);

        $expired = $svc->create($this->acct, $this->acct, $deputy, null, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600), null);
        $id      = $this->submitted();
        $this->assertFalse($this->engine->canAct($id, $deputy)['can_act']);

        $live = $svc->create($this->acct, $this->acct, $deputy, null, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), null);
        $this->assertTrue($this->engine->canAct($id, $deputy)['can_act']);
        $svc->revoke($this->acct, $live);
        $this->assertFalse($this->engine->canAct($id, $deputy)['can_act']);

        // A delegation made by someone else (the manager) does not give access to the accountant's step.
        $svc->create($this->mgr, $this->mgr, $deputy, null, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), null);
        $this->assertFalse($this->engine->canAct($id, $deputy)['can_act']);
        $this->assertNotNull($expired);
    }

    public function testDelegationIsLimitedToTheChosenCompany(): void
    {
        $other  = $this->makeCompany('OTHER');
        $deputy = $this->makeUser('Dee Deputy');
        $this->join($deputy, $this->company);
        $this->join($deputy, $other);

        (new DelegationResolver($this->db))->create($this->acct, $this->acct, $deputy, $other, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), null);

        $this->assertFalse($this->engine->canAct($this->submitted(), $deputy)['can_act']); // ACME invoice, delegation is for OTHER
    }

    public function testADelegationCannotGrantAccessToACompanyTheDelegateCannotSee(): void
    {
        $outsider = $this->makeUser('Out Sider'); // member of nothing

        $this->expectException(WorkflowException::class);
        (new DelegationResolver($this->db))->create($this->acct, $this->acct, $outsider, $this->company, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3600), null);
    }

    public function testUsersCanOnlyDelegateTheirOwnApprovalsUnlessAdmin(): void
    {
        $svc = new DelegationResolver($this->db);
        $when = [date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3600)];

        try {
            $svc->create($this->mgr, $this->acct, $this->proc, null, ...$when, ...[null]);
            $this->fail('A non-admin must not delegate someone else\'s approvals.');
        } catch (WorkflowException) {
            $this->assertSame(0, $this->db->table('delegations')->countAllResults());
        }

        $id = $svc->create($this->adminId, $this->acct, $this->proc, null, ...$when, ...[null]);
        $this->assertGreaterThan(0, $id);
    }

    // -------------------------------------------------------------- group-level accountability

    public function testGroupLevelUserMustGiveAReasonAndIsFlaggedAndTold(): void
    {
        $id = $this->submitted();

        $check = $this->engine->canAct($id, $this->groupUser);
        $this->assertTrue($check['can_act']);
        $this->assertSame('group_level', $check['as']);
        $this->assertTrue($check['reason_required']);

        try {
            $this->engine->approve($id, $this->groupUser);
            $this->fail('A reason is mandatory for a group-level approval.');
        } catch (WorkflowException $e) {
            $this->assertSame(2, (int) $this->invoiceRow($id)['current_step_order']);
        }

        $this->engine->approve($id, $this->groupUser, 'Alan is away and the supplier is chasing.');

        $action = $this->actions($id)[1];
        $this->assertSame('group_level', $action['acted_as']);
        $this->assertSame($this->groupUser, (int) $action['acted_by']);
        $this->assertSame($this->acct, (int) $action['on_behalf_of']);

        $note = $this->db->table('invoice_notes')->where('id', $action['note_id'])->get()->getRowArray();
        $this->assertSame('override_reason', $note['kind']);
        $this->assertSame($this->groupUser, (int) $note['user_id']);

        // The named approver and the submitter are told it was a group-level action.
        $this->assertSame(1, $this->bell($this->acct, 'invoice.override'));
        $this->assertSame(1, $this->bell($this->submitter, 'invoice.override'));
        $this->assertSame(3, (int) $this->invoiceRow($id)['current_step_order']);
    }

    public function testNamedApproverWithoutTheApproveRightCannotAct(): void
    {
        $id = $this->submitted();
        $this->db->table('user_companies')->where(['user_id' => $this->acct, 'company_id' => $this->company])->update(['can_approve' => 0]);

        $this->assertFalse($this->engine->canAct($id, $this->acct)['can_act']);
        foreach (['approve', 'returnForChanges', 'reject'] as $method) {
            try {
                $method === 'approve' ? $this->engine->approve($id, $this->acct) : $this->engine->{$method}($id, $this->acct, 'No.');
                $this->fail("{$method} must be refused without the approve right.");
            } catch (WorkflowException $e) {
                $this->assertSame('You are not an approver for this step.', $e->getMessage());
            }
        }
        $this->assertSame(2, (int) $this->invoiceRow($id)['current_step_order']);

        // Group-level users still act, with the usual reason.
        $this->assertSame('group_level', $this->engine->canAct($id, $this->groupUser)['as']);
    }

    public function testSubmitterWithoutTheSubmitRightCannotCreateADraft(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('You cannot submit invoices for that company.');
        $this->engine->createDraft($this->acct, [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'supplier' => 'X',
            'invoice_number' => 'INV-9', 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '1', 'currency' => 'usd',
        ]);
    }

    public function testGroupLevelUserApprovingAsTheNamedApproverIsNotAnOverride(): void
    {
        (new AdminAccess($this->db))->grantGroupLevel($this->adminId, $this->acct); // Alan is also group-level
        $id = $this->submitted();

        $this->assertSame('normal', $this->engine->canAct($id, $this->acct)['as']);
        $this->engine->approve($id, $this->acct); // no reason needed

        $this->assertSame('normal', $this->actions($id)[1]['acted_as']);
        $this->assertSame(0, $this->bell($this->submitter, 'invoice.override'));
    }

    public function testGroupLevelUsersCanActOnSharedMailboxSteps(): void
    {
        $r = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk'],
            ['type' => 'approve', 'kind' => 'external', 'label' => 'Aspireqs', 'role' => 'Quantity Surveyor'],
        ], 'With QS');
        $id = $this->engine->createDraft($this->submitter, [
            'company_id' => $this->company, 'invoice_type_id' => $r['type'], 'supplier' => 'S', 'invoice_number' => 'QS1',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'amount' => '99',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile(), 'a.pdf');
        $this->engine->submit($id, $this->submitter);

        $this->assertFalse($this->engine->canAct($id, $this->acct)['can_act']);
        $this->assertTrue($this->engine->canAct($id, $this->groupUser)['can_act']);
        $this->engine->approve($id, $this->groupUser, 'QS confirmed by phone.');
        $this->assertSame('processed', $this->invoiceRow($id)['status']);
        $this->assertNull($this->actions($id)[1]['on_behalf_of']);
    }

    // -------------------------------------------------------------- return, reject, resubmit

    public function testReturnNeedsANoteAndSendsItBackToTheSubmitter(): void
    {
        $id = $this->submitted();

        try {
            $this->engine->returnForChanges($id, $this->acct, '   ');
            $this->fail('A note is required.');
        } catch (WorkflowException) {
            $this->assertSame('in_approval', $this->invoiceRow($id)['status']);
        }

        $this->engine->returnForChanges($id, $this->acct, 'Wrong PO number.');
        $this->assertSame('returned', $this->invoiceRow($id)['status']);
        $this->assertNull($this->invoiceRow($id)['ended_at']);
        $this->assertSame(1, $this->bell($this->submitter, 'invoice.returned'));
        $mail = $this->mail->to('sue@example.test');
        $this->assertStringContainsString('Wrong PO number.', end($mail)->html);
    }

    public function testReturnToThePreviousApproverMovesTheInvoiceBackOneStep(): void
    {
        $id = $this->submitted();
        $this->engine->approve($id, $this->acct);
        $this->assertSame(2, (int) $this->invoiceRow($id)['current_step_order']);

        $can = $this->engine->canAct($id, $this->mgr);
        $this->assertSame(1, (int) $can['previous_step']['order']);

        try {
            $this->engine->returnToPreviousApprover($id, $this->mgr, ' ');
            $this->fail('A note is required.');
        } catch (WorkflowException) {
            $this->assertSame(2, (int) $this->invoiceRow($id)['current_step_order']);
        }

        $this->engine->returnToPreviousApprover($id, $this->mgr, 'Check the coding.');

        $row = $this->invoiceRow($id);
        $this->assertSame('in_approval', $row['status']);
        $this->assertSame(1, (int) $row['current_step_order']);
        $this->assertSame(['submit', 'approve', 'return_previous'], array_column($this->actions($id), 'action'));
        $this->assertSame(1, $this->bell($this->acct, 'invoice.returned_step'));
        $this->assertSame(0, $this->bell($this->submitter, 'invoice.returned'));

        // The earlier approver can act again, and the invoice then moves forward as normal.
        $this->engine->approve($id, $this->acct);
        $this->assertSame(2, (int) $this->invoiceRow($id)['current_step_order']);
    }

    public function testThereIsNoPreviousApproverOnTheFirstStep(): void
    {
        $id = $this->submitted();

        $this->assertNull($this->engine->canAct($id, $this->acct)['previous_step']);
        $this->expectException(WorkflowException::class);
        $this->engine->returnToPreviousApprover($id, $this->acct, 'Nope.');
    }

    public function testRejectNeedsANoteAndEndsTheWorkflowButCanBeResubmitted(): void
    {
        $id = $this->submitted();

        $this->expectExceptionObject(new WorkflowException('Give a reason for rejecting the invoice.'));
        try {
            $this->engine->reject($id, $this->acct, '');
        } finally {
            $this->assertSame('in_approval', $this->invoiceRow($id)['status']);
        }
    }

    public function testRejectedInvoiceCanBeEditedAndResubmittedWithANote(): void
    {
        $id = $this->submitted();
        $this->engine->reject($id, $this->acct, 'Duplicate invoice.');

        $inv = $this->invoiceRow($id);
        $this->assertSame('rejected', $inv['status']);
        $this->assertNotNull($inv['ended_at']);
        $this->assertSame(1, $this->bell($this->submitter, 'invoice.rejected'));

        // Resubmitting needs a note.
        try {
            $this->engine->resubmit($id, $this->submitter, '');
            $this->fail('A resubmission note is required.');
        } catch (WorkflowException) {
            $this->assertSame('rejected', $this->invoiceRow($id)['status']);
        }

        $this->engine->updateDetails($id, $this->submitter, ['invoice_number' => 'INV-1B', 'supplier' => 'Acme Supplies', 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '1250.50']);
        $this->engine->resubmit($id, $this->submitter, 'Corrected the invoice number.');

        $inv = $this->invoiceRow($id);
        $this->assertSame('in_approval', $inv['status']);
        $this->assertNull($inv['ended_at']);
        $this->assertSame(2, (int) $inv['current_step_order']);          // restarts at the first approval step
        $this->assertSame('INV-1B', $inv['invoice_number']);
        $this->assertSame(['submit', 'reject', 'resubmit'], array_column($this->actions($id), 'action')); // history kept
        $this->assertSame(2, $this->bell($this->acct, 'invoice.submitted') + $this->bell($this->acct, 'invoice.resubmitted'));
        $this->assertSame(1, $this->bell($this->acct, 'invoice.resubmitted'));

        $kinds = array_column($this->db->table('invoice_notes')->where('invoice_id', $id)->orderBy('id')->get()->getResultArray(), 'kind');
        $this->assertSame(['reject_reason', 'resubmit_reason'], $kinds);
    }

    public function testAnActiveInvoiceCannotBeEditedOrResubmitted(): void
    {
        $id = $this->submitted();

        $this->expectException(WorkflowException::class);
        $this->engine->updateDetails($id, $this->submitter, ['supplier' => 'Changed']);
    }

    public function testCancelOnlyFromEditableStates(): void
    {
        $draft = $this->draft();
        $this->engine->cancel($draft, $this->submitter);
        $this->assertSame('cancelled', $this->invoiceRow($draft)['status']);
        $this->assertNotNull($this->invoiceRow($draft)['ended_at']);

        $live = $this->submitted();
        $this->expectException(WorkflowException::class);
        $this->engine->cancel($live, $this->submitter);
    }

    // -------------------------------------------------------------- notes

    public function testNotesRequireAccessAndTextAndAreRecorded(): void
    {
        $id = $this->submitted();

        $this->engine->addNote($id, $this->acct, 'Checking with the supplier.');
        $this->assertSame(1, $this->db->table('invoice_notes')->where(['invoice_id' => $id, 'kind' => 'note'])->countAllResults());

        $outsider = $this->makeUser('Out Sider');
        try {
            $this->engine->addNote($id, $outsider, 'Hello');
            $this->fail('No access, no notes.');
        } catch (WorkflowException) {
            $this->assertSame(1, $this->db->table('invoice_notes')->where('invoice_id', $id)->countAllResults());
        }

        $this->expectException(WorkflowException::class);
        $this->engine->addNote($id, $this->acct, '  ');
    }

    // -------------------------------------------------------------- route variants

    public function testManualFlagVariantIsUsedOnlyWhenActiveAndFlagged(): void
    {
        $extraUser = $this->makeUserWithEmail('Eve Extra', 'eve@example.test');
        $this->join($extraUser, $this->company);
        $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => $this->acct, 'role' => 'Accountant'],
            ['type' => 'approve', 'user' => $extraUser, 'role' => 'Group Treasurer'],
        ], 'Standard', 'extra_approval', false, 'manual_flag');

        $this->assertCount(4, $this->engine->selectRoute(['company_id' => $this->company, 'invoice_type_id' => $this->type, 'extra_approval' => 1, 'amount' => '5'])['steps']); // inactive variant ignored

        $this->db->table('routes')->where('variant', 'extra_approval')->update(['active' => 1]);
        $this->assertCount(3, $this->engine->selectRoute(['company_id' => $this->company, 'invoice_type_id' => $this->type, 'extra_approval' => 1, 'amount' => '5'])['steps']);
        $this->assertCount(4, $this->engine->selectRoute(['company_id' => $this->company, 'invoice_type_id' => $this->type, 'extra_approval' => 0, 'amount' => '5'])['steps']);
    }

    public function testAmountThresholdVariant(): void
    {
        $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'role' => 'Clerk'],
            ['type' => 'approve', 'user' => $this->mgr, 'role' => 'Manager'],
        ], 'Standard', 'extra_approval', true, 'amount_threshold', '5000');

        $pick = fn (string $amount): int => count($this->engine->selectRoute(['company_id' => $this->company, 'invoice_type_id' => $this->type, 'extra_approval' => 0, 'amount' => $amount])['steps']);

        $this->assertSame(4, $pick('4999.99')); // standard route
        $this->assertSame(2, $pick('5000.00')); // threshold reached: variant
        $this->assertSame(2, $pick('12000'));
    }

    public function testCustomVariantsAreOrderedBySortOrderAndFallBackToStandard(): void
    {
        $this->db->table('route_variants')->insertBatch([
            ['variant_key' => 'big', 'name' => 'Big spend', 'is_standard' => 0, 'active' => 1, 'sort_order' => 20],
            ['variant_key' => 'huge', 'name' => 'Huge spend', 'is_standard' => 0, 'active' => 1, 'sort_order' => 5],
        ]);
        $steps = static fn (int $n): array => array_slice([
            ['type' => 'submit', 'role' => 'Clerk'], ['type' => 'approve', 'role' => 'Manager'], ['type' => 'approve', 'role' => 'Director'],
        ], 0, $n);
        $withUsers = function (int $n) use ($steps): array {
            return array_map(fn (array $s): array => $s + ['user' => $s['type'] === 'submit' ? $this->submitter : $this->mgr], $steps($n));
        };
        $this->makeRoute($this->company, $withUsers(2), 'Standard', 'big', true, 'amount_threshold', '1000');
        $this->makeRoute($this->company, $withUsers(3), 'Standard', 'huge', true, 'amount_threshold', '1000');

        $pick = fn (string $amount): int => count($this->engine->selectRoute(['company_id' => $this->company, 'invoice_type_id' => $this->type, 'extra_approval' => 0, 'amount' => $amount])['steps']);

        $this->assertSame(4, $pick('999'));   // neither applies: standard route
        $this->assertSame(3, $pick('1000'));  // both apply: "huge" (order 5) beats "big" (order 20)
    }

    // -------------------------------------------------------------- email behaviour

    public function testUsersCanSwitchEmailOffButNeverTheBell(): void
    {
        $this->db->table('notification_prefs')->insert(['user_id' => $this->acct, 'event' => 'invoice.submitted', 'email' => 0, 'daily_digest' => 1]);

        $this->submitted();

        $this->assertSame(1, $this->bell($this->acct, 'invoice.submitted'));
        $this->assertCount(0, $this->mail->to('alan@example.test'));
        $this->assertCount(1, $this->mail->to('sue@example.test')); // others unaffected
    }

    public function testHighPriorityTypeEmailsApproversEvenWhenTheyTurnedItOff(): void
    {
        $this->db->table('invoice_types')->where('id', $this->type)->update(['high_priority' => 1]);
        $this->db->table('notification_prefs')->insert(['user_id' => $this->acct, 'event' => 'invoice.submitted', 'email' => 0, 'daily_digest' => 1]);

        $id = $this->submitted();

        $this->assertSame(1, $this->invoiceRow($id)['high_priority']);
        $this->assertCount(1, $this->mail->to('alan@example.test'));
        $this->assertStringStartsWith('HIGH PRIORITY:', $this->mail->to('alan@example.test')[0]->subject);
        $this->assertSame(1, $this->db->table('user_notifications')->where(['user_id' => $this->acct, 'event' => 'invoice.submitted', 'priority' => 1])->countAllResults());

        // Changing the type afterwards does not alter the invoice already in flight.
        $this->db->table('invoice_types')->where('id', $this->type)->update(['high_priority' => 0]);
        $this->assertSame(1, $this->invoiceRow($id)['high_priority']);
    }

    public function testAnEmailOutageNeverBlocksTheWorkflowAndIsRetried(): void
    {
        $this->mail->fail = true;
        $id               = $this->submitted();

        $this->assertSame('in_approval', $this->invoiceRow($id)['status']);
        $failed = $this->db->table('notifications')->where('status', 'failed')->countAllResults();
        $this->assertGreaterThan(0, $failed);
        $this->assertSame(1, $this->bell($this->acct, 'invoice.submitted')); // the bell still works

        $this->mail->fail = false;
        $result           = (new \App\Services\NotificationService($this->db))->sendPending();
        $this->assertSame($failed, $result['sent']);
        $this->assertSame(0, $this->db->table('notifications')->where('status', 'failed')->countAllResults());
        $this->assertCount(1, $this->mail->to('alan@example.test'));
    }

    public function testActorIsNotNotifiedAboutTheirOwnAction(): void
    {
        $id = $this->submitted();
        $this->engine->approve($id, $this->acct);

        $this->assertSame(0, $this->bell($this->acct, 'invoice.step_approved'));
        $this->assertSame(0, $this->bell($this->acct, 'invoice.processed'));
    }

    public function testProcessStepNeedsNoPaymentDetails(): void
    {
        $id = $this->submitted();
        $this->engine->approve($id, $this->acct);
        $this->engine->approve($id, $this->mgr);
        $this->engine->approve($id, $this->proc);

        $inv = $this->invoiceRow($id);
        $this->assertSame('processed', $inv['status']);
        $this->assertNotNull($inv['processed_at']);
        $this->assertNull($inv['payment_reference'], 'payment is recorded at the pay step (see ProcessedPaidFlowTest)');
    }

    public function testApproveStepIgnoresPaymentDetails(): void
    {
        $id = $this->submitted();
        $this->engine->approve($id, $this->acct, null, self::PAID);

        $this->assertNull($this->invoiceRow($id)['payment_reference']);
    }
}

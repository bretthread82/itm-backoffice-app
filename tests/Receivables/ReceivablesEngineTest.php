<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\ReceivablesEngine;
use App\Core\Receivables\ReceivablesException;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * The customer invoice state machine: rights, the route, mandatory notes, history and notifications.
 *
 * @internal
 */
final class ReceivablesEngineTest extends ReceivablesTestCase
{
    private ReceivablesEngine $engine;
    private int $company;
    private int $raiser;
    private int $approver;
    private int $issuer;
    private int $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine   = new ReceivablesEngine($this->db);
        $this->company  = $this->makeCompany('ACME');
        $this->raiser   = $this->makeUserNamed('Rae Raiser');
        $this->approver = $this->makeUserNamed('Alan Approver');
        $this->issuer   = $this->makeUserNamed('Ivy Issuer');

        $this->grant($this->raiser, $this->company, ['submit']);
        $this->grant($this->approver, $this->company, ['approve']);
        $this->grant($this->issuer, $this->company, ['approve']);

        $route      = $this->makeArRoute($this->company, [
            ['type' => 'submit', 'user' => $this->raiser, 'role' => 'Raiser', 'label' => 'Rae'],
            ['type' => 'approve', 'user' => $this->approver, 'role' => 'Approver', 'label' => 'Alan'],
            ['type' => 'issue', 'user' => $this->issuer, 'role' => 'Billing', 'label' => 'Ivy'],
        ]);
        $this->type = $route['type'];
    }

    /** A draft with a PDF attached, ready to submit. */
    private function draft(array $over = [], ?int $by = null): int
    {
        $by ??= $this->raiser;
        $id   = $this->engine->createDraft($by, $this->arDetails($this->company, $this->type, $over));
        (new \App\Core\Receivables\ArFiles($this->db))->store($id, $by, $this->pdfFile(), 'invoice.pdf');

        return $id;
    }

    private function inApproval(): int
    {
        $id = $this->draft();
        $this->engine->submit($id, $this->raiser);

        return $id;
    }

    private function failsWith(string $contains, callable $do): void
    {
        try {
            $do();
            $this->fail('Expected a ReceivablesException containing: ' . $contains);
        } catch (ReceivablesException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ the happy path

    public function testRaiseApproveAndIssue(): void
    {
        $id = $this->draft();
        $this->assertSame('draft', $this->arRow($id)['status']);

        $this->engine->submit($id, $this->raiser);
        $row = $this->arRow($id);
        $this->assertSame('in_approval', $row['status']);
        $this->assertSame(2, (int) $row['current_step_order']);
        $this->assertNotNull($row['submitted_at']);
        $this->assertSame(1, $this->bell($this->approver, 'ar.submitted'));
        $this->assertSame(1, $this->bell($this->raiser, 'ar.received'));

        $this->engine->approve($id, $this->approver, 'Looks right');
        $row = $this->arRow($id);
        $this->assertSame('approved', $row['status'], 'Waiting at the issue step.');
        $this->assertSame(3, (int) $row['current_step_order']);
        $this->assertSame(1, $this->bell($this->issuer, 'ar.submitted'));
        $this->assertSame(1, $this->bell($this->raiser, 'ar.step_approved'));

        $this->engine->approve($id, $this->issuer, null, ['sent_to' => '  accounts@harbourfoods.example ']);
        $row = $this->arRow($id);
        $this->assertSame('issued', $row['status']);
        $this->assertSame('accounts@harbourfoods.example', $row['sent_to']);
        $this->assertNotNull($row['issued_at']);
        $this->assertNotNull($row['completed_at']);
        $this->assertNull($row['ended_at'], 'Issued is not the end: collection ends it.');
        $this->assertNull($row['current_step_order']);
        $this->assertSame(1, $this->bell($this->raiser, 'ar.issued'));

        $this->assertSame(['submit', 'approve', 'approve'], array_column($this->arActions($id), 'action'));
    }

    public function testNotifyStepsAreToldAndSkipped(): void
    {
        $fyi = $this->makeUserNamed('Fay Fyi');
        $co  = $this->makeCompany('BETA');
        $this->grant($this->raiser, $co, ['submit']);
        $this->grant($this->issuer, $co, ['approve']);
        $route = $this->makeArRoute($co, [
            ['type' => 'issue', 'user' => $this->issuer, 'label' => 'Ivy'],
            ['type' => 'notify', 'user' => $fyi, 'label' => 'Fay'],
        ]);

        $id = $this->engine->createDraft($this->raiser, $this->arDetails($co, $route['type']));
        (new \App\Core\Receivables\ArFiles($this->db))->store($id, $this->raiser, $this->pdfFile(), 'invoice.pdf');
        $this->engine->submit($id, $this->raiser);
        $this->engine->approve($id, $this->issuer, null, ['sent_to' => 'post']);

        $this->assertSame('issued', $this->arRow($id)['status']);
        $this->assertSame(1, $this->bell($fyi, 'ar.notify_step'));
    }

    // ------------------------------------------------------------------ rights

    public function testRaisingNeedsTheReceivablesRightNotTheInvoicesRight(): void
    {
        $payablesOnly = $this->makeUserNamed('Pat Payables');
        $this->join($payablesOnly, $this->company, true, true); // invoices can_submit / can_approve

        $this->failsWith('cannot raise', fn () => $this->engine->createDraft($payablesOnly, $this->arDetails($this->company, $this->type)));

        $stranger = $this->makeUserNamed('Sam Stranger');
        $this->failsWith('cannot raise', fn () => $this->engine->createDraft($stranger, $this->arDetails($this->company, $this->type)));
    }

    public function testInactiveCompanyCannotBeRaisedFor(): void
    {
        $this->db->table('companies')->where('id', $this->company)->update(['active' => 0]);

        $this->failsWith('cannot raise', fn () => $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type)));
    }

    public function testOnlyTheNamedApproverWithTheRightCanApprove(): void
    {
        $id = $this->inApproval();

        $other = $this->makeUserNamed('Olga Other');
        $this->grant($other, $this->company, ['approve']);
        $this->failsWith('not an approver', fn () => $this->engine->approve($id, $other));
        $this->failsWith('not an approver', fn () => $this->engine->approve($id, $this->raiser));

        // The named approver loses the company right: they can no longer act.
        $this->db->table('user_company_capabilities')->where(['user_id' => $this->approver, 'capability' => 'receivables.approve'])->delete();
        $this->failsWith('not an approver', fn () => $this->engine->approve($id, $this->approver));
    }

    public function testOnlyTheRaiserCanEditSubmitAndCancel(): void
    {
        $id = $this->draft();

        $this->failsWith('Only the person who raised', fn () => $this->engine->submit($id, $this->approver));
        $this->failsWith('Only the person who raised', fn () => $this->engine->updateDetails($id, $this->approver, ['amount' => '1']));
        $this->failsWith('Only the person who raised', fn () => $this->engine->cancel($id, $this->approver));
    }

    // ------------------------------------------------------------------ submit rules

    public function testSubmitListsEverythingMissing(): void
    {
        $id = $this->engine->createDraft($this->raiser, ['company_id' => $this->company, 'invoice_type_id' => $this->type]);

        $problems = implode(' ', $this->engine->problemsForSubmit($id));
        foreach (['Customer is required', 'Invoice number is required', 'Invoice date is required', 'Due date is required', 'Amount must be more than zero', 'Attach the invoice PDF'] as $p) {
            $this->assertStringContainsString($p, $problems);
        }

        $this->failsWith('Customer is required', fn () => $this->engine->submit($id, $this->raiser));
        $this->assertSame('draft', $this->arRow($id)['status']);
    }

    public function testDueDateCannotPrecedeInvoiceDate(): void
    {
        $id = $this->draft(['invoice_date' => '2026-10-10', 'due_date' => '2026-10-01']);

        $this->failsWith('due date cannot be before', fn () => $this->engine->submit($id, $this->raiser));
    }

    public function testInvoiceNumbersAreUniquePerCompanyIgnoringCancelled(): void
    {
        $first = $this->draft(['invoice_number' => 'AR-100']);
        $dupe  = $this->draft(['invoice_number' => 'AR-100']);

        $this->failsWith('already uses that number', fn () => $this->engine->submit($dupe, $this->raiser));

        $this->engine->cancel($first, $this->raiser);
        $this->engine->submit($dupe, $this->raiser);
        $this->assertSame('in_approval', $this->arRow($dupe)['status']);

        // The same number in another company is fine.
        $co = $this->makeCompany('BETA');
        $this->grant($this->raiser, $co, ['submit']);
        $route = $this->makeArRoute($co, [['type' => 'issue', 'user' => $this->issuer]]);
        $other = $this->engine->createDraft($this->raiser, $this->arDetails($co, $route['type'], ['invoice_number' => 'AR-100']));
        $this->assertSame([], array_filter($this->engine->problemsForSubmit($other), static fn (string $p): bool => str_contains($p, 'number')));
    }

    public function testOnlyADraftCanBeSubmitted(): void
    {
        $id = $this->inApproval();

        $this->failsWith('Only a draft', fn () => $this->engine->submit($id, $this->raiser));
    }

    // ------------------------------------------------------------------ route shape

    /**
     * @return iterable<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function badRoutes(): iterable
    {
        yield 'no action steps' => [[['type' => 'submit'], ['type' => 'notify']], 'no approval steps'];
        yield 'approvals but no issue step' => [[['type' => 'approve']], 'exactly one issue step'];
        yield 'two issue steps' => [[['type' => 'issue'], ['type' => 'issue']], 'exactly one issue step'];
        yield 'approval after the issue step' => [[['type' => 'issue'], ['type' => 'approve']], 'exactly one issue step'];
    }

    /**
     * @dataProvider badRoutes
     *
     * @param list<array<string, mixed>> $steps
     */
    public function testARouteWithoutOneFinalIssueStepIsRefused(array $steps, string $message): void
    {
        $co = $this->makeCompany('BAD');
        $this->grant($this->raiser, $co, ['submit']);
        $steps = array_map(fn (array $s): array => $s + ['user' => $this->approver], $steps);
        $route = $this->makeArRoute($co, $steps);

        $id = $this->engine->createDraft($this->raiser, $this->arDetails($co, $route['type']));
        (new \App\Core\Receivables\ArFiles($this->db))->store($id, $this->raiser, $this->pdfFile(), 'invoice.pdf');

        $this->failsWith($message, fn () => $this->engine->submit($id, $this->raiser));
        $this->assertSame('draft', $this->arRow($id)['status']);
    }

    public function testNoActiveRouteMeansNoSubmission(): void
    {
        $co = $this->makeCompany('NONE');
        $this->grant($this->raiser, $co, ['submit']);
        $route = $this->makeArRoute($co, [['type' => 'issue', 'user' => $this->issuer]], active: false);
        $id    = $this->engine->createDraft($this->raiser, $this->arDetails($co, $route['type']));
        (new \App\Core\Receivables\ArFiles($this->db))->store($id, $this->raiser, $this->pdfFile(), 'invoice.pdf');

        $this->failsWith('no active approval route', fn () => $this->engine->submit($id, $this->raiser));
    }

    public function testAHighValueRouteIsChosenByAmount(): void
    {
        $boss = $this->makeUserNamed('Bea Boss');
        $this->grant($boss, $this->company, ['approve']);
        $this->makeArRoute($this->company, [['type' => 'approve', 'user' => $boss, 'role' => 'Director'], ['type' => 'issue', 'user' => $this->issuer]], 'Sales', 'extra_approval', true, 'amount_threshold', '1000');

        $small = $this->draft(['amount' => '999.99']);
        $this->engine->submit($small, $this->raiser);
        $this->assertSame($this->approver, (int) $this->engine->currentStep($this->arRow($small))['user_id']);

        $large = $this->draft(['amount' => '1000.00']);
        $this->engine->submit($large, $this->raiser);
        $this->assertSame($boss, (int) $this->engine->currentStep($this->arRow($large))['user_id']);
    }

    // ------------------------------------------------------------------ issuing

    public function testIssuingNeedsToKnowWhoItWentTo(): void
    {
        $id = $this->inApproval();
        $this->engine->approve($id, $this->approver);

        $this->failsWith('who the invoice was sent to', fn () => $this->engine->approve($id, $this->issuer));
        $this->failsWith('who the invoice was sent to', fn () => $this->engine->approve($id, $this->issuer, null, ['sent_to' => '   ']));
        $this->assertSame('approved', $this->arRow($id)['status']);
        $this->assertNull($this->arRow($id)['issued_at']);
    }

    // ------------------------------------------------------------------ return / reject / resubmit / cancel

    public function testReturnAndRejectNeedANoteAndResubmitNeedsOneToo(): void
    {
        $id = $this->inApproval();

        $this->failsWith('Explain what needs to change', fn () => $this->engine->returnForChanges($id, $this->approver, '  '));
        $this->failsWith('Give a reason for rejecting', fn () => $this->engine->reject($id, $this->approver, ''));

        $this->engine->returnForChanges($id, $this->approver, 'Wrong PO');
        $this->assertSame('returned', $this->arRow($id)['status']);
        $this->assertSame(1, $this->bell($this->raiser, 'ar.returned'));

        $this->failsWith('Explain what you changed', fn () => $this->engine->resubmit($id, $this->raiser, ''));
        $this->engine->updateDetails($id, $this->raiser, $this->arDetails($this->company, $this->type, ['invoice_number' => 'AR-FIXED', 'customer_po_reference' => 'PO-9']));
        $this->engine->resubmit($id, $this->raiser, 'Corrected the PO');
        $row = $this->arRow($id);
        $this->assertSame('in_approval', $row['status']);
        $this->assertSame('PO-9', $row['customer_po_reference']);
        $this->assertContains('resubmit', array_column($this->arActions($id), 'action'));
        $this->assertSame(1, $this->bell($this->approver, 'ar.resubmitted'));

        $this->engine->reject($id, $this->approver, 'Customer cancelled the order');
        $row = $this->arRow($id);
        $this->assertSame('rejected', $row['status']);
        $this->assertNotNull($row['ended_at']);
        $this->assertSame(1, $this->bell($this->raiser, 'ar.rejected'));

        // A rejected invoice can still be corrected and resubmitted, which reopens it.
        $this->engine->resubmit($id, $this->raiser, 'Order is back on');
        $row = $this->arRow($id);
        $this->assertSame('in_approval', $row['status']);
        $this->assertNull($row['ended_at']);
    }

    public function testNotesAreStoredWithTheirKind(): void
    {
        $id = $this->inApproval();
        $this->engine->returnForChanges($id, $this->approver, 'Wrong PO');

        $note = $this->db->table('ar_notes')->where('invoice_id', $id)->get()->getRowArray();
        $this->assertSame('return_reason', $note['kind']);
        $this->assertSame('Wrong PO', $note['body']);
        $this->assertSame((int) $note['id'], (int) $this->arActions($id)[1]['note_id']);
    }

    public function testCancelOnlyFromEditableStates(): void
    {
        $draft = $this->draft();
        $this->engine->cancel($draft, $this->raiser);
        $row = $this->arRow($draft);
        $this->assertSame('cancelled', $row['status']);
        $this->assertNotNull($row['ended_at']);

        $live = $this->inApproval();
        $this->failsWith('Only a draft, returned or rejected', fn () => $this->engine->cancel($live, $this->raiser));
        $this->failsWith('can no longer be edited', fn () => $this->engine->updateDetails($live, $this->raiser, ['amount' => '1']));
    }

    public function testReturnToThePreviousApprover(): void
    {
        $id = $this->inApproval();

        // At the first action step there is nobody earlier.
        $this->failsWith('no earlier approver', fn () => $this->engine->returnToPreviousApprover($id, $this->approver, 'Back'));

        $this->engine->approve($id, $this->approver);
        $this->assertSame('approved', $this->arRow($id)['status']);
        $this->failsWith('Explain what needs to change', fn () => $this->engine->returnToPreviousApprover($id, $this->issuer, ''));

        $this->engine->returnToPreviousApprover($id, $this->issuer, 'Please re-check the amount');
        $row = $this->arRow($id);
        $this->assertSame('in_approval', $row['status'], 'It stays in approval; nobody resubmits.');
        $this->assertSame(2, (int) $row['current_step_order']);
        $this->assertSame(1, $this->bell($this->approver, 'ar.returned_step'));
    }

    // ------------------------------------------------------------------ group-level, delegation, teams

    public function testGroupLevelUsersMustGiveAReasonAndEveryoneIsTold(): void
    {
        $boss = $this->makeUserNamed('Gus Group');
        $this->grantGroupLevel($boss);
        $id = $this->inApproval();

        $this->failsWith('Give a reason', fn () => $this->engine->approve($id, $boss));
        $this->assertTrue($this->engine->canAct($id, $boss)['reason_required']);

        $this->engine->approve($id, $boss, 'Alan is away');
        $this->assertSame('approved', $this->arRow($id)['status']);
        $this->assertSame('group_level', $this->arActions($id)[1]['acted_as']);
        $this->assertSame($this->approver, (int) $this->arActions($id)[1]['on_behalf_of']);
        $this->assertSame(1, $this->bell($this->approver, 'ar.override'));
        $this->assertSame(1, $this->bell($this->raiser, 'ar.override'));
    }

    public function testADelegateActsOnBehalfInsideTheWindow(): void
    {
        $deputy = $this->makeUserNamed('Dee Deputy');
        $this->grant($deputy, $this->company, ['approve']);
        $id = $this->inApproval();

        $this->failsWith('not an approver', fn () => $this->engine->approve($id, $deputy));

        $resolver = new \App\Core\Receivables\ArDelegationResolver($this->db);
        $resolver->create($this->approver, $this->approver, $deputy, null, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), 'Holiday');
        $this->assertEqualsCanonicalizing(
            [$this->approver, $deputy],
            $this->engine->approversOf($this->engine->currentStep($this->arRow($id)), $this->company)['user_ids'],
            'The step is with the approver and, during the window, the delegate.',
        );

        $this->assertSame('delegate', $this->engine->canAct($id, $deputy)['as']);
        $this->engine->approve($id, $deputy);
        $action = $this->arActions($id)[1];
        $this->assertSame('delegate', $action['acted_as']);
        $this->assertSame($this->approver, (int) $action['on_behalf_of']);
    }

    public function testADelegationCannotBeCreatedForSomeoneElseWithoutManageRights(): void
    {
        $deputy   = $this->makeUserNamed('Dee Deputy');
        $resolver = new \App\Core\Receivables\ArDelegationResolver($this->db);

        $this->failsWith('only delegate your own', fn () => $resolver->create($this->raiser, $this->approver, $deputy, null, '2026-10-01 00:00:00', '2026-10-02 00:00:00', null));
    }

    public function testTeamStepsNeedAnAcceptanceBeforeAnyoneCanAct(): void
    {
        $a    = $this->makeUserNamed('Tia Team');
        $b    = $this->makeUserNamed('Tom Team');
        $co   = $this->makeCompany('TEAM');
        $team = $this->makeArTeam('Billing desk', [$a, $b]);
        foreach ([$this->raiser => 'submit', $a => 'approve', $b => 'approve'] as $uid => $right) {
            $this->grant($uid, $co, [$right]);
        }
        $route = $this->makeArRoute($co, [['type' => 'issue', 'team' => $team, 'label' => 'Billing desk']]);
        $id    = $this->engine->createDraft($this->raiser, $this->arDetails($co, $route['type']));
        (new \App\Core\Receivables\ArFiles($this->db))->store($id, $this->raiser, $this->pdfFile(), 'invoice.pdf');
        $this->engine->submit($id, $this->raiser);

        $this->assertSame(1, $this->bell($a, 'ar.submitted'));
        $this->assertSame(1, $this->bell($b, 'ar.submitted'));
        $this->assertTrue($this->engine->canAct($id, $a)['can_claim']);
        $this->failsWith('Accept this invoice first', fn () => $this->engine->approve($id, $a, null, ['sent_to' => 'x']));

        $this->engine->claim($id, $a);
        $this->failsWith('already accepted', fn () => $this->engine->claim($id, $b));
        $this->failsWith('Accept this invoice first', fn () => $this->engine->approve($id, $b, null, ['sent_to' => 'x']));
        $this->failsWith('Only the person who accepted', fn () => $this->engine->release($id, $b));

        $this->engine->release($id, $a);
        $this->engine->claim($id, $b);
        $this->engine->approve($id, $b, null, ['sent_to' => 'Harbour Foods AP']);
        $this->assertSame('issued', $this->arRow($id)['status']);
        $this->assertSame('team', $this->arActions($id)[count($this->arActions($id)) - 1]['acted_as']);
        $this->assertSame(0, $this->db->table('ar_step_claims')->where('invoice_id', $id)->where('released_at IS NULL')->countAllResults(), 'Claims are closed when the step is done.');
    }

    public function testNonMembersCannotClaim(): void
    {
        $a    = $this->makeUserNamed('Tia Team');
        $co   = $this->makeCompany('TEAM');
        $team = $this->makeArTeam('Billing desk', [$a]);
        $this->grant($this->raiser, $co, ['submit']);
        $this->grant($a, $co, ['approve']);
        $route = $this->makeArRoute($co, [['type' => 'issue', 'team' => $team]]);
        $id    = $this->engine->createDraft($this->raiser, $this->arDetails($co, $route['type']));
        (new \App\Core\Receivables\ArFiles($this->db))->store($id, $this->raiser, $this->pdfFile(), 'invoice.pdf');
        $this->engine->submit($id, $this->raiser);

        $this->failsWith('not on the team', fn () => $this->engine->claim($id, $this->raiser));
    }

    // ------------------------------------------------------------------ customers

    public function testTheCustomerIsLinkedToTheSharedListAndNameIsKept(): void
    {
        $cid = service('customers')->create(['code' => 'C1', 'name' => 'Harbour Foods', 'default_currency' => 'EUR']);

        $byName = $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type, ['customer' => 'harbour foods']));
        $this->assertSame($cid, (int) $this->arRow($byName)['customer_id']);
        $this->assertSame('harbour foods', $this->arRow($byName)['customer']);

        $byId = $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type, ['customer' => 'ignored', 'customer_id' => $cid, 'currency' => '']));
        $this->assertSame('Harbour Foods', $this->arRow($byId)['customer'], 'The list name is the snapshot when a customer is picked.');
        $this->assertSame('EUR', $this->arRow($byId)['currency'], 'The customer default currency applies when none is given.');

        $free = $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type, ['customer' => 'One-off Buyer']));
        $this->assertNull($this->arRow($free)['customer_id']);
    }

    public function testASwitchedOffCustomerCannotBeNewlyChosenButMayStay(): void
    {
        $cid = service('customers')->create(['code' => 'C1', 'name' => 'Harbour Foods']);
        $id  = $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type, ['customer_id' => $cid]));

        service('customers')->update($cid, ['code' => 'C1', 'name' => 'Harbour Foods', 'active' => false]);

        $this->failsWith('switched off', fn () => $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type, ['customer_id' => $cid])));

        $this->engine->updateDetails($id, $this->raiser, $this->arDetails($this->company, $this->type, ['customer_id' => $cid, 'amount' => '300.00']));
        $this->assertSame('300.00', $this->arRow($id)['amount']);
    }

    // ------------------------------------------------------------------ documents and notes

    public function testDocumentsAndNotes(): void
    {
        $id = $this->inApproval();

        $docId = $this->engine->addDocument($id, $this->approver, $this->pdfFile('quote'), 'quote.pdf', 'Customer quote');
        $this->assertSame('attach', $this->arActions($id)[1]['action']);
        $this->assertSame($docId, (int) $this->db->table('ar_documents')->where('invoice_id', $id)->get()->getRow()->id);
        $this->assertSame(1, $this->bell($this->raiser, 'ar.document_added'));

        $outsider = $this->makeUserNamed('Olga Other');
        $this->assertFalse($this->engine->canAttach($id, $outsider)['allowed']);
        $this->failsWith('cannot attach', fn () => $this->engine->addDocument($id, $outsider, $this->pdfFile(), 'x.pdf'));
        $this->failsWith('cannot see', fn () => $this->engine->addNote($id, $outsider, 'hello'));

        $this->failsWith('Write a note first', fn () => $this->engine->addNote($id, $this->approver, ' '));
        $this->assertGreaterThan(0, $this->engine->addNote($id, $this->approver, 'Chased the customer'));
    }

    public function testABadUploadIsRefusedAndLeavesNothing(): void
    {
        $id = $this->draft();

        $this->failsWith('Only PDF', fn () => (new \App\Core\Receivables\ArFiles($this->db))->store($id, $this->raiser, $this->tempFile('not a pdf'), 'invoice.txt'));
        $this->failsWith('That file type is not allowed', fn () => $this->engine->addDocument($id, $this->raiser, $this->tempFile('MZ'), 'virus.exe'));
        $this->assertSame(0, $this->db->table('ar_documents')->where('invoice_id', $id)->countAllResults());
        $this->assertSame(1, $this->db->table('ar_files')->where('invoice_id', $id)->countAllResults());
    }

    // ------------------------------------------------------------------ audit

    public function testEveryChangeIsAudited(): void
    {
        $id = $this->inApproval();
        $this->engine->approve($id, $this->approver);
        $this->engine->approve($id, $this->issuer, null, ['sent_to' => 'post']);

        $rows = $this->db->table('audit_log')->where('entity', 'ar_invoice')->where('entity_id', (string) $id)->get()->getResultArray();
        foreach (['ar.submit', 'ar.approved', 'ar.issued'] as $e) {
            $this->assertContains($e, array_column($rows, 'action'));
        }
        $this->assertSame($this->issuer, (int) $this->db->table('audit_log')->where('action', 'ar.issued')->get()->getRow()->actor_id);
    }
}

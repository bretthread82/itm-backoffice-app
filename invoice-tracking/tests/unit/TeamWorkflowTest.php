<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdminAccess;
use App\Services\ApprovalQueue;
use App\Services\InvoiceFiles;
use App\Services\TeamMembers;
use App\Services\WorkflowEngine;
use App\Services\WorkflowException;
use Config\Services;
use Tests\Support\AppTestCase;
use Tests\Support\FakeMailTransport;

/**
 * Teams on route steps: everyone on the team is told, one member accepts, only they can act.
 *
 * @internal
 */
final class TeamWorkflowTest extends AppTestCase
{
    private WorkflowEngine $engine;
    private int $company;
    private int $type;
    private int $submitter;
    private int $ann;      // team member
    private int $bob;      // team member
    private int $outsider; // in the company, not on the team
    private int $noAccess; // on the team, not in the company
    private int $groupUser;
    private int $adminId;
    private int $team;

    protected function setUp(): void
    {
        parent::setUp();

        Services::injectMock('mailer', new FakeMailTransport());
        $this->engine = new WorkflowEngine($this->db);

        $this->adminId   = $this->makeUserWithEmail('Admin', 'admin@example.test', true);
        $this->company   = $this->makeCompany('ACME');
        $this->submitter = $this->makeUserWithEmail('Sue Submitter', 'sue@example.test');
        $this->ann       = $this->makeUserWithEmail('Ann Team', 'ann@example.test');
        $this->bob       = $this->makeUserWithEmail('Bob Team', 'bob@example.test');
        $this->outsider  = $this->makeUserWithEmail('Olly Outsider', 'olly@example.test');
        $this->noAccess  = $this->makeUserWithEmail('Nina NoAccess', 'nina@example.test');
        $this->groupUser = $this->makeUserWithEmail('Gary Group', 'gary@example.test');
        $this->join($this->submitter, $this->company, true);
        foreach ([$this->ann, $this->bob, $this->outsider] as $u) {
            $this->join($u, $this->company);
        }
        (new AdminAccess($this->db))->grantGroupLevel($this->adminId, $this->groupUser);

        $this->team = $this->makeTeam('AP Team', [$this->ann, $this->bob, $this->noAccess]);
        $r          = $this->makeRoute($this->company, [
            ['type' => 'submit', 'user' => $this->submitter, 'label' => 'Sue Submitter'],
            ['type' => 'approve', 'team' => $this->team, 'label' => 'AP Team', 'role' => 'AP'],
            ['type' => 'process', 'user' => $this->outsider, 'label' => 'Olly Outsider'],
        ]);
        $this->type = $r['type'];
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        Services::resetSingle('mailer');
        parent::tearDown();
    }

    private function submitted(): int
    {
        $id = $this->engine->createDraft($this->submitter, [
            'company_id' => $this->company, 'invoice_type_id' => $this->type, 'supplier' => 'Acme', 'invoice_number' => 'T-1',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '100', 'currency' => 'usd',
        ]);
        (new InvoiceFiles($this->db))->store($id, $this->submitter, $this->pdfFile(), 'inv.pdf');
        $this->engine->submit($id, $this->submitter);

        return $id;
    }

    private function bell(int $userId, string $event): int
    {
        return $this->db->table('user_notifications')->where(['user_id' => $userId, 'event' => $event])->countAllResults();
    }

    public function testSnapshotKeepsTheTeamAndTheWholeTeamIsToldExceptThoseWhoCannotSeeTheCompany(): void
    {
        $id   = $this->submitted();
        $step = $this->engine->currentStep($this->invoiceRow($id));

        $this->assertSame('team', $step['assignee_kind']);
        $this->assertSame($this->team, $step['team_id']);
        $this->assertSame(1, $this->bell($this->ann, 'invoice.submitted'));
        $this->assertSame(1, $this->bell($this->bob, 'invoice.submitted'));
        $this->assertSame(0, $this->bell($this->noAccess, 'invoice.submitted'));
        $this->assertSame(0, $this->bell($this->outsider, 'invoice.submitted'));
    }

    public function testMembersCanAcceptButOthersCannot(): void
    {
        $id = $this->submitted();

        $this->assertTrue($this->engine->canAct($id, $this->ann)['can_claim']);
        $this->assertFalse($this->engine->canAct($id, $this->outsider)['can_claim']);
        $this->assertFalse($this->engine->canAct($id, $this->noAccess)['can_claim']);

        $this->expectException(WorkflowException::class);
        $this->engine->claim($id, $this->outsider);
    }

    public function testAMemberMustAcceptBeforeActing(): void
    {
        $id = $this->submitted();

        $this->assertFalse($this->engine->canAct($id, $this->ann)['can_act']);
        try {
            $this->engine->approve($id, $this->ann);
            $this->fail('Approving without accepting should fail.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Accept this invoice first', $e->getMessage());
        }
        $this->assertSame('in_approval', $this->invoiceRow($id)['status']);
    }

    public function testOnlyTheAcceptorCanActAndTheRestOfTheTeamCannotAcceptAgain(): void
    {
        $id = $this->submitted();
        $this->engine->claim($id, $this->ann);

        $can = $this->engine->canAct($id, $this->ann);
        $this->assertTrue($can['can_act']);
        $this->assertSame('team', $can['as']);

        $bob = $this->engine->canAct($id, $this->bob);
        $this->assertFalse($bob['can_act']);
        $this->assertFalse($bob['can_claim']);
        $this->assertSame($this->ann, $bob['claim']['claimed_by']);

        try {
            $this->engine->claim($id, $this->bob);
            $this->fail('A second claim should fail.');
        } catch (WorkflowException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(WorkflowException::class);
        $this->engine->approve($id, $this->bob);
    }

    public function testAcceptingAndApprovingIsRecordedAndMovesOn(): void
    {
        $id = $this->submitted();
        $this->engine->claim($id, $this->ann);
        $this->assertSame(1, $this->bell($this->bob, 'invoice.claimed'));
        $this->assertSame(1, $this->bell($this->submitter, 'invoice.claimed'));

        $this->engine->approve($id, $this->ann, 'ok');

        $actions = array_column($this->actions($id), 'action');
        $this->assertSame(['submit', 'claim', 'approve'], $actions);
        $this->assertSame('team', $this->actions($id)[1]['acted_as']);
        $this->assertSame('approved', $this->invoiceRow($id)['status']); // only the process step is left
        $this->assertSame(0, $this->db->table('invoice_step_claims')->where('released_at IS NULL')->countAllResults());
    }

    public function testReleaseGivesItBackToTheTeam(): void
    {
        $id = $this->submitted();
        $this->engine->claim($id, $this->ann);

        try {
            $this->engine->release($id, $this->bob);
            $this->fail('Only the acceptor can release.');
        } catch (WorkflowException) {
            $this->addToAssertionCount(1);
        }

        $this->engine->release($id, $this->ann);
        $this->assertTrue($this->engine->canAct($id, $this->bob)['can_claim']);
        $this->assertFalse($this->engine->canAct($id, $this->ann)['can_act']);
        $this->assertSame(1, $this->bell($this->bob, 'invoice.released'));

        $this->engine->claim($id, $this->bob);
        $this->assertTrue($this->engine->canAct($id, $this->bob)['can_act']);
    }

    public function testGroupLevelNeedsAReasonAndIsFlaggedOnATeamStep(): void
    {
        $id = $this->submitted();
        $this->engine->claim($id, $this->ann);

        $can = $this->engine->canAct($id, $this->groupUser);
        $this->assertSame('group_level', $can['as']);
        $this->assertSame($this->ann, $can['on_behalf_of']);

        try {
            $this->engine->approve($id, $this->groupUser);
            $this->fail('A reason is required.');
        } catch (WorkflowException) {
            $this->addToAssertionCount(1);
        }

        $this->engine->approve($id, $this->groupUser, 'Urgent, Ann is away');
        $all  = $this->actions($id);
        $last = $all[array_key_last($all)];
        $this->assertSame('group_level', $last['acted_as']);
        $this->assertSame(1, $this->bell($this->ann, 'invoice.override')); // the acceptor is told
    }

    public function testReleasingSomeoneElsesAcceptanceAsGroupLevelNeedsAReason(): void
    {
        $id = $this->submitted();
        $this->engine->claim($id, $this->ann);

        try {
            $this->engine->release($id, $this->groupUser);
            $this->fail('A reason is required.');
        } catch (WorkflowException) {
            $this->addToAssertionCount(1);
        }

        $this->engine->release($id, $this->groupUser, 'Ann is on leave');
        $this->assertTrue($this->engine->canAct($id, $this->bob)['can_claim']);
    }

    public function testReturnedInvoiceReopensUnclaimedOnResubmit(): void
    {
        $id = $this->submitted();
        $this->engine->claim($id, $this->ann);
        $this->engine->returnForChanges($id, $this->ann, 'wrong amount');
        $this->engine->resubmit($id, $this->submitter, 'fixed');

        $this->assertTrue($this->engine->canAct($id, $this->bob)['can_claim']);
        $this->assertFalse($this->engine->canAct($id, $this->ann)['can_act']);
    }

    public function testQueueShowsAvailableAndAcceptedItemsButHidesOthersClaims(): void
    {
        $id    = $this->submitted();
        $queue = new ApprovalQueue($this->db);

        $this->assertSame('available', $queue->pendingFor($this->ann)[0]['claim_state']);
        $this->assertSame([], $queue->pendingFor($this->outsider));

        $this->engine->claim($id, $this->ann);
        $this->assertSame('mine', $queue->pendingFor($this->ann)[0]['claim_state']);
        $this->assertSame([], $queue->pendingFor($this->bob));
    }

    public function testRemovingAMemberReturnsWhatTheyAccepted(): void
    {
        $id = $this->submitted();
        $this->engine->claim($id, $this->ann);

        (new TeamMembers($this->db))->sync($this->team, [$this->bob], $this->adminId);

        $this->assertFalse($this->engine->canAct($id, $this->ann)['can_act']);
        $this->assertTrue($this->engine->canAct($id, $this->bob)['can_claim']);
        $this->assertContains('release', array_column($this->actions($id), 'action'));
    }

    public function testAcceptanceByADisabledAccountNoLongerCounts(): void
    {
        $id = $this->submitted();
        $this->engine->claim($id, $this->ann);
        $this->db->table('users')->where('id', $this->ann)->update(['active' => 0]);

        $this->assertTrue($this->engine->canAct($id, $this->bob)['can_claim']);
        $this->engine->claim($id, $this->bob);
        $this->assertTrue($this->engine->canAct($id, $this->bob)['can_act']);
    }

    public function testMembershipDoesNotGrantVisibilityOfTheCompany(): void
    {
        $this->assertFalse((new \App\Services\AccessScope($this->db))->canViewCompany($this->noAccess, $this->company));
    }
}

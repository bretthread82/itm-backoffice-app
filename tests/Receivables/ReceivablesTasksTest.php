<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Dashboard\DashboardService;
use App\Core\Receivables\ArApprovalQueue;
use App\Core\Receivables\ArDashboardTab;
use App\Core\Receivables\ArFiles;
use App\Core\Receivables\ArNavCounts;
use App\Core\Receivables\PaymentService;
use App\Core\Receivables\ReceivablesEngine;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * My tasks and the dashboard tab: what is waiting on whom, what is overdue to chase, and that none of it leaks across
 * companies.
 *
 * @internal
 */
final class ReceivablesTasksTest extends ReceivablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private ReceivablesEngine $engine;
    private int $company;
    private int $other;
    private int $raiser;
    private int $approver;
    private int $issuer;
    private int $collector;
    private int $outsider;
    private int $type;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->engine    = new ReceivablesEngine($this->db);
        $this->company   = $this->makeCompany('ACME');
        $this->other     = $this->makeCompany('OTHER');
        $this->raiser    = $this->makeUserNamed('Rae Raiser', 'rae@example.test');
        $this->approver  = $this->makeUserNamed('Alan Approver', 'alan@example.test');
        $this->issuer    = $this->makeUserNamed('Ivy Issuer', 'ivy@example.test');
        $this->collector = $this->makeUserNamed('Cal Collector', 'cal@example.test');
        $this->outsider  = $this->makeUserNamed('Olga Outsider', 'olga@example.test');

        $this->grant($this->raiser, $this->company, ['submit']);
        $this->grant($this->approver, $this->company, ['approve']);
        $this->grant($this->issuer, $this->company, ['approve']);
        $this->grant($this->collector, $this->company, ['collect']);
        $this->grant($this->outsider, $this->other, ['approve', 'collect']);
        foreach ([$this->raiser, $this->approver, $this->issuer, $this->collector, $this->outsider] as $u) {
            $this->giveRole($u, 'receivables_user');
        }

        $route      = $this->makeArRoute($this->company, [
            ['type' => 'approve', 'user' => $this->approver, 'role' => 'Approver', 'label' => 'Alan'],
            ['type' => 'issue', 'user' => $this->issuer, 'role' => 'Billing', 'label' => 'Ivy'],
        ]);
        $this->type = $route['type'];
        $this->today = service('timezone')->todayFor($this->company);
    }

    private function giveRole(int $userId, string $role): void
    {
        $this->db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => $this->roleId($role)]);
        service('permissions')->forget($userId);
    }

    private function as(int $userId, string $path)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();
        db_connect()->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        return $this->withSession(['user' => ['id' => $userId]])->get($path);
    }

    private function submitted(string $number = 'AR-T1'): int
    {
        $id = $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type, ['invoice_number' => $number]));
        (new ArFiles($this->db))->store($id, $this->raiser, $this->pdfFile(), 'inv.pdf');
        $this->engine->submit($id, $this->raiser);

        return $id;
    }

    private function ago(int $days): string
    {
        return date('Y-m-d', strtotime($this->today . " -{$days} days"));
    }

    /** @param list<array<string, mixed>> $items */
    private function ids(array $items): array
    {
        return array_map('intval', array_column($items, 'id'));
    }

    // ------------------------------------------------------------------ the queue

    public function testAnInvoiceIsWaitingOnTheNamedApproverOnly(): void
    {
        $id    = $this->submitted();
        $queue = new ArApprovalQueue($this->db);

        $this->assertSame([$id], $this->ids($queue->pendingFor($this->approver)));
        $this->assertSame([], $queue->pendingFor($this->issuer), 'The issuer is not up yet.');
        $this->assertSame([], $queue->pendingFor($this->raiser));
        $this->assertSame([], $queue->pendingFor($this->outsider), 'Another company sees nothing.');

        $this->engine->approve($id, $this->approver);
        $this->assertSame([], $queue->pendingFor($this->approver));
        $this->assertSame([$id], $this->ids($queue->pendingFor($this->issuer)));
        $this->assertSame('Billing (Ivy)', $queue->pendingFor($this->issuer)[0]['step_label']);
    }

    public function testADelegateSeesTheDelegatorsTasks(): void
    {
        $deputy = $this->makeUserNamed('Dee Deputy');
        $this->grant($deputy, $this->company, ['approve']);
        $id = $this->submitted();
        (new \App\Core\Receivables\ArDelegationResolver($this->db))->create($this->approver, $this->approver, $deputy, null, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), null);

        $rows = (new ArApprovalQueue($this->db))->pendingFor($deputy);

        $this->assertSame([$id], $this->ids($rows));
        $this->assertSame('delegate', $rows[0]['acting_as']);
    }

    public function testTeamStepsAreAvailableToMembersUntilOneAcceptsThem(): void
    {
        $other = $this->makeUserNamed('Tom Team');
        $this->grant($other, $this->company, ['approve']);
        $team  = $this->makeArTeam('Billing desk', [$this->issuer, $other]);
        $route = $this->makeArRoute($this->company, [['type' => 'issue', 'team' => $team]], 'Team sales');
        $id    = $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $route['type']));
        (new ArFiles($this->db))->store($id, $this->raiser, $this->pdfFile(), 'inv.pdf');
        $this->engine->submit($id, $this->raiser);
        $queue = new ArApprovalQueue($this->db);

        $this->assertSame('available', $queue->pendingFor($this->issuer)[0]['claim_state']);
        $this->assertSame('available', $queue->pendingFor($other)[0]['claim_state']);

        $this->engine->claim($id, $this->issuer);
        $this->assertSame('mine', $queue->pendingFor($this->issuer)[0]['claim_state']);
        $this->assertSame([], $queue->pendingFor($other), 'Once accepted, the rest of the team no longer sees it as theirs.');
    }

    public function testGroupLevelUsersSeeEverythingInApproval(): void
    {
        $boss = $this->makeUserNamed('Gus Group');
        $this->grantGroupLevel($boss);
        $id = $this->submitted();

        $queue = new ArApprovalQueue($this->db);
        $this->assertSame([], $queue->pendingFor($boss), 'Not their own queue.');
        $this->assertSame([$id], $this->ids($queue->awaitingAll($boss)));
        $this->assertSame([], $queue->awaitingAll($this->outsider));
    }

    public function testMineSplitsDraftsInProgressAndSentBack(): void
    {
        $draft = $this->engine->createDraft($this->raiser, $this->arDetails($this->company, $this->type));
        $live  = $this->submitted('AR-LIVE');
        $back  = $this->submitted('AR-BACK');
        $this->engine->returnForChanges($back, $this->approver, 'Wrong PO');
        $this->makeIssued($this->company, $this->raiser);   // already issued: not "in progress"

        $mine = (new ArApprovalQueue($this->db))->mine($this->raiser);

        $this->assertSame([$draft], $this->ids($mine['drafts']));
        $this->assertSame([$live], $this->ids($mine['waiting']));
        $this->assertSame([$back], $this->ids($mine['needs_action']));
        $this->assertSame(['waiting' => [], 'needs_action' => [], 'drafts' => []], (new ArApprovalQueue($this->db))->mine($this->approver));
    }

    public function testOverdueIsForTheRaiserAndTheCollectorsOfThatCompany(): void
    {
        $late   = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(5)]);
        $older  = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(40), 'status' => 'part_paid', 'amount_paid' => '50.00']);
        $notYet = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->today]);
        $paid   = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(9), 'status' => 'paid', 'amount_paid' => '250.00']);
        $queue  = new ArApprovalQueue($this->db);

        $mine = $queue->overdueFor($this->raiser);
        $this->assertSame([$older, $late], $this->ids($mine), 'Most overdue first.');
        $this->assertSame('200.00', $mine[0]['outstanding']);
        $this->assertSame([40, 5], array_column($mine, 'days_past_due'));
        $this->assertSame([$older, $late], $this->ids($queue->overdueFor($this->collector)), 'Collectors chase the whole company.');
        $this->assertSame([], $queue->overdueFor($this->approver), 'Approvers only approve.');
        $this->assertSame([], $queue->overdueFor($this->outsider), 'Another company sees nothing.');
        $this->assertNotContains($notYet, $this->ids($mine));
        $this->assertNotContains($paid, $this->ids($mine));
    }

    public function testOverdueStopsOnceItIsPaid(): void
    {
        $id = $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(3)]);
        $this->assertSame([$id], $this->ids((new ArApprovalQueue($this->db))->overdueFor($this->collector)));

        (new PaymentService($this->db))->record($id, $this->collector, ['amount' => '250.00', 'received_date' => $this->today]);

        $this->assertSame([], (new ArApprovalQueue($this->db))->overdueFor($this->collector));
    }

    // ------------------------------------------------------------------ the page and the sidebar count

    public function testTheTasksPageShowsWhatIsWaitingAndTheSidebarCountsIt(): void
    {
        $this->submitted('AR-WAIT');

        $page = $this->as($this->approver, 'receivables/tasks');

        $this->assertTrue($page->isOK(), 'status ' . $page->response()->getStatusCode());
        $page->assertSee('AR-WAIT');
        $page->assertSee('Waiting for you');
        $page->assertSee('receivables/tasks');

        $this->as($this->outsider, 'receivables/tasks')->assertDontSee('AR-WAIT');
        $this->assertSame(1, ArNavCounts::for($this->approver)['waiting']);
    }

    public function testTheTasksPageListsOverdueInvoicesToChase(): void
    {
        $this->makeIssued($this->company, $this->raiser, ['invoice_number' => 'AR-LATE', 'due_date' => $this->ago(12)]);

        $this->as($this->collector, 'receivables/tasks')->assertSee('AR-LATE');
        $this->as($this->collector, 'receivables/tasks')->assertSee('Overdue to chase');
        $this->as($this->approver, 'receivables/tasks')->assertDontSee('AR-LATE');
    }

    public function testTheTasksPageIsGated(): void
    {
        $nobody = $this->makeUserNamed('No Body', 'nobody@example.test');

        $this->assertNotSame(200, $this->as($nobody, 'receivables/tasks')->response()->getStatusCode());
    }

    // ------------------------------------------------------------------ the dashboard tab

    public function testTheDashboardTabIsOfferedToPeopleWhoCanUseReceivables(): void
    {
        $board = new DashboardService();
        $this->assertArrayHasKey('receivables', $board->tabsFor($this->raiser));
        $this->assertArrayNotHasKey('receivables', $board->tabsFor($this->makeUserNamed('No Body', 'nobody@example.test')));

        setting()->set('ModuleState.disabled', ['receivables']);
        Services::resetSingle('modules');
        $this->assertArrayNotHasKey('receivables', $board->tabsFor($this->raiser), 'A switched-off module drops its tab.');
        setting()->set('ModuleState.disabled', []);
        Services::resetSingle('modules');
    }

    public function testTheBadgeCountsWhatIsWaitingAndWhatCameBack(): void
    {
        $this->submitted('AR-W');
        $back    = $this->submitted('AR-B');
        $this->engine->returnForChanges($back, $this->approver, 'Fix it');

        $tab = new ArDashboardTab();
        Services::resetSingle('session');
        session();

        $this->assertSame(1, $tab->badge($this->approver), 'One waiting on the approver.');
        session()->remove('ar_nav_counts');
        $this->assertSame(1, $tab->badge($this->raiser), 'One sent back to the raiser.');
    }

    public function testTheTabDataIsScopedAndTotalsAreCorrect(): void
    {
        $this->makeIssued($this->company, $this->raiser, ['due_date' => $this->ago(4), 'amount' => '100.00']);
        $this->makeIssued($this->company, $this->raiser, ['due_date' => date('Y-m-d', strtotime($this->today . ' +3 days')), 'amount' => '60.00']);
        $this->makeIssued($this->company, $this->raiser, ['due_date' => date('Y-m-d', strtotime($this->today . ' +30 days')), 'amount' => '40.00']);
        $hidden = $this->makeIssued($this->other, $this->outsider, ['due_date' => $this->ago(4), 'amount' => '9999.00']);

        $paidInvoice = $this->makeIssued($this->company, $this->raiser, ['amount' => '500.00', 'due_date' => date('Y-m-d', strtotime($this->today . ' +60 days'))]);
        (new PaymentService($this->db))->record($paidInvoice, $this->collector, ['amount' => '125.00', 'received_date' => $this->today]);

        $data = (new ArDashboardTab())->data($this->collector);

        $this->assertSame(['USD' => 100.0 + 60.0 + 40.0 + 375.0], $data['summary']['outstanding']['amount']);
        $this->assertSame(['USD' => 100.0], $data['summary']['overdue']['amount']);
        $this->assertSame(['USD' => 60.0], $data['dueSoon']['amount']);
        $this->assertSame(['USD' => 125.0], $data['received']['amount']);
        $this->assertNotContains($hidden, $this->ids($data['overdue']));

        $outsider = (new ArDashboardTab())->data($this->outsider);
        $this->assertSame(['USD' => 9999.0], $outsider['summary']['outstanding']['amount']);
        $this->assertSame([], $outsider['received']['amount']);
    }

    public function testTheTabRendersOverHttp(): void
    {
        $this->submitted('AR-DASH');
        $this->makeIssued($this->company, $this->raiser, ['invoice_number' => 'AR-LATE2', 'due_date' => $this->ago(8)]);

        $tab = $this->as($this->approver, '/?tab=receivables');
        $this->assertTrue($tab->isOK(), 'status ' . $tab->response()->getStatusCode());
        $tab->assertSee('Waiting on me');
        $tab->assertSee('AR-DASH');

        $this->as($this->collector, '/?tab=receivables')->assertSee('AR-LATE2');
        $this->as($this->raiser, '/')->assertSee('Receivables');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\ArDelegationResolver;
use App\Core\Receivables\ArFiles;
use App\Core\Receivables\ArUserOffboarding;
use App\Core\Receivables\ReceivablesEngine;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * Handing a leaver's customer invoice work to a replacement: what moves, what must not, the guards, and the screen.
 *
 * @internal
 */
final class ReceivablesHandoverTest extends ReceivablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private ReceivablesEngine $engine;
    private ArUserOffboarding $svc;
    private int $company;
    private int $leaver;
    private int $replacement;
    private int $early;
    private int $manager;
    private int $admin;
    private int $raiser;
    private int $type;
    private int $teamType;
    private int $team;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->engine      = new ReceivablesEngine($this->db);
        $this->svc         = new ArUserOffboarding($this->db);
        $this->company     = $this->makeCompany('ACME');
        $this->leaver      = $this->makeUserNamed('Len Leaver', 'len@example.test');
        $this->replacement = $this->makeUserNamed('Rita Replacement', 'rita@example.test');
        $this->early       = $this->makeUserNamed('Earl Early', 'earl@example.test');
        $this->manager     = $this->makeUserNamed('Mia Manager', 'mia@example.test');
        $this->admin       = $this->makeUserNamed('Ada Admin', 'ada@example.test', true);
        $this->raiser      = $this->makeUserNamed('Rae Raiser', 'rae@example.test');

        $this->grant($this->leaver, $this->company, ['approve', 'submit']);
        $this->grant($this->replacement, $this->company, ['approve', 'submit']);
        $this->grant($this->early, $this->company, ['approve']);
        $this->grant($this->raiser, $this->company, ['submit']);
        $this->grant($this->admin, $this->company, ['approve']);
        foreach ([$this->manager => 'receivables_workflow'] as $u => $role) {
            $this->db->table('user_roles')->insert(['user_id' => $u, 'role_id' => $this->roleId($role)]);
            service('permissions')->forget($u);
        }

        // Route 1: early approver, then the leaver issues.
        $this->type = $this->makeArRoute($this->company, [
            ['type' => 'approve', 'user' => $this->early, 'role' => 'Credit', 'label' => 'Earl Early'],
            ['type' => 'issue', 'user' => $this->leaver, 'role' => 'Billing', 'label' => 'Len Leaver'],
        ])['type'];

        // Route 2: a team step the leaver belongs to.
        $this->team     = $this->makeArTeam('Billing desk', [$this->leaver, $this->early]);
        $this->teamType = $this->makeArRoute($this->company, [['type' => 'issue', 'team' => $this->team]], 'Team sales')['type'];
    }

    private function draft(int $type, ?int $by = null, ?string $number = null): int
    {
        $by ??= $this->raiser;
        $id   = $this->engine->createDraft($by, $this->arDetails($this->company, $type, $number !== null ? ['invoice_number' => $number] : []));
        (new ArFiles($this->db))->store($id, $by, $this->pdfFile(), 'inv.pdf');

        return $id;
    }

    private function submitted(int $type, ?int $by = null): int
    {
        $id = $this->draft($type, $by);
        $this->engine->submit($id, $by ?? $this->raiser);

        return $id;
    }

    /** An invoice on route 1 waiting at the issue step, which the leaver holds. */
    private function atLeaver(): int
    {
        $id = $this->submitted($this->type);
        $this->engine->approve($id, $this->early);

        return $id;
    }

    /** @return array<string, mixed> */
    private function snapshotStep(int $invoiceId, int $order): array
    {
        foreach ($this->engine->stepsOf($this->arRow($invoiceId)) as $s) {
            if ((int) $s['order'] === $order) {
                return $s;
            }
        }

        $this->fail("No step {$order} on invoice {$invoiceId}");
    }

    private function failsWith(string $contains, callable $do): void
    {
        try {
            $do();
            $this->fail('Expected a RuntimeException containing: ' . $contains);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ the service

    public function testPreviewCountsTheWork(): void
    {
        $this->atLeaver();
        $this->submitted($this->type);                                   // leaver's step is still ahead
        $this->draft($this->type, $this->leaver);                         // leaver's own draft
        $claimed = $this->submitted($this->teamType);
        $this->engine->claim($claimed, $this->leaver);
        (new ArDelegationResolver($this->db))->create($this->leaver, $this->leaver, $this->early, null, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), null);

        $plan = $this->svc->preview($this->leaver);

        $this->assertCount(2, $plan['steps'], 'Two in-flight invoices have the leaver on a step ahead.');
        $this->assertCount(1, array_filter(array_column($plan['steps'], 'current')), 'Exactly one of them is the step the invoice is at now.');
        $this->assertSame(1, $plan['route_steps']);
        $this->assertSame(1, $plan['submitted']);
        $this->assertSame(1, $plan['claims']);
        $this->assertSame(1, $plan['delegations']);
        $this->assertSame(1, $plan['teams']);
        $this->assertSame([$this->company], $plan['need_approve']);
        $this->assertSame([$this->company], $plan['need_submit']);
    }

    public function testTransferMovesStepsRoutesDraftsClaimsAndDelegations(): void
    {
        $waiting = $this->atLeaver();                                    // at the leaver's step now
        $ahead   = $this->submitted($this->type);                        // leaver's step is still ahead
        $draft   = $this->draft($this->type, $this->leaver, 'AR-LEN');
        $issued  = $this->makeIssued($this->company, $this->leaver);     // already with the customer
        $claimed = $this->submitted($this->teamType);
        $this->engine->claim($claimed, $this->leaver);
        $dResolver = new ArDelegationResolver($this->db);
        $given     = $dResolver->create($this->leaver, $this->leaver, $this->early, null, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), null);
        $received  = $dResolver->create($this->early, $this->early, $this->leaver, null, date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), null);
        $actionsBefore = $this->db->table('ar_actions')->where('invoice_id', $waiting)->countAllResults();

        $r = $this->svc->transfer($this->manager, $this->leaver, $this->replacement, false);

        $this->assertSame(['steps' => 2, 'route_steps' => 1, 'submitted' => 1, 'claims' => 1, 'delegations' => 2, 'teams' => 0, 'disabled' => false], $r);

        // In-flight steps: re-assigned, with the name; the step already done keeps its person.
        $step = $this->snapshotStep($waiting, 2);
        $this->assertSame($this->replacement, (int) $step['user_id']);
        $this->assertSame('Rita Replacement', $step['label']);
        $this->assertSame($this->early, (int) $this->snapshotStep($waiting, 1)['user_id']);
        $this->assertSame($this->replacement, (int) $this->snapshotStep($ahead, 2)['user_id']);
        $this->assertStringContainsString('Approver changed from Len Leaver to Rita Replacement', $this->db->table('ar_notes')->where('invoice_id', $waiting)->get()->getRow()->body);
        $this->assertSame($actionsBefore, $this->db->table('ar_actions')->where('invoice_id', $waiting)->countAllResults(), 'History is not rewritten.');

        // Routes, so future invoices go to the replacement, with the name shown on them.
        $routeStep = $this->db->table('ar_route_steps')->where('step_type', 'issue')->where('assignee_kind', 'user')->get()->getRowArray();
        $this->assertSame($this->replacement, (int) $routeStep['assignee_user_id']);
        $this->assertSame('Rita Replacement', $routeStep['assignee_label']);

        // Raised invoices: unfinished move, issued stays.
        $this->assertSame($this->replacement, (int) $this->arRow($draft)['submitted_by']);
        $this->assertSame($this->leaver, (int) $this->arRow($issued)['submitted_by']);

        // Claim returned to the team, recorded.
        $this->assertNull($this->engine->openClaim($claimed, 1));
        $release = $this->db->table('ar_actions')->where(['invoice_id' => $claimed, 'action' => 'release'])->get()->getRowArray();
        $this->assertSame($this->manager, (int) $release['acted_by']);
        $this->assertSame($this->leaver, (int) $release['on_behalf_of']);

        // Delegations ended in both directions.
        $this->assertSame(0, (int) $this->db->table('ar_delegations')->where('id', $given)->get()->getRow()->active);
        $this->assertSame(0, (int) $this->db->table('ar_delegations')->where('id', $received)->get()->getRow()->active);

        // Still a team member and still active: it was not a leaving transfer.
        $this->assertSame(1, $this->db->table('ar_team_members')->where('user_id', $this->leaver)->countAllResults());

        // The replacement was told about the step that is theirs now, and can act; the leaver cannot.
        $this->assertSame(1, $this->bell($this->replacement, 'ar.submitted'));
        $this->assertTrue($this->engine->canAct($waiting, $this->replacement)['can_act']);
        $this->assertFalse($this->engine->canAct($waiting, $this->leaver)['can_act']);
        $this->engine->approve($waiting, $this->replacement, null, ['sent_to' => 'post']);
        $this->assertSame('issued', $this->arRow($waiting)['status']);

        $audit = $this->db->table('audit_log')->where('action', 'ar.work_transferred')->get()->getRowArray();
        $this->assertSame($this->manager, (int) $audit['actor_id']);
        $this->assertSame((string) $this->leaver, $audit['entity_id']);
    }

    public function testTheReplacementMustHoldTheRightsTheWorkNeeds(): void
    {
        $waiting = $this->atLeaver();
        $this->draft($this->type, $this->leaver);
        $weak = $this->makeUserNamed('Wes Weak');
        $this->grant($weak, $this->company, ['collect']);                 // sees the company, but cannot approve or raise

        $this->failsWith('"Can approve and issue" for ACME', fn () => $this->svc->transfer($this->manager, $this->leaver, $weak, false));
        $this->failsWith('"Can raise" for ACME', fn () => $this->svc->transfer($this->manager, $this->leaver, $weak, false));

        $stranger = $this->makeUserNamed('Sam Stranger');
        $this->failsWith('membership of ACME', fn () => $this->svc->transfer($this->manager, $this->leaver, $stranger, false));

        // The invoices rights are not enough either.
        $payables = $this->makeUserNamed('Pat Payables');
        $this->join($payables, $this->company, true, true);
        $this->failsWith('Can approve and issue', fn () => $this->svc->transfer($this->manager, $this->leaver, $payables, false));

        $this->assertSame($this->leaver, (int) $this->snapshotStep($waiting, 2)['user_id'], 'A refused transfer changes nothing.');
        $this->assertSame(0, $this->db->table('ar_notes')->where('invoice_id', $waiting)->countAllResults());
    }

    public function testGuards(): void
    {
        $this->failsWith('permission', fn () => $this->svc->transfer($this->raiser, $this->leaver, $this->replacement, false));
        $this->failsWith('different user', fn () => $this->svc->transfer($this->manager, $this->leaver, $this->leaver, false));
        $this->failsWith('active user', fn () => $this->svc->transfer($this->manager, $this->leaver, 999999, false));
        $this->failsWith('User not found', fn () => $this->svc->transfer($this->manager, 999999, $this->replacement, false));

        $gone = $this->makeUserNamed('Gone Gary', null, false, false);
        $this->failsWith('active user', fn () => $this->svc->transfer($this->manager, $this->leaver, $gone, false));

        // Only a manager who may switch accounts off can ask for that.
        $this->failsWith('switch off accounts', fn () => $this->svc->transfer($this->manager, $this->leaver, $this->replacement, true));
    }

    public function testTeamStepsStayWithTheTeam(): void
    {
        $team = $this->submitted($this->teamType);

        $this->svc->transfer($this->manager, $this->leaver, $this->replacement, false);

        $step = $this->snapshotStep($team, 1);
        $this->assertSame('team', $step['assignee_kind']);
        $this->assertSame($this->team, (int) $step['team_id']);
    }

    public function testNothingToMoveIsFine(): void
    {
        $idle = $this->makeUserNamed('Ida Idle');

        $r = $this->svc->transfer($this->manager, $idle, $this->replacement, false);

        $this->assertSame(['steps' => 0, 'route_steps' => 0, 'submitted' => 0, 'claims' => 0, 'delegations' => 0, 'teams' => 0, 'disabled' => false], $r);
    }

    // ------------------------------------------------------------------ the screen

    private function as(int $userId, string $method, string $path, ?array $params = null)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();
        db_connect()->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        $call = $this->withSession(['user' => ['id' => $userId]]);

        return $method === 'post' ? $call->post($path, ($params ?? []) + [csrf_token() => csrf_hash()]) : $call->get($path);
    }

    public function testTheScreenShowsWhatWouldMoveAndWhatIsMissing(): void
    {
        $this->atLeaver();
        $weak = $this->makeUserNamed('Wes Weak', 'wes@example.test');
        $this->grant($weak, $this->company, ['collect']);

        $page = $this->as($this->manager, 'get', 'receivables-admin/handover?from=' . $this->leaver . '&to=' . $this->replacement);
        $this->assertTrue($page->isOK());
        $page->assertSee('Approval and issue steps on invoices in progress');
        $page->assertSee('The replacement has what this work needs');
        $page->assertDontSee('Also switch off their account');

        $bad = $this->as($this->manager, 'get', 'receivables-admin/handover?from=' . $this->leaver . '&to=' . $weak);
        $bad->assertSee('cannot take this over yet');
        $bad->assertSee('Can approve and issue');

        $this->as($this->admin, 'get', 'receivables-admin/handover?from=' . $this->leaver . '&to=' . $this->replacement)->assertSee('Also switch off their account');
    }

    public function testTheScreenIsGated(): void
    {
        $user = $this->makeUserNamed('Una User', 'una@example.test');
        $this->db->table('user_roles')->insert(['user_id' => $user, 'role_id' => $this->roleId('receivables_user')]);
        service('permissions')->forget($user);

        $this->assertNotSame(200, $this->as($user, 'get', 'receivables-admin/handover')->response()->getStatusCode());

        $waiting = $this->atLeaver();
        $this->as($user, 'post', 'receivables-admin/handover', ['from' => (string) $this->leaver, 'to' => (string) $this->replacement]);
        $this->assertSame($this->leaver, (int) $this->snapshotStep($waiting, 2)['user_id']);
    }

    public function testHandingOverOverHttpMovesTheWork(): void
    {
        $waiting = $this->atLeaver();

        $this->as($this->manager, 'post', 'receivables-admin/handover', ['from' => (string) $this->leaver, 'to' => (string) $this->replacement]);

        $this->assertSame($this->replacement, (int) $this->snapshotStep($waiting, 2)['user_id']);
        $this->assertStringContainsString('Handed over', (string) session()->getFlashdata('message'));
        $this->assertSame('active', $this->db->table('staff_profiles')->where('user_id', $this->leaver)->get()->getRow()->status);
    }

    public function testSwitchingTheAccountOffAlsoLeavesTheTeamsAndReturnsClaims(): void
    {
        $claimed = $this->submitted($this->teamType);
        $this->engine->claim($claimed, $this->leaver);

        $this->as($this->admin, 'post', 'receivables-admin/handover', ['from' => (string) $this->leaver, 'to' => (string) $this->replacement, 'disable' => '1']);

        $this->assertStringContainsString('switched off', (string) session()->getFlashdata('message'));
        $this->assertSame('inactive', $this->db->table('staff_profiles')->where('user_id', $this->leaver)->get()->getRow()->status);
        $this->assertSame(0, $this->db->table('ar_team_members')->where('user_id', $this->leaver)->countAllResults());
        $this->assertSame(0, $this->db->table('ar_step_claims')->where('invoice_id', $claimed)->where('released_at IS NULL')->countAllResults());
        $this->assertSame(1, $this->db->table('ar_team_members')->where('user_id', $this->early)->countAllResults(), 'Other members stay.');
    }

    public function testARefusedTransferShowsTheReasonAndChangesNothing(): void
    {
        $waiting = $this->atLeaver();
        $weak    = $this->makeUserNamed('Wes Weak', 'wes@example.test');
        $this->grant($weak, $this->company, ['collect']);

        $this->as($this->manager, 'post', 'receivables-admin/handover', ['from' => (string) $this->leaver, 'to' => (string) $weak]);

        $this->assertStringContainsString('missing', (string) session()->getFlashdata('error'));
        $this->assertSame($this->leaver, (int) $this->snapshotStep($waiting, 2)['user_id']);
    }

    public function testTheAdminDashboardOffersTheHandoverToManagersOnly(): void
    {
        $this->as($this->manager, 'get', 'admin')->assertSee('receivables-admin/handover');

        $user = $this->makeUserNamed('Una User', 'una@example.test');
        $this->db->table('user_roles')->insert(['user_id' => $user, 'role_id' => $this->roleId('receivables_user')]);
        service('permissions')->forget($user);
        $this->as($user, 'get', 'receivables')->assertDontSee('receivables-admin/handover');
    }
}

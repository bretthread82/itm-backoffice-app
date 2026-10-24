<?php

declare(strict_types=1);

namespace Tests\Receivables;

use App\Core\Receivables\ArFiles;
use App\Core\Receivables\ReceivablesEngine;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Receivables\ReceivablesTestCase;

/**
 * The customer invoice admin screens: routes and the route builder, invoice types, route variants, teams and
 * delegations. Real requests through the filter pipeline.
 *
 * @internal
 */
final class ReceivablesAdminTest extends ReceivablesTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    private int $company;
    private int $manager;
    private int $user;
    private int $approver;
    private int $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        session();

        $this->company  = $this->makeCompany('ACME');
        $this->manager  = $this->makeUserNamed('Mia Manager', 'mia@example.test');
        $this->user     = $this->makeUserNamed('Una User', 'una@example.test');
        $this->approver = $this->makeUserNamed('Alan Approver', 'alan@example.test');
        $this->issuer   = $this->makeUserNamed('Ivy Issuer', 'ivy@example.test');

        $this->seedVariants();
        $this->giveRole($this->manager, 'receivables_workflow');
        $this->giveRole($this->user, 'receivables_user');
        $this->grant($this->approver, $this->company, ['approve']);
        $this->grant($this->issuer, $this->company, ['approve']);
    }

    private function giveRole(int $userId, string $role): void
    {
        $this->db->table('user_roles')->insert(['user_id' => $userId, 'role_id' => $this->roleId($role)]);
        service('permissions')->forget($userId);
    }

    /** One fresh request as a signed-in user (Shield caches its state per request). */
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

    /** A route built on the screen: company, a custom type and the standard variant. Returns the route id. */
    private function newRoute(string $type = 'Sales'): int
    {
        $this->as($this->manager, 'post', 'receivables-admin/routes', ['company_id' => (string) $this->company, 'type_choice' => 'other', 'type_name' => $type, 'variant' => 'default']);

        return (int) $this->db->table('ar_routes')->orderBy('id', 'DESC')->get(1)->getRow()->id;
    }

    /** @return array<string, mixed> */
    private function steps(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $r) {
            $out['s' . $i] = $r + ['job_title' => '', 'sla_days' => ''];
        }

        return ['steps' => $out];
    }

    private function approveStep(int $user): array
    {
        return ['step_type' => 'approve', 'assignee_kind' => 'user', 'user_id' => (string) $user];
    }

    private function issueStep(int $user): array
    {
        return ['step_type' => 'issue', 'assignee_kind' => 'user', 'user_id' => (string) $user];
    }

    // ------------------------------------------------------------------ rendering and access

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function adminScreens(): iterable
    {
        foreach (['receivables-admin/routes', 'receivables-admin/routes/new', 'receivables-admin/route-variants', 'receivables-admin/route-variants/new', 'receivables-admin/invoice-types', 'receivables-admin/teams', 'receivables-admin/teams/new', 'receivables/delegations'] as $path) {
            yield $path => [$path];
        }
    }

    /**
     * @dataProvider adminScreens
     */
    public function testScreensRenderForAManager(string $path): void
    {
        $result = $this->as($this->manager, 'get', $path);

        $this->assertTrue($result->isOK(), "GET /{$path} returned " . $result->response()->getStatusCode());
    }

    public function testOrdinaryUsersAreKeptOutOfSetup(): void
    {
        foreach (['receivables-admin/routes', 'receivables-admin/teams', 'receivables-admin/invoice-types', 'receivables-admin/route-variants'] as $path) {
            $this->assertNotSame(200, $this->as($this->user, 'get', $path)->response()->getStatusCode(), "GET /{$path} must be gated");
        }

        $this->as($this->user, 'post', 'receivables-admin/routes', ['company_id' => (string) $this->company, 'type_choice' => 'other', 'type_name' => 'Sneaky', 'variant' => 'default']);
        $this->assertSame(0, $this->db->table('ar_routes')->countAllResults());
        $this->assertTrue($this->as($this->user, 'get', 'receivables/delegations')->isOK(), 'Everyone with access manages their own delegations.');
    }

    public function testTheAdminDashboardLinksToTheSetupScreens(): void
    {
        $result = $this->as($this->manager, 'get', 'admin');

        foreach (['receivables-admin/routes', 'receivables-admin/teams', 'receivables-admin/invoice-types', 'receivables-admin/route-variants'] as $url) {
            $result->assertSee($url);
        }

        $this->as($this->manager, 'get', 'receivables')->assertSee('receivables/delegations');

        $plain = $this->as($this->user, 'get', 'receivables');
        $plain->assertDontSee('receivables-admin/routes');
        $plain->assertSee('receivables/delegations');
    }

    public function testNothingOnTheSetupScreensUsesInlineStyles(): void
    {
        $route = $this->newRoute();
        $html  = (string) $this->as($this->manager, 'get', 'receivables-admin/routes/' . $route . '/edit')->response()->getBody();

        $this->assertStringNotContainsString('style="', $html, 'The strict CSP blocks inline style attributes.');
        $this->assertStringContainsString('ar-step-handle', $html);
    }

    public function testSetupHelpIsOnlyForThoseWhoCanSetUp(): void
    {
        $this->assertContains('receivables-setup', array_keys(service('help')->topics($this->manager)));
        $this->assertNotContains('receivables-setup', array_keys(service('help')->topics($this->user)));
        $this->assertContains('Teams', array_column(service('help')->topic($this->manager, 'receivables-setup')['sections'], 'heading'));
    }

    // ------------------------------------------------------------------ routes

    public function testCreatingARouteMakesTheTypeAndStartsItSwitchedOff(): void
    {
        $id = $this->newRoute('Equipment sales');

        $route = $this->db->table('ar_routes')->where('id', $id)->get()->getRowArray();
        $this->assertSame(0, (int) $route['active']);
        $this->assertSame('default', $route['variant']);
        $this->assertSame('Equipment sales', $this->db->table('ar_invoice_types')->where('id', $route['invoice_type_id'])->get()->getRow()->name);

        // The same company, type and variant cannot be created twice.
        $before = $this->db->table('ar_routes')->countAllResults();
        $this->as($this->manager, 'post', 'receivables-admin/routes', ['company_id' => (string) $this->company, 'type_choice' => 'other', 'type_name' => 'equipment SALES', 'variant' => 'default']);
        $this->assertSame($before, $this->db->table('ar_routes')->countAllResults());
    }

    public function testASavedRouteCanBeSwitchedOnAndCarriesAnInvoiceEndToEnd(): void
    {
        $id = $this->newRoute();
        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([
            ['step_type' => 'submit', 'assignee_kind' => 'external', 'label' => 'Whoever raises it', 'email' => ''],
            $this->approveStep($this->approver) + ['job_title' => 'Credit controller', 'sla_days' => '3'],
            $this->issueStep($this->issuer),
        ]) + ['active' => '1', 'variant_trigger' => 'none']);

        $route = $this->db->table('ar_routes')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, (int) $route['active']);
        $this->assertSame(2, (int) $route['version'], 'Each save bumps the version.');
        $steps = $this->db->table('ar_route_steps')->where('route_id', $id)->orderBy('step_order')->get()->getResultArray();
        $this->assertSame(['submit', 'approve', 'issue'], array_column($steps, 'step_type'));
        $this->assertSame(3, (int) $steps[1]['sla_days']);
        $this->assertSame('Credit controller', $this->db->table('ar_job_titles')->where('id', $steps[1]['job_title_id'])->get()->getRow()->name);

        // The engine can now carry an invoice along the route the screen built.
        $raiser = $this->makeUserNamed('Rae Raiser');
        $this->grant($raiser, $this->company, ['submit']);
        $engine = new ReceivablesEngine($this->db);
        $inv    = $engine->createDraft($raiser, $this->arDetails($this->company, (int) $route['invoice_type_id']));
        (new ArFiles($this->db))->store($inv, $raiser, $this->pdfFile(), 'inv.pdf');
        $engine->submit($inv, $raiser);
        $engine->approve($inv, $this->approver);
        $engine->approve($inv, $this->issuer, null, ['sent_to' => 'post']);

        $this->assertSame('issued', $this->arRow($inv)['status']);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string}>
     */
    public static function unfinishedRoutes(): iterable
    {
        yield 'no steps' => [[], 'Add at least one step'];
        yield 'no issue step' => [['approve'], 'no approval steps|exactly one issue step'];
        yield 'only notify steps' => [['notify'], 'no approval steps'];
    }

    /**
     * @dataProvider unfinishedRoutes
     *
     * @param list<string> $types
     */
    public function testARouteWithoutAnIssueStepCannotBeSwitchedOn(array $types, string $message): void
    {
        $id   = $this->newRoute();
        $rows = array_map(fn (string $t): array => ['step_type' => $t, 'assignee_kind' => 'user', 'user_id' => (string) $this->approver], $types);

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps($rows) + ['active' => '1', 'variant_trigger' => 'none']);

        $this->assertSame(0, (int) $this->db->table('ar_routes')->where('id', $id)->get()->getRow()->active);
        $this->assertMatchesRegularExpression('/' . $message . '/', (string) session()->getFlashdata('error'));

        // Saved switched off, a half-built route is fine (it just cannot be used yet).
        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps($rows) + ['variant_trigger' => 'none']);
        $this->assertSame(count($rows), $this->db->table('ar_route_steps')->where('route_id', $id)->countAllResults());
    }

    public function testStepOrderRulesForTheIssueStep(): void
    {
        $id = $this->newRoute();

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([$this->issueStep($this->issuer), $this->approveStep($this->approver)]) + ['active' => '1', 'variant_trigger' => 'none']);
        $this->assertStringContainsString('issue step must come after every approve step', (string) session()->getFlashdata('error'));

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([$this->issueStep($this->issuer), $this->issueStep($this->issuer)]) + ['variant_trigger' => 'none']);
        $this->assertStringContainsString('only one issue step', (string) session()->getFlashdata('error'));

        $this->assertSame(0, $this->db->table('ar_route_steps')->where('route_id', $id)->countAllResults(), 'A refused save writes nothing.');
    }

    public function testStepsNeedSomeoneWhoMayApproveForTheCompany(): void
    {
        $id        = $this->newRoute();
        $nobody    = $this->makeUserNamed('Nat NoRights');
        $this->grant($nobody, $this->company, ['submit']);     // can raise, cannot approve

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([$this->approveStep($nobody), $this->issueStep($this->issuer)]) + ['variant_trigger' => 'none']);
        $this->assertStringContainsString('Can approve and issue', (string) session()->getFlashdata('error'));
        $this->assertSame(0, $this->db->table('ar_route_steps')->where('route_id', $id)->countAllResults());

        // The payables approve right is not enough either.
        $payables = $this->makeUserNamed('Pat Payables');
        $this->join($payables, $this->company, true, true);
        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([$this->approveStep($payables), $this->issueStep($this->issuer)]) + ['variant_trigger' => 'none']);
        $this->assertStringContainsString('Can approve and issue', (string) session()->getFlashdata('error'));
    }

    public function testATeamStepNeedsAMemberWhoMayApprove(): void
    {
        $id   = $this->newRoute();
        $mute = $this->makeUserNamed('Mo Mute');
        $this->grant($mute, $this->company, ['submit']);
        $weak = $this->makeArTeam('Weak team', [$mute]);
        $good = $this->makeArTeam('Billing desk', [$this->approver]);

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([['step_type' => 'issue', 'assignee_kind' => 'team', 'team_id' => (string) $weak]]) + ['variant_trigger' => 'none']);
        $this->assertStringContainsString('no one in the team', (string) session()->getFlashdata('error'));

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([['step_type' => 'issue', 'assignee_kind' => 'team', 'team_id' => (string) $good]]) + ['active' => '1', 'variant_trigger' => 'none']);
        $this->assertSame(1, (int) $this->db->table('ar_routes')->where('id', $id)->get()->getRow()->active);
    }

    public function testTimeLimitsAreOnlyForApproveAndIssueSteps(): void
    {
        $id = $this->newRoute();

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([
            ['step_type' => 'notify', 'assignee_kind' => 'external', 'label' => 'FYI', 'email' => '', 'sla_days' => '2'],
        ]) + ['variant_trigger' => 'none']);
        $this->assertStringContainsString('only approve and issue steps can have a time limit', (string) session()->getFlashdata('error'));

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([$this->approveStep($this->approver) + ['sla_days' => '400']]) + ['variant_trigger' => 'none']);
        $this->assertStringContainsString('1 to 365', (string) session()->getFlashdata('error'));
    }

    public function testAVariantRouteNeedsATriggerBeforeItIsSwitchedOn(): void
    {
        $this->as($this->manager, 'post', 'receivables-admin/routes', ['company_id' => (string) $this->company, 'type_choice' => 'other', 'type_name' => 'Sales', 'variant' => 'default']);
        $this->as($this->manager, 'post', 'receivables-admin/route-variants', ['name' => 'Big deals', 'sort_order' => '10']);
        $this->as($this->manager, 'post', 'receivables-admin/routes', ['company_id' => (string) $this->company, 'type_choice' => 'other', 'type_name' => 'Sales', 'variant' => 'big_deals']);
        $id = (int) $this->db->table('ar_routes')->where('variant', 'big_deals')->get()->getRow()->id;

        $rows = $this->steps([$this->approveStep($this->approver), $this->issueStep($this->issuer)]);

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $rows + ['active' => '1', 'variant_trigger' => 'none']);
        $this->assertStringContainsString('Choose when this', (string) session()->getFlashdata('error'));

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $rows + ['active' => '1', 'variant_trigger' => 'amount_threshold', 'trigger_value' => '0']);
        $this->assertStringContainsString('number above zero', (string) session()->getFlashdata('error'));

        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $rows + ['active' => '1', 'variant_trigger' => 'amount_threshold', 'trigger_value' => '5000']);
        $route = $this->db->table('ar_routes')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, (int) $route['active']);
        $this->assertSame('5000', $route['trigger_value']);

        $page = (string) $this->as($this->manager, 'get', 'receivables-admin/routes/' . $id . '/edit')->response()->getBody();
        $this->assertStringContainsString('Big deals', $page);
        $this->assertStringNotContainsString('<?=', $page, 'The variant badge must be rendered, not printed as code.');
    }

    public function testTheRoutesListFilters(): void
    {
        $this->newRoute('Widgets');
        $this->newRoute('Gadgets');

        $this->as($this->manager, 'get', 'receivables-admin/routes?q=Widgets')->assertSee('Widgets');
        $this->as($this->manager, 'get', 'receivables-admin/routes?q=Widgets')->assertDontSee('Gadgets');
        $this->as($this->manager, 'get', 'receivables-admin/routes?status=active')->assertDontSee('Widgets');
    }

    // ------------------------------------------------------------------ invoice types

    public function testDefaultTypesAreAssignedToCompaniesAsOrdinaryTypes(): void
    {
        $other = $this->makeCompany('BETA');
        $this->as($this->manager, 'post', 'receivables-admin/invoice-types', ['name' => 'Service contracts']);
        $default = (int) $this->db->table('ar_default_invoice_types')->get()->getRow()->id;

        $this->as($this->manager, 'post', 'receivables-admin/invoice-types/assign', ['type_ids' => [(string) $default], 'company_ids' => [(string) $this->company, (string) $other]]);
        $this->assertSame(2, $this->db->table('ar_invoice_types')->where('default_type_id', $default)->countAllResults());

        // Assigning again does not duplicate.
        $this->as($this->manager, 'post', 'receivables-admin/invoice-types/assign', ['type_ids' => [(string) $default], 'company_ids' => [(string) $this->company]]);
        $this->assertSame(2, $this->db->table('ar_invoice_types')->where('default_type_id', $default)->countAllResults());

        // A duplicate default name is refused.
        $this->as($this->manager, 'post', 'receivables-admin/invoice-types', ['name' => 'service CONTRACTS']);
        $this->assertSame(1, $this->db->table('ar_default_invoice_types')->countAllResults());

        $this->as($this->manager, 'get', 'receivables-admin/invoice-types')->assertSee('Service contracts');
    }

    public function testHighPriorityFollowsADefaultToItsCopiesAndCanBeSetPerCompanyType(): void
    {
        $this->as($this->manager, 'post', 'receivables-admin/invoice-types', ['name' => 'Rush']);
        $default = (int) $this->db->table('ar_default_invoice_types')->get()->getRow()->id;
        $this->as($this->manager, 'post', 'receivables-admin/invoice-types/assign', ['type_ids' => [(string) $default], 'company_ids' => [(string) $this->company]]);
        $copy = (int) $this->db->table('ar_invoice_types')->get()->getRow()->id;

        $this->as($this->manager, 'post', 'receivables-admin/invoice-types/' . $default, ['name' => 'Rush', 'active' => '1', 'high_priority' => '1']);
        $this->assertSame(1, (int) $this->db->table('ar_invoice_types')->where('id', $copy)->get()->getRow()->high_priority);

        $this->as($this->manager, 'post', 'receivables-admin/company-types/' . $copy . '/priority', ['return' => 'receivables-admin/invoice-types']);
        $this->assertSame(0, (int) $this->db->table('ar_invoice_types')->where('id', $copy)->get()->getRow()->high_priority);

        $this->as($this->manager, 'post', 'receivables-admin/invoice-types/' . $default . '/delete');
        $this->assertSame(0, $this->db->table('ar_default_invoice_types')->countAllResults());
        $this->assertNull($this->db->table('ar_invoice_types')->where('id', $copy)->get()->getRow()->default_type_id, 'Copies are kept.');
    }

    // ------------------------------------------------------------------ route variants

    public function testRouteVariantsCanBeAddedRenamedAndDeletedWhenUnused(): void
    {
        $this->as($this->manager, 'post', 'receivables-admin/route-variants', ['name' => 'Big deals', 'sort_order' => '10']);
        $v = $this->db->table('ar_route_variants')->where('variant_key', 'big_deals')->get()->getRowArray();
        $this->assertNotNull($v);

        $before = $this->db->table('ar_route_variants')->countAllResults();
        $this->as($this->manager, 'post', 'receivables-admin/route-variants', ['name' => 'Big Deals']);
        $this->as($this->manager, 'post', 'receivables-admin/route-variants', ['name' => 'Standard', 'variant_key' => 'default']);
        $this->assertSame($before, $this->db->table('ar_route_variants')->countAllResults(), 'Duplicates and the standard key are refused.');

        $this->as($this->manager, 'post', 'receivables-admin/route-variants/' . $v['id'], ['name' => 'Large deals', 'active' => '1', 'sort_order' => '20']);
        $this->assertSame('Large deals', $this->db->table('ar_route_variants')->where('id', $v['id'])->get()->getRow()->name);

        $std = $this->db->table('ar_route_variants')->where('variant_key', 'default')->get()->getRow();
        $this->as($this->manager, 'post', 'receivables-admin/route-variants/' . $std->id . '/delete');
        $this->assertSame(1, $this->db->table('ar_route_variants')->where('variant_key', 'default')->countAllResults(), 'The standard variant cannot be deleted.');

        $this->as($this->manager, 'post', 'receivables-admin/route-variants/' . $v['id'] . '/delete');
        $this->assertSame(0, $this->db->table('ar_route_variants')->where('variant_key', 'big_deals')->countAllResults());
    }

    // ------------------------------------------------------------------ teams

    public function testTeamsAreCreatedWithMembersAndGuardedWhileUsed(): void
    {
        $this->as($this->manager, 'post', 'receivables-admin/teams', ['name' => 'Billing desk', 'group_email' => 'Billing@Example.test', 'members' => [(string) $this->approver, (string) $this->issuer]]);
        $team = $this->db->table('ar_teams')->get()->getRowArray();
        $this->assertSame('billing@example.test', $team['group_email']);
        $this->assertSame(2, $this->db->table('ar_team_members')->where('team_id', $team['id'])->countAllResults());

        $before = $this->db->table('ar_teams')->countAllResults();
        $this->as($this->manager, 'post', 'receivables-admin/teams', ['name' => 'billing DESK']);
        $this->as($this->manager, 'post', 'receivables-admin/teams', ['name' => 'Bad mail', 'group_email' => 'not-an-email']);
        $this->assertSame($before, $this->db->table('ar_teams')->countAllResults());

        // Use it on an active route, then it cannot be switched off.
        $id = $this->newRoute();
        $this->as($this->manager, 'post', 'receivables-admin/routes/' . $id, $this->steps([['step_type' => 'issue', 'assignee_kind' => 'team', 'team_id' => (string) $team['id']]]) + ['active' => '1', 'variant_trigger' => 'none']);
        $this->as($this->manager, 'post', 'receivables-admin/teams/' . $team['id'], ['name' => 'Billing desk', 'members' => [(string) $this->approver]]);
        $this->assertStringContainsString('used by 1 active route step', (string) session()->getFlashdata('error'));
        $this->assertSame(1, (int) $this->db->table('ar_teams')->where('id', $team['id'])->get()->getRow()->active);

        // Members can change while it stays on.
        $this->as($this->manager, 'post', 'receivables-admin/teams/' . $team['id'], ['name' => 'Billing desk', 'active' => '1', 'members' => [(string) $this->approver]]);
        $this->assertSame(1, $this->db->table('ar_team_members')->where('team_id', $team['id'])->countAllResults());
    }

    public function testRemovingAMemberReturnsWhatTheyHadAcceptedToTheTeam(): void
    {
        $team = $this->makeArTeam('Billing desk', [$this->approver, $this->issuer]);
        $route = $this->makeArRoute($this->company, [['type' => 'issue', 'team' => $team]]);
        $raiser = $this->makeUserNamed('Rae Raiser');
        $this->grant($raiser, $this->company, ['submit']);
        $engine = new ReceivablesEngine($this->db);
        $inv    = $engine->createDraft($raiser, $this->arDetails($this->company, $route['type']));
        (new ArFiles($this->db))->store($inv, $raiser, $this->pdfFile(), 'inv.pdf');
        $engine->submit($inv, $raiser);
        $engine->claim($inv, $this->approver);

        $this->as($this->manager, 'post', 'receivables-admin/teams/' . $team, ['name' => 'Billing desk', 'active' => '1', 'members' => [(string) $this->issuer]]);

        $this->assertNull($engine->openClaim($inv, 1), 'The claim went back to the team.');
        $this->assertTrue($engine->canAct($inv, $this->issuer)['can_claim']);
        $this->assertContains('release', array_column($this->arActions($inv), 'action'));
    }

    // ------------------------------------------------------------------ delegations

    public function testDelegationsCanBeMadeAndEndedByTheirOwner(): void
    {
        $deputy = $this->makeUserNamed('Dee Deputy', 'dee@example.test');
        $this->grant($deputy, $this->company, ['approve']);
        $this->grant($this->approver, $this->company, ['submit']);
        $this->giveRole($this->approver, 'receivables_user');

        $start = date('Y-m-d\TH:i', strtotime('-1 hour'));
        $end   = date('Y-m-d\TH:i', strtotime('+2 days'));
        $this->as($this->approver, 'post', 'receivables/delegations', ['delegate_id' => (string) $deputy, 'company_id' => '', 'starts_at' => $start, 'ends_at' => $end, 'reason' => 'Holiday']);

        $row = $this->db->table('ar_delegations')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame($this->approver, (int) $row['delegator_id']);
        $this->assertSame($deputy, (int) $row['delegate_id']);
        $this->as($this->approver, 'get', 'receivables/delegations')->assertSee('Dee Deputy');

        // Someone else cannot end it; the owner can.
        $this->as($this->user, 'post', 'receivables/delegations/' . $row['id'] . '/end');
        $this->assertSame(1, (int) $this->db->table('ar_delegations')->where('id', $row['id'])->get()->getRow()->active);
        $this->as($this->approver, 'post', 'receivables/delegations/' . $row['id'] . '/end');
        $this->assertSame(0, (int) $this->db->table('ar_delegations')->where('id', $row['id'])->get()->getRow()->active);
    }

    public function testOnlyManagersSeeAllDelegationsOrSetOneUpForSomeoneElse(): void
    {
        $deputy = $this->makeUserNamed('Dee Deputy');
        $this->grant($deputy, $this->company, ['approve']);
        $start = date('Y-m-d\TH:i', strtotime('-1 hour'));
        $end   = date('Y-m-d\TH:i', strtotime('+2 days'));

        // An ordinary user asking for ?all=1 just gets their own screen, and cannot delegate for another person.
        $this->as($this->user, 'post', 'receivables/delegations?all=1', ['delegator_id' => (string) $this->approver, 'delegate_id' => (string) $deputy, 'starts_at' => $start, 'ends_at' => $end]);
        $this->assertSame(0, $this->db->table('ar_delegations')->where('delegator_id', $this->approver)->countAllResults());

        $this->as($this->manager, 'post', 'receivables/delegations?all=1', ['delegator_id' => (string) $this->approver, 'delegate_id' => (string) $deputy, 'starts_at' => $start, 'ends_at' => $end]);
        $this->assertSame(1, $this->db->table('ar_delegations')->where('delegator_id', $this->approver)->countAllResults());
        $this->as($this->manager, 'get', 'receivables/delegations?all=1')->assertSee('All delegations');
    }
}

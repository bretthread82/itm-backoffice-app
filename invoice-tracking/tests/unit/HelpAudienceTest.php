<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdminAccess;
use App\Services\HelpAudience;
use App\Services\NotificationService;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class HelpAudienceTest extends AppTestCase
{
    private int $acme;
    private int $admin;
    private HelpAudience $audience;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audience = new HelpAudience($this->db);
        $this->admin    = $this->makeUser('Admin', true);
        $this->acme     = $this->makeCompany('ACME');
    }

    /** @return array{submitter: bool, approver: bool, group: bool, admin: bool} */
    private function caps(int $userId, bool $admin = false): array
    {
        return $this->audience->for($userId, $admin);
    }

    public function testPlainMemberHasNoCapabilities(): void
    {
        $u = $this->makeUserWithEmail('Pat', 'pat@example.test');
        $this->join($u, $this->acme);

        $this->assertSame(['submitter' => false, 'approver' => false, 'group' => false, 'admin' => false, 'manager' => false, 'workflow' => false], $this->caps($u));
    }

    public function testSubmitterNeedsCanSubmitOnAnActiveCompany(): void
    {
        $sue = $this->makeUserWithEmail('Sue', 'sue@example.test');
        $this->join($sue, $this->acme, true);
        $this->assertTrue($this->caps($sue)['submitter']);

        $this->db->table('companies')->where('id', $this->acme)->update(['active' => 0]);
        $this->assertFalse($this->caps($sue)['submitter']);
    }

    public function testApproverWhenNamedOnAnActiveRouteButNotAnInactiveOne(): void
    {
        $alan = $this->makeUserWithEmail('Alan', 'alan@example.test');
        $this->join($alan, $this->acme);
        $ids = $this->makeRoute($this->acme, [['type' => 'approve', 'user' => $alan, 'role' => 'Accountant']], 'Standard', 'default', false);
        $this->assertFalse($this->caps($alan)['approver']);

        $this->db->table('routes')->where('id', $ids['route'])->update(['active' => 1]);
        $this->assertTrue($this->caps($alan)['approver']);
    }

    public function testNotifyOnlyStepDoesNotMakeAnApprover(): void
    {
        $n = $this->makeUserWithEmail('Nora', 'nora@example.test');
        $this->join($n, $this->acme);
        $this->makeRoute($this->acme, [['type' => 'notify', 'user' => $n, 'role' => 'Observer']]);

        $this->assertFalse($this->caps($n)['approver']);
    }

    public function testTeamMemberIsAnApproverOnlyForAnActiveTeamOnAnActiveRoute(): void
    {
        $m    = $this->makeUserWithEmail('Maria', 'maria@example.test');
        $team = $this->makeTeam('AP Team', [$m]);
        $this->join($m, $this->acme);
        $this->makeRoute($this->acme, [['type' => 'approve', 'team' => $team, 'role' => 'AP']]);
        $this->assertTrue($this->caps($m)['approver']);

        $this->db->table('teams')->where('id', $team)->update(['active' => 0]);
        $this->assertFalse($this->caps($m)['approver']);
    }

    public function testDelegateIsAnApproverUntilTheDelegationEnds(): void
    {
        $boss = $this->makeUserWithEmail('Boss', 'boss@example.test');
        $dave = $this->makeUserWithEmail('Dave', 'dave@example.test');
        $this->join($boss, $this->acme);
        $this->join($dave, $this->acme);

        $this->db->table('delegations')->insert([
            'delegator_id' => $boss, 'delegate_id' => $dave, 'company_id' => null, 'active' => 1,
            'starts_at' => date('Y-m-d H:i:s', time() - 86400), 'ends_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $this->assertTrue($this->caps($dave)['approver']);

        $this->db->table('delegations')->update(['ends_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertFalse($this->caps($dave)['approver']);
    }

    public function testSomeoneWhoHasActedBeforeIsStillAnApprover(): void
    {
        $e   = $this->makeUserWithEmail('Eve', 'eve@example.test');
        $sue = $this->makeUserWithEmail('Sue', 'sue@example.test');
        $this->join($e, $this->acme);
        $inv = $this->makeInvoice($this->acme, $sue);
        $this->db->table('invoice_actions')->insert(['invoice_id' => $inv, 'step_order' => 2, 'acted_by' => $e, 'acted_as' => 'normal', 'action' => 'approve', 'acted_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->caps($e)['approver']);
        // Submitting is not approving.
        $this->db->table('invoice_actions')->insert(['invoice_id' => $inv, 'acted_by' => $sue, 'acted_as' => 'normal', 'action' => 'submit', 'acted_at' => date('Y-m-d H:i:s')]);
        $this->assertFalse($this->caps($sue)['approver']);
    }

    public function testGroupLevelAndAdminAreIndependent(): void
    {
        $gary = $this->makeUserWithEmail('Gary', 'gary@example.test');
        (new AdminAccess($this->db))->grantGroupLevel($this->admin, $gary);

        $this->assertTrue($this->caps($gary)['group']);
        $this->assertFalse($this->caps($gary)['admin']);

        $adminCaps = $this->caps($this->admin, true);
        $this->assertTrue($adminCaps['admin']);
        $this->assertFalse($adminCaps['group']);
    }

    public function testAllowsUsesAnyOfAndEmptyMeansEveryone(): void
    {
        $caps = ['submitter' => false, 'approver' => true, 'group' => false, 'admin' => false];

        $this->assertTrue(HelpAudience::allows(null, $caps));
        $this->assertTrue(HelpAudience::allows([], $caps));
        $this->assertTrue(HelpAudience::allows(['group', 'approver'], $caps));
        $this->assertFalse(HelpAudience::allows(['group', 'admin'], $caps));
        $this->assertFalse(HelpAudience::allows(['nonsense'], $caps));
    }

    public function testEveryNotificationEventHasAnAudience(): void
    {
        $this->assertSame([], array_diff(array_keys(NotificationService::EVENTS), array_keys(HelpAudience::EVENT_NEEDS)), 'A new event needs an entry in HelpAudience::EVENT_NEEDS');
    }
}

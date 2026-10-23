<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\InvoiceFiles;
use App\Services\WorkflowEngine;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class TeamsScreensTest extends FeatureTestCase
{
    private \CodeIgniter\Shield\Entities\User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->shieldUser('boss@example.test', 'The Admin', true);
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        parent::tearDown();
    }

    public function testNonAdminCannotOpenTeamScreens(): void
    {
        $user = $this->shieldUser('plain@example.test', 'Plain');

        foreach (['admin/teams', 'admin/teams/new'] as $uri) {
            $this->actingAs($user)->get($uri)->assertRedirectTo(site_url('/'));
        }
    }

    public function testCreateEditAndListATeam(): void
    {
        $a = $this->makeUser('Ann Team');
        $b = $this->makeUser('Bob Team');

        $this->actingAs($this->admin)->post('admin/teams', ['name' => 'AP Team', 'members' => [(string) $a, (string) $b]]);
        $team = $this->db->table('teams')->where('name', 'AP Team')->get()->getRowArray();
        $this->assertNotNull($team);
        $this->assertCount(2, $this->db->table('team_members')->where('team_id', $team['id'])->get()->getResultArray());

        // A duplicate name is refused.
        $this->actingAs($this->admin)->post('admin/teams', ['name' => 'ap team']);
        $this->assertSame(1, $this->db->table('teams')->countAllResults());

        // Drop Bob.
        $this->actingAs($this->admin)->post('admin/teams/' . $team['id'], ['name' => 'AP Team', 'active' => '1', 'members' => [(string) $a]]);
        $this->assertSame([$a], array_map('intval', array_column($this->db->table('team_members')->get()->getResultArray(), 'user_id')));

        $this->actingAs($this->admin)->get('admin/teams')->assertSee('AP Team');
        $this->actingAs($this->admin)->get('admin/teams/' . $team['id'] . '/edit')->assertSee('Ann Team');
    }

    public function testRouteEditorSavesATeamStepAndRefusesBadOnes(): void
    {
        $company = $this->makeCompany('ACME');
        $member  = $this->makeUser('Team Member');
        $this->join($member, $company);
        $team    = $this->makeTeam('AP Team', [$member]);
        $off     = $this->makeTeam('Old Team', [], false);
        $route   = $this->makeRoute($company, [['type' => 'approve', 'user' => $this->makeUser('Someone')]], 'Standard', 'default', false)['route'];

        $post = fn (array $step): array => ['active' => '1', 'variant_trigger' => 'none', 'steps' => ['a' => $step]];

        $this->actingAs($this->admin)->get('admin/routes/' . $route . '/edit')->assertSee('A team');

        $this->actingAs($this->admin)->post('admin/routes/' . $route, $post(['step_type' => 'approve', 'assignee_kind' => 'team', 'team_id' => (string) $team]));
        $saved = $this->db->table('route_steps')->where('route_id', $route)->get()->getResultArray();
        $this->assertSame('team', $saved[0]['assignee_kind']);
        $this->assertSame($team, (int) $saved[0]['team_id']);
        $this->assertSame('AP Team', $saved[0]['assignee_label']);

        // Not on notify steps for a team nobody would hear, not an inactive team, not a missing one: the saved route stays as it was.
        foreach ([
            ['step_type' => 'notify', 'assignee_kind' => 'team', 'team_id' => (string) $team],
            ['step_type' => 'approve', 'assignee_kind' => 'team', 'team_id' => (string) $off],
            ['step_type' => 'approve', 'assignee_kind' => 'team', 'team_id' => ''],
        ] as $bad) {
            $this->actingAs($this->admin)->post('admin/routes/' . $route, $post($bad));
            $this->assertSame($team, (int) $this->db->table('route_steps')->where('route_id', $route)->get()->getRowArray()['team_id']);
        }

        // A team on an active route cannot be switched off.
        $this->actingAs($this->admin)->post('admin/teams/' . $team, ['name' => 'AP Team', 'members' => []]);
        $this->assertSame(1, (int) $this->db->table('teams')->where('id', $team)->get()->getRowArray()['active']);
    }

    public function testGroupMailboxIsSavedValidatedAndUsableOnNotifySteps(): void
    {
        $company = $this->makeCompany('ACME');
        $route   = $this->makeRoute($company, [['type' => 'approve', 'user' => $this->makeUser('Someone')]], 'Standard', 'default', false)['route'];

        $this->actingAs($this->admin)->post('admin/teams', ['name' => 'Bad', 'group_email' => 'not-an-email', 'members' => []]);
        $this->assertSame(0, $this->db->table('teams')->where('name', 'Bad')->countAllResults());

        $this->actingAs($this->admin)->post('admin/teams', ['name' => 'Finance', 'group_email' => ' Finance@Example.test ', 'members' => []]);
        $team = $this->db->table('teams')->where('name', 'Finance')->get()->getRowArray();
        $this->assertSame('finance@example.test', $team['group_email']);

        // A notify step may use a team that has only a group mailbox.
        $this->actingAs($this->admin)->post('admin/routes/' . $route, ['active' => '', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => 'notify', 'assignee_kind' => 'team', 'team_id' => (string) $team['id']],
        ]]);
        $this->assertSame('team', $this->db->table('route_steps')->where('route_id', $route)->get()->getRowArray()['assignee_kind']);

        // The retired shared mailbox kind is refused.
        $this->actingAs($this->admin)->post('admin/routes/' . $route, ['active' => '', 'variant_trigger' => 'none', 'steps' => [
            'a' => ['step_type' => 'notify', 'assignee_kind' => 'shared_mailbox', 'label' => 'X', 'email' => 'x@example.test'],
        ]]);
        $this->assertSame('team', $this->db->table('route_steps')->where('route_id', $route)->get()->getRowArray()['assignee_kind']);
    }

    public function testMemberAcceptsFromTheInvoicePageThenActs(): void
    {
        $company   = $this->makeCompany('ACME');
        $submitter = $this->shieldUser('sue@example.test', 'Sue Submitter');
        $ann       = $this->shieldUser('ann@example.test', 'Ann Team');
        $bob       = $this->shieldUser('bob@example.test', 'Bob Team');
        foreach ([$submitter, $ann, $bob] as $u) {
            $this->join((int) $u->id, $company, $u === $submitter);
        }
        $team  = $this->makeTeam('AP Team', [(int) $ann->id, (int) $bob->id]);
        $route = $this->makeRoute($company, [
            ['type' => 'submit', 'user' => (int) $submitter->id],
            ['type' => 'approve', 'team' => $team, 'label' => 'AP Team'],
        ]);

        $engine = new WorkflowEngine($this->db);
        $id     = $engine->createDraft((int) $submitter->id, [
            'company_id' => $company, 'invoice_type_id' => $route['type'], 'supplier' => 'Acme', 'invoice_number' => 'T-9',
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => '10', 'currency' => 'USD',
        ]);
        (new InvoiceFiles($this->db))->store($id, (int) $submitter->id, $this->pdfFile(), 'inv.pdf');
        $engine->submit($id, (int) $submitter->id);

        // Both members see the Accept button and no approve buttons yet.
        $page = $this->actingAs($ann)->get('invoices/' . $id);
        $page->assertSee('Accept invoice');
        $page->assertDontSee('Return for changes');
        $this->actingAs($bob)->get('approvals')->assertSee('needs accepting');

        $this->actingAs($ann)->post('invoices/' . $id . '/claim');
        $this->assertNotNull($this->db->table('invoice_step_claims')->where('invoice_id', $id)->where('released_at IS NULL')->get()->getRowArray());

        $this->actingAs($ann)->get('invoices/' . $id)->assertSee('Return for changes');
        $other = $this->actingAs($bob)->get('invoices/' . $id);
        $other->assertSee('Accepted by');
        $other->assertDontSee('Accept invoice');
        $other->assertDontSee('Return for changes');

        // Bob cannot sneak an approval through.
        $this->actingAs($bob)->post('invoices/' . $id . '/act', ['decision' => 'approve']);
        $this->assertSame('in_approval', $this->invoiceRow($id)['status']);

        $this->actingAs($ann)->post('invoices/' . $id . '/act', ['decision' => 'approve']);
        $this->assertSame('processed', $this->invoiceRow($id)['status']);
    }
}

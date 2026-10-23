<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\AdminAccess;
use App\Services\HelpAudience;
use App\Services\NotificationService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AppTestCase;

/**
 * The manual must only contain what the signed-in user can actually use. Assertions look at the raw
 * HTML, because content a user may not use must never be sent to the browser (not merely hidden).
 *
 * @internal
 */
final class HelpScreenTest extends AppTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    private int $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $filters                    = new \Config\Filters();
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);

        $this->acme = $this->makeCompany('ACME');
    }

    private function shieldUser(string $email, string $name, bool $admin = false): User
    {
        $users = model(UserModel::class);
        $user  = new User(['email' => $email, 'password' => 'a-long-test-password-1', 'display_name' => $name, 'is_admin' => $admin ? 1 : 0, 'active' => 1]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    private function page(User $user): string
    {
        $result = $this->actingAs($user)->get('help');
        $result->assertOK();

        return (string) $result->getBody();
    }

    /** Things that only appear for one capability, so their presence or absence is unambiguous. */
    private const MARK = [
        'submitter' => 'id="submitting"',
        'approver'  => 'Team steps',
        'group'     => 'id="group-level"',
        'admin'     => 'id="admin-setup"',
    ];

    private const EVENT = [
        'approver' => 'waiting for my approval',
        'group'    => 'stuck at a step for too long',
        'admin'    => 'could not be saved to SharePoint',
    ];

    /**
     * @param list<string> $has
     */
    private function assertOnly(string $body, array $has): void
    {
        foreach (self::MARK as $cap => $needle) {
            in_array($cap, $has, true)
                ? $this->assertStringContainsString($needle, $body, "{$cap} content missing")
                : $this->assertStringNotContainsString($needle, $body, "{$cap} content leaked");
        }
        foreach (self::EVENT as $cap => $needle) {
            in_array($cap, $has, true)
                ? $this->assertStringContainsString($needle, $body, "{$cap} alert type missing")
                : $this->assertStringNotContainsString($needle, $body, "{$cap} alert type leaked");
        }
    }

    public function testSignedOutUserIsSentToLogin(): void
    {
        $this->get('help')->assertRedirectTo(site_url('login'));
    }

    public function testPlainMemberSeesOnlyTheBasics(): void
    {
        $user = $this->shieldUser('member@example.test', 'Member');
        $this->join((int) $user->id, $this->acme);
        $body = $this->page($user);

        $this->assertStringContainsString('Getting started', $body);
        $this->assertStringContainsString('Dashboard and reports', $body);
        $this->assertStringContainsString('Notifications, reminders and escalations', $body);
        $this->assertOnly($body, []);
        // Nothing about approving, cover or stepping in.
        foreach (['Finding your work', 'arrange cover while you are away', 'Reminders.', 'Escalations.', 'id="approving"', 'group-level override', 'Team steps'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "Leaked: {$leak}");
        }
    }

    public function testSubmitterOnlySeesSubmittingButNotApproving(): void
    {
        $user = $this->shieldUser('sue@example.test', 'Sue');
        $this->join((int) $user->id, $this->acme, true);
        $body = $this->page($user);

        $this->assertOnly($body, ['submitter']);
        $this->assertStringNotContainsString('id="approving"', $body);
        $this->assertStringContainsString('My invoice was returned for changes', $body);
        $this->assertStringNotContainsString('Reminders.', $body);
    }

    public function testApproverOnlySeesApprovingButNotSubmitting(): void
    {
        $user = $this->shieldUser('alan@example.test', 'Alan');
        $this->join((int) $user->id, $this->acme);
        $this->makeRoute($this->acme, [['type' => 'approve', 'user' => (int) $user->id, 'role' => 'Accountant']]);
        $body = $this->page($user);

        $this->assertOnly($body, ['approver']);
        $this->assertStringContainsString('id="approving"', $body);
        $this->assertStringContainsString('Finding your work', $body);
        $this->assertStringContainsString('Reminders.', $body);
        $this->assertStringNotContainsString('Escalations.', $body);
        $this->assertStringNotContainsString('Can I act on a step that is not mine?', $body);
        $this->assertStringNotContainsString('My invoice was returned for changes', $body);
    }

    public function testGroupLevelUserSeesGroupContentButNothingAdminOrPersonalQueue(): void
    {
        $admin = $this->makeUser('Admin', true);
        $gary  = $this->shieldUser('gary@example.test', 'Gary');
        (new AdminAccess($this->db))->grantGroupLevel($admin, (int) $gary->id);
        $body = $this->page($gary);

        $this->assertOnly($body, ['group']);
        $this->assertStringContainsString('id="approving"', $body);
        $this->assertStringContainsString('Can I act on a step that is not mine?', $body);
        $this->assertStringContainsString('Escalations.', $body);
        // No personal queue, so nothing about My Tasks, team steps or cover.
        $this->assertStringNotContainsString('Finding your work', $body);
        $this->assertStringNotContainsString('Reminders.', $body);
    }

    public function testAdminWhoIsNotGroupLevelDoesNotSeeGroupLevelContent(): void
    {
        $body = $this->page($this->shieldUser('boss@example.test', 'The Admin', true));

        $this->assertOnly($body, ['admin']);
        $this->assertStringContainsString('Routes, time limits and escalation', $body);
        $this->assertStringContainsString('notifications:escalate', $body);
        $this->assertStringContainsString('Troubleshooting', $body);
        $this->assertStringNotContainsString('id="approving"', $body);
        $this->assertStringNotContainsString('Can I act on a step that is not mine?', $body);
        $this->assertStringNotContainsString('Escalations.', $body);
    }

    public function testUserWithEveryPermissionSeesEverything(): void
    {
        $boss = $this->shieldUser('all@example.test', 'Everything', true);
        $id   = (int) $boss->id;
        $this->join($id, $this->acme, true);
        $this->makeRoute($this->acme, [['type' => 'approve', 'user' => $id, 'role' => 'Controller']]);
        (new AdminAccess($this->db))->grantGroupLevel($id, $id);
        $body = $this->page($boss);

        $this->assertOnly($body, ['submitter', 'approver', 'group', 'admin']);
        $this->assertStringContainsString('Escalations.', $body);
        $this->assertStringContainsString('Reminders.', $body);
    }

    public function testAlertTypesListedMatchWhatCanReachTheUser(): void
    {
        $user = $this->shieldUser('sue2@example.test', 'Sue Two');
        $this->join((int) $user->id, $this->acme, true);
        $body = $this->page($user);

        $caps = ['submitter' => true, 'approver' => false, 'group' => false, 'admin' => false];
        foreach (NotificationService::EVENTS as $event => $label) {
            $expected = HelpAudience::allows(HelpAudience::EVENT_NEEDS[$event], $caps);
            $expected
                ? $this->assertStringContainsString(esc($label), $body, "Missing: {$label}")
                : $this->assertStringNotContainsString(esc($label), $body, "Should not list: {$label}");
        }
    }

    public function testNavigationLinksToTheManual(): void
    {
        $body = (string) $this->actingAs($this->shieldUser('member3@example.test', 'Member Three'))->get('/')->getBody();

        $this->assertStringContainsString(site_url('help'), $body);
        $this->assertStringContainsString('Help &amp; Manual', $body);
    }
}

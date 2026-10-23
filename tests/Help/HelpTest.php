<?php

declare(strict_types=1);

namespace Tests\Help;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Help;
use Config\Services;
use Tests\Support\Mfa\MfaTestCase;

/**
 * The manual and FAQ show each person only what their permissions allow, and a switched-off module takes its help with it.
 */
final class HelpTest extends MfaTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('session');
        Services::resetSingle('modules');
        Services::resetSingle('help');
        session();
        setting()->set('ModuleState.disabled', []);
    }

    private function staff(): int
    {
        return $this->makeUser('staff@example.test', [$this->roleId('staff')]);
    }

    private function invoiceUser(): int
    {
        return $this->makeUser('inv@example.test', [$this->roleId('invoice_user')]);
    }

    /** @return list<string> */
    private function slugs(int $uid): array
    {
        return array_keys(service('help')->topics($uid));
    }

    public function testStaffSeeOnlyTheTopicsTheirPermissionsAllow(): void
    {
        $slugs = $this->slugs($this->staff());

        $this->assertContains('getting-started', $slugs);
        $this->assertContains('my-account', $slugs);
        $this->assertContains('directory', $slugs);
        $this->assertNotContains('invoices', $slugs);
        $this->assertNotContains('admin-users', $slugs);
        $this->assertNotContains('admin-security', $slugs);
    }

    public function testInvoiceUsersGetInvoiceHelpButNotSetupOrAdmin(): void
    {
        $slugs = $this->slugs($this->invoiceUser());

        $this->assertContains('invoices', $slugs);
        $this->assertContains('invoice-reports', $slugs);
        $this->assertNotContains('invoice-setup', $slugs);
        $this->assertNotContains('suppliers', $slugs);
        $this->assertNotContains('admin-access', $slugs);
    }

    public function testSectionsAndFaqsAreFilteredInsideATopic(): void
    {
        // A custom role that can maintain routes but not teams (teams need invoices.manage).
        $db = db_connect();
        $db->table('roles')->insert(['key' => 'routes_only', 'name' => 'Routes only', 'description' => '', 'is_system' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $roleId = (int) $db->insertID();
        $perm   = (int) $db->table('permissions')->where('key', 'invoices.workflow')->get()->getRow('id');
        $db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $perm]);

        $workflow = $this->makeUser('wf@example.test', [$roleId]);
        $topic    = service('help')->topic($workflow, 'invoice-setup');

        $headings = array_column($topic['sections'], 'heading');
        $this->assertContains('Approval routes', $headings);
        $this->assertNotContains('Teams', $headings, 'Teams need invoices.manage.');

        $questions = array_column($topic['faqs'], 'q');
        $this->assertNotContains('Someone has left. Who deals with their invoices?', $questions);
    }

    public function testInvoicesManagerSeesTeamsHelpToo(): void
    {
        $manager = $this->makeUser('mgr@example.test', [$this->roleId('invoice_workflow')]);
        $topic   = service('help')->topic($manager, 'invoice-setup');

        $this->assertContains('Teams', array_column($topic['sections'], 'heading'));
    }

    public function testSuperAdminSeesEveryTopic(): void
    {
        $super = $this->makeSuperUser('super@example.test');

        $this->assertSame(array_keys(config(Help::class)->topics), array_values(array_intersect(array_keys(config(Help::class)->topics), $this->slugs($super))));
        $this->assertCount(count(config(Help::class)->topics), $this->slugs($super));
    }

    public function testASwitchedOffModuleRemovesItsHelp(): void
    {
        $super = $this->makeSuperUser('super@example.test');
        service('modules')->setEnabled('invoices', false);
        Services::resetSingle('modules');

        $slugs = $this->slugs($super);
        $this->assertNotContains('invoices', $slugs);
        $this->assertNotContains('invoice-reports', $slugs);
        $this->assertContains('admin-users', $slugs);
    }

    public function testSearchAndFaqGroupsOnlyReturnPermittedContent(): void
    {
        $staff = $this->staff();

        $this->assertSame([], service('help')->search($staff, 'delegation'), 'Invoice content is hidden from staff.');
        $this->assertNotEmpty(service('help')->search($staff, 'password'));
        $this->assertSame([], service('help')->search($staff, '   '));

        $groups = array_column(service('help')->faqGroups($staff), 'slug');
        $this->assertNotContains('invoices', $groups);
        $this->assertContains('my-account', $groups);
    }

    /**
     * A real request through the filter pipeline as the given user.
     */
    private function as(int $userId, string $path): \CodeIgniter\Test\TestResponse
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();

        // Accounts made by UserService must change their password at first sign-in; these tests are about the screens.
        db_connect()->table('auth_identities')->where('user_id', $userId)->update(['force_reset' => 0]);

        return $this->withSession(['user' => ['id' => $userId]])->get($path);
    }

    public function testTopicRouteIsA404WhenNotPermitted(): void
    {
        $uid = $this->staff();

        foreach (['help/invoices', 'help/no-such-topic'] as $path) {
            try {
                $this->as($uid, $path);
                $this->fail("GET /{$path} should be not found.");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testScreensRenderForAPermittedUser(): void
    {
        $uid = $this->invoiceUser();

        foreach (['help' => 'Invoices', 'help/invoices' => 'How an invoice moves', 'help/faq' => 'My invoice was returned', 'help?q=delegat' => 'Delegating while you are away'] as $path => $text) {
            $result = $this->as($uid, $path);
            $this->assertTrue($result->isOK(), "GET /{$path} returned " . $result->response()->getStatusCode());
            $result->assertSee($text);
        }
    }

    public function testStaffDoNotSeeInvoiceContentOnTheScreens(): void
    {
        $uid = $this->staff();

        $this->as($uid, 'help')->assertDontSee('Submit, approve and track invoices');
        $this->as($uid, 'help/faq')->assertDontSee('My invoice was returned');
        $this->as($uid, 'help?q=delegat')->assertDontSee('Delegating while you are away');
    }
}

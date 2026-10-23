<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\Entra\EntraIdentity;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FakeEntraClient;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class MicrosoftSignInTest extends FeatureTestCase
{
    private const OID_PAT = 'aaaaaaaa-0000-0000-0000-000000000001';
    private const TENANT  = '11111111-2222-3333-4444-555555555555';

    private FakeEntraClient $entra;

    /** @var array<string, string> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entra = new FakeEntraClient();
        Services::injectMock('entra', $this->entra);

        foreach (['entra.tenantId' => self::TENANT, 'entra.clientId' => 'client-id-123', 'entra.clientSecret' => 'client-secret'] as $k => $v) {
            $this->savedEnv[$k] = (string) ($_SERVER[$k] ?? '');
            $_SERVER[$k]        = $_ENV[$k] = $v;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            $_SERVER[$k] = $_ENV[$k] = $v;
        }
        setting()->forget('AppSettings.entraEnabled');
        Services::resetSingle('entra');
        parent::tearDown();
    }

    // -------------------------------------------------------------- helpers

    private function enable(bool $on = true): void
    {
        setting()->set('AppSettings.entraEnabled', $on);
    }

    private function unconfigure(): void
    {
        $_SERVER['entra.clientSecret'] = $_ENV['entra.clientSecret'] = '';
    }

    private function identity(string $email = 'pat@maloney.test', string $oid = self::OID_PAT): EntraIdentity
    {
        return new EntraIdentity($oid, self::TENANT, $email, 'Pat Example');
    }

    /** The values the start step stores in the session, as the callback expects to find them. */
    private function flow(array $override = []): array
    {
        return array_merge(['state' => 'state-abc', 'nonce' => 'nonce-xyz', 'pkce' => 'pkce-verifier', 'at' => time()], $override);
    }

    /**
     * Microsoft redirecting the browser back. By default the session holds a valid, fresh flow, as it
     * would after the start step. Pass flow: null to simulate a browser that never started one.
     * (The feature-test harness does not carry the session from one request to the next.)
     */
    private function returnFromMicrosoft(array $query = ['state' => 'state-abc', 'code' => 'the-code'], ?array $flow = []): TestResponse
    {
        // The harness blanks $_SESSION at the start of a request and then applies withSession(). If the
        // session has not been started yet, PHP starts it later and discards those values. Starting it
        // first (session() does that) makes withSession() reliable.
        session();

        return $this->withSession($flow === null ? [] : ['entra_flow' => $flow === [] ? $this->flow() : $flow])
            ->get('auth/microsoft/callback', $query);
    }

    private function signOut(): void
    {
        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
    }

    private function audit(string $event): int
    {
        return $this->db->table('audit_log')->where('event', $event)->countAllResults();
    }

    private function oidOf(int $userId): ?string
    {
        return $this->db->table('users')->where('id', $userId)->get()->getRow()->entra_oid;
    }

    private function flash(): string
    {
        return (string) session()->getFlashdata('error');
    }

    // -------------------------------------------------------------- off by default

    public function testItIsOffByDefaultAndTheButtonIsHidden(): void
    {
        $login = $this->get('login');
        $login->assertStatus(200);
        $this->assertStringNotContainsString('Sign in with Microsoft', $login->getBody());
        $this->assertStringContainsString('name="password"', $login->getBody()); // the local form is there
    }

    public function testWhenOffBothEndpointsRefuseAndDoNothing(): void
    {
        $user = $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->entra->identity = $this->identity();

        $this->get('auth/microsoft')->assertRedirectTo(site_url('login'));
        $this->returnFromMicrosoft()->assertRedirectTo(site_url('login'));      // even with a perfectly valid flow

        $this->assertSame([], $this->entra->completed);                         // never even talked to Microsoft
        $this->assertFalse(auth()->loggedIn());
        $this->assertNull($this->oidOf((int) $user->id));
    }

    public function testSwitchedOnButNotConfiguredStillRefusesAndHidesTheButton(): void
    {
        $this->enable();
        $this->unconfigure();

        $this->assertStringNotContainsString('Sign in with Microsoft', $this->get('login')->getBody());
        $this->get('auth/microsoft')->assertRedirectTo(site_url('login'));
        $this->returnFromMicrosoft()->assertRedirectTo(site_url('login'));
        $this->assertSame([], $this->entra->completed);
    }

    // -------------------------------------------------------------- on

    public function testWhenOnTheButtonAppearsAlongsideTheLocalForm(): void
    {
        $this->enable();

        $body = $this->get('login')->getBody();
        $this->assertStringContainsString('Sign in with Microsoft', $body);
        $this->assertStringContainsString(site_url('auth/microsoft'), $body);
        $this->assertStringContainsString('name="password"', $body);
    }

    public function testStartSendsTheUserToMicrosoftAndRemembersTheFlow(): void
    {
        $this->enable();

        $res = $this->get('auth/microsoft');

        $res->assertRedirectTo('https://login.microsoftonline.test/authorize?state=state-abc');
        $flow = session()->get('entra_flow');
        $this->assertSame('state-abc', $flow['state']);
        $this->assertSame('nonce-xyz', $flow['nonce']);
        $this->assertSame('pkce-verifier', $flow['pkce']);
        $this->assertEqualsWithDelta(time(), $flow['at'], 5);
    }

    public function testSuccessfulSignInByEmailLinksTheAccountAndLogsIn(): void
    {
        $this->enable();
        $user = $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->entra->identity = $this->identity('Pat@Maloney.test');

        $this->returnFromMicrosoft()->assertRedirectTo(site_url('/'));

        $this->assertTrue(auth()->loggedIn());
        $this->assertSame((int) $user->id, (int) auth()->id());
        $this->assertSame(self::OID_PAT, $this->oidOf((int) $user->id));
        $this->assertSame([['code' => 'the-code', 'nonce' => 'nonce-xyz', 'pkce' => 'pkce-verifier']], $this->entra->completed);
        $this->assertSame(1, $this->audit('auth.microsoft_login'));
    }

    public function testLaterSignInsMatchTheStableObjectIdEvenIfTheEmailChanged(): void
    {
        $this->enable();
        $user = $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->db->table('users')->where('id', $user->id)->update(['entra_oid' => self::OID_PAT]);
        $this->entra->identity = $this->identity('pat.renamed@maloney.test'); // renamed in Microsoft

        $this->returnFromMicrosoft();

        $this->assertTrue(auth()->loggedIn());
        $this->assertSame((int) $user->id, (int) auth()->id());
    }

    public function testASignedInUserIsNotShownTheFlowAgain(): void
    {
        $this->enable();
        $user = $this->shieldUser('pat@maloney.test', 'Pat Example');

        $this->actingAs($user)->get('auth/microsoft')->assertRedirectTo(site_url('/'));
    }

    // -------------------------------------------------------------- denials come from the identity checks

    public function testUnknownPeopleAreDeniedAndNoAccountIsCreated(): void
    {
        $this->enable();
        $this->entra->identity = $this->identity('stranger@maloney.test', 'bbbbbbbb-0000-0000-0000-000000000002');
        $before                = $this->db->table('users')->countAllResults();

        $this->returnFromMicrosoft()->assertRedirectTo(site_url('login'));

        $this->assertSame(1, count($this->entra->completed), 'The flow was valid, so Microsoft was asked.');
        $this->assertFalse(auth()->loggedIn());
        $this->assertSame($before, $this->db->table('users')->countAllResults());
        $this->assertStringContainsString('has not been set up', $this->flash());
        $this->assertSame(1, $this->audit('auth.microsoft_denied'));
    }

    public function testInactiveUsersAreDenied(): void
    {
        $this->enable();
        $user = $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->db->table('users')->where('id', $user->id)->update(['active' => 0]);
        $this->entra->identity = $this->identity();

        $this->returnFromMicrosoft();

        $this->assertFalse(auth()->loggedIn());
        $this->assertStringContainsString('not active', $this->flash());
        $this->assertNull($this->oidOf((int) $user->id));
    }

    public function testEmaillessPlaceholdersAreDeniedEvenIfActive(): void
    {
        $this->enable();
        $user = $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->db->table('users')->where('id', $user->id)->update(['email_missing' => 1]);
        $this->entra->identity = $this->identity();

        $this->returnFromMicrosoft();

        $this->assertFalse(auth()->loggedIn());
        $this->assertStringContainsString('not active', $this->flash());
    }

    public function testAnEmailMatchIsRefusedWhenTheAccountIsLinkedToADifferentMicrosoftIdentity(): void
    {
        $this->enable();
        $user = $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->db->table('users')->where('id', $user->id)->update(['entra_oid' => 'cccccccc-0000-0000-0000-000000000003']);
        $this->entra->identity = $this->identity('pat@maloney.test', self::OID_PAT); // someone else claiming Pat's email

        $this->returnFromMicrosoft();

        $this->assertFalse(auth()->loggedIn());
        $this->assertSame('cccccccc-0000-0000-0000-000000000003', $this->oidOf((int) $user->id)); // not relinked
        $this->assertStringContainsString('different Microsoft account', $this->flash());
    }

    public function testAMicrosoftAccountWithNoEmailCannotMatchByEmail(): void
    {
        $this->enable();
        $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->entra->identity = $this->identity('');

        $this->returnFromMicrosoft();

        $this->assertFalse(auth()->loggedIn());
        $this->assertStringContainsString('has not been set up', $this->flash());
    }

    // -------------------------------------------------------------- the callback itself is tight

    /** Sanity check for the tests below: with a valid flow and a known user, the same call does sign in. */
    public function testTheTightnessTestsAreMeaningful(): void
    {
        $this->enable();
        $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->entra->identity = $this->identity();

        $this->returnFromMicrosoft(['state' => 'state-abc', 'code' => 'x']);

        $this->assertTrue(auth()->loggedIn());
    }

    public function testWrongOrMissingStateOrCodeIsRefusedBeforeMicrosoftIsAsked(): void
    {
        $this->enable();
        $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->entra->identity = $this->identity();

        foreach ([['state' => 'forged', 'code' => 'x'], ['code' => 'x'], ['state' => 'state-abc'], ['state' => ['a'], 'code' => 'x'], ['state' => 'state-abc', 'code' => ['x']]] as $query) {
            $this->returnFromMicrosoft($query)->assertRedirectTo(site_url('login'));
            $this->assertFalse(auth()->loggedIn());
            $this->assertStringContainsString('expired or was not valid', $this->flash());
        }
        $this->assertSame([], $this->entra->completed);
    }

    public function testCallbackWithoutHavingStartedIsRefused(): void
    {
        $this->enable();
        $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->entra->identity = $this->identity();

        $this->returnFromMicrosoft(['state' => 'state-abc', 'code' => 'x'], null)->assertRedirectTo(site_url('login'));

        $this->assertFalse(auth()->loggedIn());
        $this->assertSame([], $this->entra->completed);
    }

    public function testAnExpiredFlowIsRefused(): void
    {
        $this->enable();
        $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->entra->identity = $this->identity();

        $this->returnFromMicrosoft(['state' => 'state-abc', 'code' => 'x'], $this->flow(['at' => time() - 3600]));

        $this->assertFalse(auth()->loggedIn());
        $this->assertSame([], $this->entra->completed);
    }

    public function testAFlowIsUsedUpWhateverTheOutcome(): void
    {
        $this->enable();
        $this->shieldUser('pat@maloney.test', 'Pat Example');

        // A failed attempt (Microsoft refuses) still clears the stored flow, so it cannot be retried.
        $this->entra->failExchange = true;
        $this->returnFromMicrosoft();
        $this->assertNull(session()->get('entra_flow'));
        $this->assertFalse(auth()->loggedIn());

        // So does a successful one.
        $this->signOut();
        $this->entra->failExchange = false;
        $this->entra->identity     = $this->identity();
        $this->returnFromMicrosoft();
        $this->assertTrue(auth()->loggedIn());
        $this->assertNull(session()->get('entra_flow'));

        // After signing out there is nothing left to replay.
        $this->signOut();
        $this->returnFromMicrosoft(['state' => 'state-abc', 'code' => 'x'], null);
        $this->assertFalse(auth()->loggedIn());
    }

    public function testMicrosoftReportingAnErrorOrBeingDownDoesNotSignAnyoneIn(): void
    {
        $this->enable();
        $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->entra->identity = $this->identity();

        $this->returnFromMicrosoft(['error' => 'access_denied', 'error_description' => 'AADSTS65004: user declined'])->assertRedirectTo(site_url('login'));
        $this->assertFalse(auth()->loggedIn());
        $this->assertSame([], $this->entra->completed);
        $this->assertStringContainsString('cancelled or refused', $this->flash());

        $this->entra->failExchange = true;
        $this->returnFromMicrosoft()->assertRedirectTo(site_url('login'));
        $this->assertFalse(auth()->loggedIn());
        $this->assertStringContainsString('did not work', $this->flash());
    }

    public function testTheUserNeverSeesMicrosoftErrorDetails(): void
    {
        $this->enable();

        $this->returnFromMicrosoft(['error' => 'access_denied', 'error_description' => 'SECRET-INTERNAL-DETAIL']);

        $this->assertStringNotContainsString('SECRET-INTERNAL-DETAIL', $this->flash());
    }

    // -------------------------------------------------------------- local sign-in is untouched

    public function testLocalSignInStillWorksWithMicrosoftOnAndOff(): void
    {
        $user = $this->shieldUser('local@maloney.test', 'Local Person');

        foreach ([false, true] as $on) {
            $this->signOut();
            $this->enable($on);
            $this->post('login', ['email' => 'local@maloney.test', 'password' => 'a-long-test-password-1']);

            $this->assertTrue(auth()->loggedIn(), 'Local login failed with Microsoft sign-in ' . ($on ? 'on' : 'off'));
            $this->assertSame((int) $user->id, (int) auth()->id());
        }
    }

    // -------------------------------------------------------------- admin switch and unlink

    public function testOnlyAdminsCanSwitchItAndItNeedsConfiguration(): void
    {
        $admin = $this->shieldUser('boss@maloney.test', 'Boss', true);
        $plain = $this->shieldUser('plain@maloney.test', 'Plain');

        $this->actingAs($plain)->post('admin/settings/microsoft', ['enabled' => '1'])->assertRedirectTo(site_url('/'));
        $this->assertFalse((bool) setting('AppSettings.entraEnabled'));

        $this->unconfigure();
        $this->actingAs($admin)->post('admin/settings/microsoft', ['enabled' => '1']);
        $this->assertFalse((bool) setting('AppSettings.entraEnabled'));                 // refused: not configured
        $this->assertStringContainsString('clientSecret', (string) json_encode(session()->getFlashdata('errors')));

        $_SERVER['entra.clientSecret'] = $_ENV['entra.clientSecret'] = 'client-secret';
        $this->actingAs($admin)->post('admin/settings/microsoft', ['enabled' => '1']);
        $this->assertTrue((bool) setting('AppSettings.entraEnabled'));
        $this->assertSame(1, $this->audit('settings.microsoft_enabled'));

        $this->actingAs($admin)->post('admin/settings/microsoft', []);                 // unticked = off
        $this->assertFalse((bool) setting('AppSettings.entraEnabled'));
        $this->assertSame(1, $this->audit('settings.microsoft_disabled'));
    }

    public function testSwitchingOffWorksEvenIfConfigurationHasSinceBeenRemoved(): void
    {
        $admin = $this->shieldUser('boss@maloney.test', 'Boss', true);
        $this->enable();
        $this->unconfigure();

        $this->actingAs($admin)->post('admin/settings/microsoft', []);

        $this->assertFalse((bool) setting('AppSettings.entraEnabled'));
    }

    public function testSettingsPageExplainsWhatIsMissingAndShowsTheRedirectAddress(): void
    {
        $admin = $this->shieldUser('boss@maloney.test', 'Boss', true);
        $this->unconfigure();

        $body = $this->actingAs($admin)->get('admin/settings')->getBody();
        $this->assertStringContainsString('Not configured yet', $body);
        $this->assertStringContainsString('entra.clientSecret', $body);
        $this->assertStringContainsString(site_url('auth/microsoft/callback'), $body);
        $this->assertStringNotContainsString('client-secret', $body); // the secret is never displayed

        $_SERVER['entra.clientSecret'] = $_ENV['entra.clientSecret'] = 'client-secret';
        $ok = $this->actingAs($admin)->get('admin/settings')->getBody();
        $this->assertStringContainsString(self::TENANT, $ok);
        $this->assertStringNotContainsString('client-secret', $ok);
    }

    public function testAdminCanUnlinkAMicrosoftAccount(): void
    {
        $admin = $this->shieldUser('boss@maloney.test', 'Boss', true);
        $pat   = $this->shieldUser('pat@maloney.test', 'Pat Example');
        $this->db->table('users')->where('id', $pat->id)->update(['entra_oid' => self::OID_PAT]);

        $this->assertStringContainsString('Linked to a Microsoft account', $this->actingAs($admin)->get('admin/users/' . $pat->id . '/edit')->getBody());

        $this->actingAs($admin)->post('admin/users/' . $pat->id . '/unlink-microsoft');
        $this->assertNull($this->oidOf((int) $pat->id));
        $this->assertSame(1, $this->audit('user.microsoft_unlinked'));

        $this->db->table('users')->where('id', $pat->id)->update(['entra_oid' => self::OID_PAT]);
        $this->actingAs($pat)->post('admin/users/' . $pat->id . '/unlink-microsoft')->assertRedirectTo(site_url('/'));
        $this->assertSame(self::OID_PAT, $this->oidOf((int) $pat->id)); // a non-admin cannot do it
    }

    public function testBulkProvisionedUsersCanSignInWithMicrosoftWithoutEverSettingAPassword(): void
    {
        $this->enable();
        $adminId = (int) $this->shieldUser('boss@maloney.test', 'Boss', true)->id;
        (new \App\Services\UserBulkImporter($this->db))->commit(
            [['_line' => '2', 'email' => 'new.hire@maloney.test', 'display_name' => 'New Hire', 'company_codes' => '', 'job_title' => '', 'can_submit' => '', 'can_approve' => '', 'group_level' => '', 'is_admin' => '', 'active' => '']],
            $adminId,
        );
        $this->signOut();
        $this->entra->identity = $this->identity('new.hire@maloney.test', 'dddddddd-0000-0000-0000-000000000004');

        $this->returnFromMicrosoft();

        $this->assertTrue(auth()->loggedIn());
        $this->assertSame('New Hire', model(UserModel::class)->findById(auth()->id())->display_name);
    }
}

<?php

namespace Tests\Mfa;

use App\Auth\MfaAction;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Mfa\MfaTestCase;

/**
 * Runs real requests through the full pipeline (filters included) with Shield
 * session state set to "password accepted, waiting for MFA".
 */
final class MfaControllerTest extends MfaTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('auth');
        Services::resetSingle('authentication');

        // In the testing environment Session::start() blanks $_SESSION, so the session must already
        // exist when withSession() assigns it; otherwise the first use inside the request wipes it.
        Services::resetSingle('session');
        session();
    }

    /**
     * One fresh request: Shield caches the user state inside its authenticator, so each simulated
     * request must start from a clean one, exactly as a real PHP request would.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed>|null $params
     */
    private function fresh(string $method, string $path, array $session = [], ?array $params = null)
    {
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
        Services::resetSingle('session');
        session();

        $call = $this->withSession($session);

        return $method === 'post' ? $call->post($path, $params) : $call->get($path);
    }
    /**
     * The session a user has after Shield accepted the password but before the second factor.
     *
     * @return array<string, mixed>
     */
    private function pendingSession(int $userId): array
    {
        return ['user' => ['id' => $userId, 'auth_action' => MfaAction::class, 'auth_action_message' => 'Multi-factor authentication required']];
    }

    /**
     * New accounts must change their temporary password first; clear that so the user can use the app.
     */
    private function settled(int $userId): int
    {
        auth()->getProvider()->findById($userId)->undoForcePasswordReset();

        return $userId;
    }

    private function secretCiphertext(int $userId): ?string
    {
        return db_connect()->table('user_mfa_methods')->where(['user_id' => $userId, 'type' => 'totp'])->get()->getRow('secret_enc');
    }

    public function testPendingUserWithAFactorCannotEnrolAnotherOne(): void
    {
        // The regression: a password thief, holding a pending session, must not be able to
        // swap in their own authenticator and walk through the challenge.
        $id = $this->makeUser();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true, 'email' => true]);
        $before = $this->secretCiphertext($id);

        foreach (['mfa/enrol', 'mfa/enrol/totp', 'mfa/enrol/email'] as $path) {
            $result = $this->fresh('get', $path, $this->pendingSession($id));
            $result->assertRedirectTo(site_url('mfa/challenge'));
        }

        $result = $this->fresh('post', 'mfa/enrol/totp', $this->pendingSession($id), ['csrf_test_name' => csrf_hash(), 'code' => '123456']);
        $this->assertNotSame(200, $result->response()->getStatusCode());

        $this->assertSame($before, $this->secretCiphertext($id), 'The enrolled authenticator must be untouched.');
        $this->assertSame(['email' => false, 'totp' => true], [
            'email' => service('mfa')->hasMethod($id, 'email'),
            'totp'  => service('mfa')->hasMethod($id, 'totp'),
        ]);
    }

    public function testRequiredUserWithNoFactorMayEnrolDuringSignIn(): void
    {
        $id = $this->makeUser('boss@example.com', [$this->roleId('admin')]);
        $this->policy(['enabled' => true, 'totp' => true, 'requiredRoles' => [$this->roleId('admin')]]);

        $choose = $this->fresh('get', 'mfa/enrol', $this->pendingSession($id));
        $choose->assertOK();
        $choose->assertSee('Authenticator app');

        $this->fresh('get', 'mfa/enrol/totp', $this->pendingSession($id))->assertOK();
    }

    public function testChallengeSendsRequiredUnenrolledUsersToEnrolment(): void
    {
        $id = $this->makeUser('boss@example.com', [$this->roleId('admin')]);
        $this->policy(['enabled' => true, 'totp' => true, 'requiredRoles' => [$this->roleId('admin')]]);

        $this->fresh('get', 'mfa/challenge', $this->pendingSession($id))->assertRedirectTo(site_url('mfa/enrol'));
    }

    public function testChallengeShowsTheFormToAnEnrolledPendingUser(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true]);

        $page = $this->fresh('get', 'mfa/challenge', $this->pendingSession($id));
        $page->assertOK();
        $page->assertSee('6-digit code');
    }

    public function testAnonymousVisitorsAreSentToSignIn(): void
    {
        foreach (['mfa/challenge', 'mfa/enrol', 'mfa/enrol/totp'] as $path) {
            $this->get($path)->assertRedirectTo(site_url('login'));
        }
    }

    public function testAPendingUserCannotReachTheApplication(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true]);

        foreach (['/', 'admin/users', 'directory', 'profile'] as $path) {
            $result = $this->fresh('get', $path, $this->pendingSession($id));
            $this->assertTrue($result->isRedirect(), "{$path} must not be served to a half-signed-in user.");
            $this->assertStringNotContainsString('Dashboard', (string) $result->getBody());
        }
    }

    public function testAWrongCodeDoesNotCompleteTheSignIn(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true]);

        $result = $this->fresh('post', 'mfa/challenge', $this->pendingSession($id), ['csrf_test_name' => csrf_hash(), 'method' => 'totp', 'code' => '000000']);

        $this->assertTrue($result->isRedirect());
        $this->assertStringContainsString('mfa/challenge', $result->getRedirectUrl());
    }

    public function testSignedInUserNeedsToConfirmThePasswordBeforeEnrolling(): void
    {
        $id = $this->settled($this->makeUser());
        $this->policy(['enabled' => true, 'totp' => true]);
        $signedIn = ['user' => ['id' => $id]];

        $this->fresh('get', 'mfa/enrol/totp', $signedIn)->assertRedirectTo(site_url('profile/mfa'));

        $ok = $this->fresh('get', 'mfa/enrol/totp', $signedIn + ['mfa_reauth' => time()]);
        $ok->assertOK();
        $ok->assertSee('scanning this code');
        $this->fresh('get', 'mfa/enrol/totp', $signedIn + ['mfa_reauth' => time() - 3600])->assertRedirectTo(site_url('profile/mfa'));
    }

    public function testSettingsPageIsOnlyForPeopleWithTheMfaPermission(): void
    {
        $staff = $this->settled($this->makeUser('staff@example.com', [$this->roleId('staff')]));
        $admin = $this->settled($this->makeSuperUser('root@example.com'));

        $this->fresh('get', 'admin/mfa', ['user' => ['id' => $staff]])->assertStatus(403);
        $page = $this->fresh('get', 'admin/mfa', ['user' => ['id' => $admin]]);
        $page->assertOK();
        $page->assertSee('Multi-factor authentication');
    }
}

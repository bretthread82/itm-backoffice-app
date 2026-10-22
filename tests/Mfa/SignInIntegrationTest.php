<?php

namespace Tests\Mfa;

use App\Auth\MfaAction;
use App\Core\PasswordResetService;
use App\Core\UserService;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Config\Services;
use RuntimeException;
use Tests\Support\Mfa\MfaTestCase;

/**
 * Drives Shield's real session authenticator to prove a password login is
 * genuinely held back until a second factor completes it.
 */
final class SignInIntegrationTest extends MfaTestCase
{
    private const PASSWORD = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('auth');
        Services::resetSingle('authentication');
    }

    private function userWithPassword(string $email = 'jane@example.com', array $roles = []): int
    {
        $id   = $this->makeUser($email, $roles);
        $user = auth()->getProvider()->findById($id);
        $user->password = self::PASSWORD;
        auth()->getProvider()->save($user);
        $user->undoForcePasswordReset();

        return $id;
    }

    private function attempt(string $email = 'jane@example.com')
    {
        return auth('session')->getAuthenticator()->attempt(['email' => $email, 'password' => self::PASSWORD]);
    }

    public function testShieldRememberMeStaysOffBecauseItWouldBypassMfa(): void
    {
        $this->assertFalse(setting('Auth.sessionConfig')['allowRemembering'], 'A remember-me cookie minted before the MFA step completes could later skip it.');
    }

    public function testWithMfaOffAPasswordLoginCompletesNormally(): void
    {
        $this->userWithPassword();
        $this->policy(['enabled' => false]);

        $this->assertTrue($this->attempt()->isOK());
        $this->assertTrue(auth('session')->loggedIn());
    }

    public function testAnEnrolledUserIsHeldAtTheChallengeUntilTheFactorPasses(): void
    {
        $id = $this->userWithPassword();
        $secret = $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true]);

        $this->assertTrue($this->attempt()->isOK(), 'The password itself was right.');

        $authenticator = auth('session')->getAuthenticator();
        $this->assertFalse($authenticator->loggedIn(), 'But the user must NOT be logged in yet.');
        $this->assertTrue($authenticator->isPending());
        $this->assertInstanceOf(MfaAction::class, $authenticator->getAction());
        $this->assertSame($id, (int) $authenticator->getPendingUser()->id);

        // The controller verifies the factor, then hands Shield its marker back.
        $this->assertTrue(service('totp')->verify($id, $this->codeFor($secret)));
        $identity = model(UserIdentityModel::class)->getIdentityByType($authenticator->getPendingUser(), MfaAction::TYPE);
        $this->assertNotNull($identity);
        $this->assertTrue($authenticator->checkAction($identity, $identity->secret));

        $this->assertTrue($authenticator->loggedIn());
        $this->assertNull(model(UserIdentityModel::class)->getIdentityByType(auth()->user(), MfaAction::TYPE), 'The marker is consumed.');
    }

    public function testAWrongPasswordNeverReachesTheChallenge(): void
    {
        $id = $this->userWithPassword();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true]);

        $result = auth('session')->getAuthenticator()->attempt(['email' => 'jane@example.com', 'password' => 'nope']);

        $this->assertFalse($result->isOK());
        $this->assertFalse(auth('session')->getAuthenticator()->isPending());
    }

    public function testRequiredRoleWithoutAFactorIsHeldToEnrol(): void
    {
        $id = $this->userWithPassword('boss@example.com', [$this->roleId('admin')]);
        $this->policy(['enabled' => true, 'totp' => true, 'requiredRoles' => [$this->roleId('admin')]]);

        $this->assertTrue($this->attempt('boss@example.com')->isOK());

        $this->assertFalse(auth('session')->loggedIn());
        $this->assertTrue(auth('session')->getAuthenticator()->isPending());
        $this->assertTrue(service('mfaPolicy')->needsEnrolment($id));
    }

    public function testOptionalUnenrolledUserSignsStraightIn(): void
    {
        $this->userWithPassword();
        $this->policy(['enabled' => true, 'totp' => true]);

        $this->assertTrue($this->attempt()->isOK());
        $this->assertTrue(auth('session')->loggedIn());
    }

    public function testATrustedBrowserSkipsTheChallenge(): void
    {
        $id = $this->userWithPassword();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true, 'rememberDays' => 30]);
        $cookie = service('mfa')->issueTrustedDevice($id, 30);
        service('request')->setGlobal('cookie', [$cookie['name'] => $cookie['value']]);

        $this->assertTrue($this->attempt()->isOK());
        $this->assertTrue(auth('session')->loggedIn());
    }

    public function testMicrosoftEntraStyleLoginIsNotChallenged(): void
    {
        // EntraAuth signs users in with login(), not attempt(); Shield starts login actions only in attempt().
        $id = $this->makeUser('sso@example.com', [$this->roleId('admin')]);
        $this->policy(['enabled' => true, 'totp' => true, 'requiredRoles' => [$this->roleId('admin')]]);

        auth('session')->login(auth()->getProvider()->findById($id));

        $this->assertTrue(auth('session')->loggedIn(), 'Entra relies on its own MFA.');
    }

    // ---- Admin reset and privilege rules ---------------------------------

    public function testAdminResetClearsEverythingAndTellsTheUser(): void
    {
        $id = $this->makeUser('victim@example.com');
        $this->enrolTotp($id);
        service('mfa')->generateRecoveryCodes($id);
        service('mfa')->issueTrustedDevice($id, 30);

        (new UserService())->resetMfa($id);

        $this->assertSame([], service('mfa')->methods($id));
        $this->assertSame(0, service('mfa')->recoveryCodesRemaining($id));
        $this->assertSame(0, service('mfa')->deviceCount($id));
        $this->seeInDatabase('notifications', ['user_id' => $id, 'type' => 'account.mfa_changed']);
        $this->seeInDatabase('audit_log', ['action' => 'admin.mfa_reset', 'entity_id' => (string) $id]);
    }

    public function testOnlyASuperAdminMayResetASuperAdminsMfa(): void
    {
        $super = $this->makeSuperUser('root@example.com');
        $this->enrolTotp($super);

        // No signed-in actor, or a plain admin: refused.
        try {
            (new UserService())->resetMfa($super);
            $this->fail('A non-super actor must not reset a Super Admin.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Super Admin', $e->getMessage());
        }
        $this->assertNotSame([], service('mfa')->methods($super));

        $other = $this->makeSuperUser('root2@example.com');
        auth('session')->login(auth()->getProvider()->findById($other));
        (new UserService())->resetMfa($super);

        $this->assertSame([], service('mfa')->methods($super));
    }

    public function testPasswordResetsRevokeTrustedBrowsers(): void
    {
        $id = $this->makeUser();
        service('mfa')->issueTrustedDevice($id, 30);
        (new UserService())->resetPassword($id);
        $this->assertSame(0, service('mfa')->deviceCount($id), 'Admin password reset revokes trust.');

        service('mfa')->issueTrustedDevice($id, 30);
        $svc = new PasswordResetService();
        $svc->requestReset('jane@example.com');
        preg_match('#reset-password/([a-f0-9]{64})#', end($this->mailer->sent)['html'], $m);
        $svc->reset($svc->validate($m[1]), 'Another-Strong-Passw0rd-7');
        $this->assertSame(0, service('mfa')->deviceCount($id), 'Emailed-link reset revokes trust.');
    }

    public function testDeletingAUserWipesTheirMfaData(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        auth('session')->login(auth()->getProvider()->findById($this->makeSuperUser('admin2@example.com')));

        (new UserService())->delete($id);

        $this->assertSame(0, db_connect()->table('user_mfa_methods')->where('user_id', $id)->countAllResults());
    }
}

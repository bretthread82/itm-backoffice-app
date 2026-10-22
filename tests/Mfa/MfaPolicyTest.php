<?php

namespace Tests\Mfa;

use Tests\Support\Mfa\MfaTestCase;

final class MfaPolicyTest extends MfaTestCase
{
    private function appliesTo(int $userId): bool
    {
        return service('mfaPolicy')->appliesTo(auth()->getProvider()->findById($userId));
    }

    public function testEncryptionKeyIsAvailableForTheTests(): void
    {
        $this->assertTrue(service('mfa')->cryptoReady(), 'Run php spark key:generate; MFA needs an encryption key.');
    }

    public function testNobodyIsChallengedWhenMfaIsOff(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        $this->policy(['enabled' => false, 'totp' => true]);

        $this->assertFalse($this->appliesTo($id));
    }

    public function testOptionalUsersAreOnlyChallengedOnceTheyEnrol(): void
    {
        $id = $this->makeUser();
        $this->policy(['enabled' => true, 'totp' => true]);

        $this->assertFalse($this->appliesTo($id), 'Not enrolled and not required: no challenge.');
        $this->assertFalse(service('mfaPolicy')->needsEnrolment($id));

        $this->enrolTotp($id);
        $this->assertTrue($this->appliesTo($id));
    }

    public function testRequiredRoleMustEnrolAtSignIn(): void
    {
        $id = $this->makeUser('boss@example.com', [$this->roleId('admin')]);
        $this->policy(['enabled' => true, 'totp' => true, 'requiredRoles' => [$this->roleId('admin')]]);

        $this->assertTrue($this->appliesTo($id));
        $this->assertTrue(service('mfaPolicy')->needsEnrolment($id));

        $staff = $this->makeUser('staff@example.com', [$this->roleId('staff')]);
        $this->assertFalse($this->appliesTo($staff), 'Other roles are not forced.');
    }

    public function testAMethodTheAdminSwitchedOffNoLongerCounts(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => false, 'email' => true]);

        $this->assertSame([], service('mfa')->usableMethods($id));
        $this->assertFalse($this->appliesTo($id), 'Optional user whose only factor is switched off is not locked out.');

        $this->policy(['requiredRoles' => [$this->roleId('staff')]]);
        $id2 = $this->makeUser('other@example.com', [$this->roleId('staff')]);
        $this->assertTrue(service('mfaPolicy')->needsEnrolment($id2));
    }

    public function testNoAvailableMethodNeverLocksEveryoneOut(): void
    {
        $id = $this->makeUser('boss@example.com', [$this->roleId('admin')]);
        $this->policy(['enabled' => true, 'totp' => false, 'email' => false, 'duo' => false, 'requiredRoles' => [$this->roleId('admin')]]);

        $this->assertFalse($this->appliesTo($id));
    }

    public function testEmailNeedsMailAndDuoNeedsConfig(): void
    {
        $policy = service('mfaPolicy');
        $this->policy(['email' => true, 'duo' => true, 'totp' => true]);

        $this->assertTrue($policy->prerequisitesMet('email'));
        $this->mailer->configured = false;
        $this->assertFalse($policy->prerequisitesMet('email'));
        $this->assertNotNull($policy->unavailableReason('email'));

        $this->assertFalse($policy->prerequisitesMet('duo'), 'Duo placeholders in .env must read as unconfigured.');
        $this->assertSame(['totp'], $policy->enabledMethods());
    }

    public function testTrustedDeviceSkipsTheChallengeButNotEnrolment(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true, 'rememberDays' => 30]);

        $cookie = service('mfa')->issueTrustedDevice($id, 30);
        service('request')->setGlobal('cookie', [$cookie['name'] => $cookie['value']]);

        $this->assertFalse($this->appliesTo($id), 'Trusted browser skips the challenge.');

        $this->policy(['rememberDays' => 0]);
        $this->assertTrue($this->appliesTo($id), 'Feature off: the cookie is ignored.');

        // A required-role user with no factor is never waved through by a cookie.
        $req = $this->makeUser('req@example.com', [$this->roleId('admin')]);
        $this->policy(['rememberDays' => 30, 'requiredRoles' => [$this->roleId('admin')]]);
        $c2 = service('mfa')->issueTrustedDevice($req, 30);
        service('request')->setGlobal('cookie', [$c2['name'] => $c2['value']]);
        $this->assertTrue($this->appliesTo($req));
    }

    public function testSettingsRoundTripIncludingArrays(): void
    {
        $this->policy(['enabled' => true, 'totp' => true, 'email' => true, 'requiredRoles' => [3, 1, 3], 'rememberDays' => 14]);
        \Config\Services::resetSingle('settings');

        $policy = service('mfaPolicy');
        $this->assertTrue($policy->enabled());
        $this->assertSame([3, 1], $policy->requiredRoleIds());
        $this->assertSame(14, $policy->rememberDays());
    }

    // ---- Admin guard rails ------------------------------------------------

    private function proposal(array $over = []): array
    {
        return $over + ['enabled' => true, 'totp' => true, 'email' => false, 'duo' => false, 'requiredRoles' => [], 'rememberDays' => 0];
    }

    public function testCannotEnableWithoutAnyMethod(): void
    {
        $this->assertStringContainsString('at least one method', (string) service('mfaPolicy')->validateChange($this->proposal(['totp' => false]), 1));
        $this->assertNull(service('mfaPolicy')->validateChange($this->proposal(['enabled' => false, 'totp' => false]), 1), 'Switching MFA off is always allowed.');
    }

    public function testCannotSwitchOnAMethodThatCannotWork(): void
    {
        $this->assertStringContainsString('Duo cannot be turned on', (string) service('mfaPolicy')->validateChange($this->proposal(['duo' => true]), 1));

        $this->mailer->configured = false;
        $this->assertStringContainsString('Email cannot be turned on', (string) service('mfaPolicy')->validateChange($this->proposal(['email' => true]), 1));
    }

    public function testRejectsBadRememberDaysAndUnknownRoles(): void
    {
        $this->assertNotNull(service('mfaPolicy')->validateChange($this->proposal(['rememberDays' => 400]), 1));
        $this->assertNotNull(service('mfaPolicy')->validateChange($this->proposal(['rememberDays' => -1]), 1));
        $this->assertStringContainsString('does not exist', (string) service('mfaPolicy')->validateChange($this->proposal(['requiredRoles' => [9999]]), 1));
    }

    public function testAdminCannotRequireMfaOfThemselvesWithoutAWorkingFactor(): void
    {
        $adminId = $this->makeUser('admin@example.com', [$this->roleId('admin')]);
        $change  = $this->proposal(['requiredRoles' => [$this->roleId('admin')]]);

        $this->assertStringContainsString('Set up a method for yourself first', (string) service('mfaPolicy')->validateChange($change, $adminId));

        $this->enrolTotp($adminId);
        $this->assertNull(service('mfaPolicy')->validateChange($change, $adminId));

        // An admin who is not themselves in a required role is not blocked.
        $other = $this->makeUser('ops@example.com', [$this->roleId('manager')]);
        $this->assertNull(service('mfaPolicy')->validateChange($change, $other));
    }

    public function testTighteningThePolicySignsOutRememberedDevices(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        $this->policy(['enabled' => true, 'totp' => true, 'rememberDays' => 30]);
        service('mfa')->issueTrustedDevice($id, 30);

        service('mfaPolicy')->apply($this->proposal(['rememberDays' => 30]));   // unchanged: keep trust
        $this->assertSame(1, service('mfa')->deviceCount($id));

        service('mfaPolicy')->apply($this->proposal(['rememberDays' => 7]));    // shorter window
        $this->assertSame(0, service('mfa')->deviceCount($id));
    }
}

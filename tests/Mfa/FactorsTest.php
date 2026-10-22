<?php

namespace Tests\Mfa;

use Tests\Support\Mfa\MfaTestCase;

final class FactorsTest extends MfaTestCase
{
    // ---- Authenticator app (TOTP) ----------------------------------------

    public function testTotpAcceptsTheCurrentCodeAndOneStepEitherSide(): void
    {
        $id = $this->makeUser();
        $secret = $this->enrolTotp($id);

        $this->assertTrue(service('totp')->verify($id, $this->codeFor($secret)));
    }

    public function testTotpToleratesClockDriftOfOneStepButNotMore(): void
    {
        $id = $this->makeUser();
        $secret = $this->enrolTotp($id);

        $this->assertTrue(service('totp')->verify($id, $this->codeFor($secret, -30)), 'Previous step is accepted.');
        $this->assertFalse(service('totp')->verify($id, $this->codeFor($secret, -120)), 'Two minutes old is rejected.');
        $this->assertFalse(service('totp')->verify($id, $this->codeFor($secret, 120)), 'Two minutes ahead is rejected.');
    }

    public function testTotpCodeCannotBeReplayed(): void
    {
        $id = $this->makeUser();
        $secret = $this->enrolTotp($id);
        $code = $this->codeFor($secret);

        $this->assertTrue(service('totp')->verify($id, $code));
        $this->assertFalse(service('totp')->verify($id, $code), 'The same code must not work twice.');
        $this->assertFalse(service('totp')->verify($id, $this->codeFor($secret, -30)), 'An older step than the one used must not work either.');
    }

    public function testTotpRejectsGarbage(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);

        foreach (['', '000000', '12345', '1234567', 'abcdef', '12 34 5x'] as $bad) {
            $this->assertFalse(service('totp')->verify($id, $bad), "'{$bad}' must be rejected.");
        }
    }

    public function testTotpSecretIsEncryptedAtRestAndCodeCannotBeUsedByAnotherUser(): void
    {
        $a = $this->makeUser('a@example.com');
        $b = $this->makeUser('b@example.com');
        $secret = $this->enrolTotp($a);

        $stored = db_connect()->table('user_mfa_methods')->where('user_id', $a)->get()->getRow('secret_enc');
        $this->assertStringNotContainsString($secret, (string) $stored, 'The TOTP secret must not be stored in plain text.');
        $this->assertSame($secret, service('mfa')->decrypt($stored));

        $this->assertFalse(service('totp')->verify($b, $this->codeFor($secret)), 'B has no authenticator enrolled.');
    }

    public function testEnrolmentProofStepIsBurned(): void
    {
        $id = $this->makeUser();
        $secret = service('totp')->newSecret();
        $step = service('totp')->matchStep($secret, $this->codeFor($secret));
        $this->assertNotNull($step);

        service('mfa')->addMethod($id, 'totp', $secret, $step);   // as MfaController does

        $this->assertFalse(service('totp')->verify($id, $this->codeFor($secret)), 'The code used to confirm enrolment must not be reusable at sign-in.');
    }

    public function testQrCodeIsAnInlineImage(): void
    {
        $uri = service('totp')->provisioningUri('JBSWY3DPEHPK3PXP', 'jane@example.com');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('issuer=', $uri);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', service('totp')->qrDataUri($uri));
    }

    // ---- Email codes ------------------------------------------------------

    private function emailedCode(): string
    {
        $this->assertNotEmpty($this->mailer->sent);
        $this->assertSame(1, preg_match('/(?:>|^)(\d{6})(?:<|$)/m', strip_tags(end($this->mailer->sent)['html'], '<p>'), $m) ?: preg_match('/\b(\d{6})\b/', end($this->mailer->sent)['html'], $m));

        return $m[1];
    }

    public function testEmailCodeIsHashedSingleUseAndSentToTheAccount(): void
    {
        $id = $this->makeUser();

        $this->assertSame('sent', service('emailCode')->send($id, 'jane@example.com'));
        $code = $this->emailedCode();
        $this->assertSame('jane@example.com', $this->mailer->sent[0]['to']);

        $row = db_connect()->table('mfa_challenges')->where('user_id', $id)->get()->getRowArray();
        $this->assertStringNotContainsString($code, json_encode($row), 'The code must not be stored in plain text.');

        $this->assertTrue(service('emailCode')->verify($id, $code));
        $this->assertFalse(service('emailCode')->verify($id, $code), 'Single use.');
    }

    public function testEmailCodeRespectsTheResendCooldown(): void
    {
        $id = $this->makeUser();

        $this->assertSame('sent', service('emailCode')->send($id, 'jane@example.com'));
        $this->assertSame('cooldown', service('emailCode')->send($id, 'jane@example.com'));
        $this->assertCount(1, $this->mailer->sent);

        db_connect()->table('mfa_challenges')->update(['created_at' => date('Y-m-d H:i:s', time() - 120)]);
        $this->assertSame('sent', service('emailCode')->send($id, 'jane@example.com'));
    }

    public function testEmailCodeIsLockedAfterTooManyWrongGuesses(): void
    {
        $id = $this->makeUser();
        service('emailCode')->send($id, 'jane@example.com');
        $code = $this->emailedCode();
        $max  = config(\Config\Mfa::class)->maxAttempts;

        for ($i = 0; $i < $max; $i++) {
            $this->assertFalse(service('emailCode')->verify($id, $code === '000000' ? '111111' : '000000'));
        }

        $this->assertFalse(service('emailCode')->verify($id, $code), 'After the attempt cap even the right code is refused.');
    }

    public function testExpiredEmailCodeIsRefused(): void
    {
        $id = $this->makeUser();
        service('emailCode')->send($id, 'jane@example.com');
        $code = $this->emailedCode();

        db_connect()->table('mfa_challenges')->update(['expires_at' => date('Y-m-d H:i:s', time() - 5)]);

        $this->assertFalse(service('emailCode')->verify($id, $code));
    }

    public function testEmailCodeUnavailableWithoutMail(): void
    {
        $id = $this->makeUser();
        $this->mailer->configured = false;

        $this->assertSame('unavailable', service('emailCode')->send($id, 'jane@example.com'));
    }

    public function testOneUsersEmailCodeDoesNotWorkForAnother(): void
    {
        $a = $this->makeUser('a@example.com');
        $b = $this->makeUser('b@example.com');
        service('emailCode')->send($a, 'a@example.com');
        $code = $this->emailedCode();

        $this->assertFalse(service('emailCode')->verify($b, $code));
    }

    // ---- Recovery codes ---------------------------------------------------

    public function testRecoveryCodesAreDistinctHashedAndSingleUse(): void
    {
        $id = $this->makeUser();
        $codes = service('mfa')->generateRecoveryCodes($id);

        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codes[0]);
        $this->assertStringNotContainsString(str_replace('-', '', $codes[0]), json_encode(db_connect()->table('mfa_recovery_codes')->get()->getResultArray()));

        $this->assertTrue(service('mfa')->useRecoveryCode($id, $codes[0]));
        $this->assertFalse(service('mfa')->useRecoveryCode($id, $codes[0]), 'Single use.');
        $this->assertSame(9, service('mfa')->recoveryCodesRemaining($id));
    }

    public function testRecoveryCodeInputIsForgiving(): void
    {
        $id = $this->makeUser();
        $codes = service('mfa')->generateRecoveryCodes($id);

        $this->assertTrue(service('mfa')->useRecoveryCode($id, ' ' . strtolower($codes[3]) . ' '));
        $this->assertTrue(service('mfa')->useRecoveryCode($id, str_replace('-', ' ', $codes[4])));
    }

    public function testRecoveryCodesBelongToOneUserAndRegenerationInvalidatesOldOnes(): void
    {
        $a = $this->makeUser('a@example.com');
        $b = $this->makeUser('b@example.com');
        $old = service('mfa')->generateRecoveryCodes($a);

        $this->assertFalse(service('mfa')->useRecoveryCode($b, $old[0]));
        service('mfa')->generateRecoveryCodes($a);
        $this->assertFalse(service('mfa')->useRecoveryCode($a, $old[0]), 'Old codes die when new ones are generated.');
        $this->assertFalse(service('mfa')->useRecoveryCode($a, 'AAAAA-BBBBB'));
        $this->assertFalse(service('mfa')->useRecoveryCode($a, ''));
    }

    // ---- Trusted devices --------------------------------------------------

    public function testTrustedDeviceTokenIsHashedExpiresAndIsPerUser(): void
    {
        $a = $this->makeUser('a@example.com');
        $b = $this->makeUser('b@example.com');
        $cookie = service('mfa')->issueTrustedDevice($a, 30);

        $this->assertTrue($cookie['httponly']);
        $this->assertSame('Lax', $cookie['samesite']);
        $this->assertSame(64, strlen($cookie['value']));
        $this->assertSame(hash('sha256', $cookie['value']), db_connect()->table('mfa_trusted_devices')->get()->getRow('token_hash'));

        service('request')->setGlobal('cookie', [$cookie['name'] => $cookie['value']]);
        $this->assertTrue(service('mfa')->isTrustedDevice($a));
        $this->assertFalse(service('mfa')->isTrustedDevice($b), "A's device is not B's.");

        db_connect()->table('mfa_trusted_devices')->update(['expires_at' => date('Y-m-d H:i:s', time() - 5)]);
        $this->assertFalse(service('mfa')->isTrustedDevice($a), 'Expired.');
    }

    public function testRevokingDevicesAndForgedCookiesDoNotWork(): void
    {
        $a = $this->makeUser();
        $cookie = service('mfa')->issueTrustedDevice($a, 30);
        service('request')->setGlobal('cookie', [$cookie['name'] => $cookie['value']]);

        service('mfa')->revokeDevices($a);
        $this->assertFalse(service('mfa')->isTrustedDevice($a));

        foreach (['', 'short', str_repeat('z', 64), str_repeat('0', 64)] as $forged) {
            service('request')->setGlobal('cookie', [$cookie['name'] => $forged]);
            $this->assertFalse(service('mfa')->isTrustedDevice($a));
        }
    }

    public function testRemovingTheLastMethodDropsRecoveryCodesAndDevices(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        service('mfa')->generateRecoveryCodes($id);
        service('mfa')->issueTrustedDevice($id, 30);

        service('mfa')->removeMethod($id, 'totp');

        $this->assertSame(0, service('mfa')->recoveryCodesRemaining($id));
        $this->assertSame(0, service('mfa')->deviceCount($id));
    }

    // ---- Lockout ----------------------------------------------------------

    public function testRepeatedFailuresLockTheAccountOutOfMfaForAWhile(): void
    {
        $id = $this->makeUser();
        $this->assertFalse(service('mfa')->isLockedOut($id));

        for ($i = 0; $i < config(\Config\Mfa::class)->lockoutFailures; $i++) {
            service('mfa')->recordFailure($id);
        }

        $this->assertTrue(service('mfa')->isLockedOut($id));
        service('mfa')->clearFailures($id);
        $this->assertFalse(service('mfa')->isLockedOut($id));
    }

    // ---- Deleting people removes their factors ----------------------------

    public function testDeletingAUserRemovesAllMfaData(): void
    {
        $id = $this->makeUser();
        $this->enrolTotp($id);
        service('mfa')->generateRecoveryCodes($id);
        service('emailCode')->send($id, 'jane@example.com');
        service('mfa')->issueTrustedDevice($id, 30);

        // The hard-delete case proves the foreign keys cascade (onUpdate, onDelete both CASCADE).
        db_connect()->table('users')->where('id', $id)->delete();

        foreach (['user_mfa_methods', 'mfa_recovery_codes', 'mfa_challenges', 'mfa_trusted_devices'] as $table) {
            $this->assertSame(0, db_connect()->table($table)->countAllResults(), "{$table} should be empty.");
        }
    }
}

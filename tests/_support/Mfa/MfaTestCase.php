<?php

namespace Tests\Support\Mfa;

use App\Core\Mailer;
use App\Core\UserService;
use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * Clean data + system roles per test, a capturing fake mailer, and a clean
 * settings store (the settings service caches values for the whole process).
 */
abstract class MfaTestCase extends DatabaseTestCase
{
    protected Mailer $mailer;

    /** Feature tests fill the real session with withSession(), so they must not use the mock. */
    protected bool $useMockSession = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new class () extends Mailer {
            /** @var list<array{to: string, subject: string, html: string}> */
            public array $sent = [];
            public bool $configured = true;

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function send(string $to, string $subject, string $html): bool
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html];

                return true;
            }
        };

        Services::injectMock('mailer', $this->mailer);
        // Nobody is signed in at the start of a test (a previous test's session and login must not leak).
        $_SESSION = [];
        foreach (['auth', 'settings', 'permissions', 'mfa', 'mfaPolicy', 'totp', 'emailCode', 'duo'] as $name) {
            Services::resetSingle($name);
        }
        if ($this->useMockSession) {
            $this->mockSession();
        }
        cache()->clean();
    }

    protected function makeUser(string $email = 'jane@example.com', array $roleIds = []): int
    {
        $id = (new UserService())->create(['email' => $email, 'first_name' => 'Jane', 'last_name' => 'Doe'], $roleIds)['id'];
        $this->mailer->sent = [];   // ignore the welcome email

        return $id;
    }

    /**
     * A Super Admin created directly in the database (UserService refuses to let a non-super actor make one).
     */
    protected function makeSuperUser(string $email): int
    {
        $id = $this->makeUser($email);
        db_connect()->table('user_roles')->insert(['user_id' => $id, 'role_id' => $this->roleId('super_admin')]);
        service('permissions')->forget($id);

        return $id;
    }

    protected function roleId(string $key): int
    {
        return (int) db_connect()->table('roles')->where('key', $key)->get()->getRow('id');
    }

    /**
     * @param array<string, mixed> $values any of enabled, totp, email, duo, requiredRoles, rememberDays
     */
    protected function policy(array $values): void
    {
        service('mfaPolicy')->save($values);
    }

    protected function enrolTotp(int $userId): string
    {
        $secret = service('totp')->newSecret();
        service('mfa')->addMethod($userId, 'totp', $secret);

        return $secret;
    }

    protected function codeFor(string $secret, int $offsetSeconds = 0): string
    {
        return \OTPHP\TOTP::createFromSecret($secret)->at(time() + $offsetSeconds);
    }
}

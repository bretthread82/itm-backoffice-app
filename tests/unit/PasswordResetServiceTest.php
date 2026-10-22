<?php

namespace Tests\Unit;

use App\Core\Mailer;
use App\Core\PasswordResetService;
use App\Core\UserService;
use App\Database\Seeds\RbacSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use RuntimeException;

final class PasswordResetServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = RbacSeeder::class;

    private Mailer $mailer;
    private PasswordResetService $svc;

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
        Services::resetSingle('permissions');
        $this->svc = new PasswordResetService();
    }

    private function user(string $email = 'jane@example.com'): int
    {
        $id = (new UserService())->create(['email' => $email, 'first_name' => 'Jane', 'last_name' => 'Doe'], [])['id'];
        $this->mailer->sent = [];   // ignore the welcome notification email

        return $id;
    }

    private function lastToken(): string
    {
        $this->assertNotEmpty($this->mailer->sent, 'An email should have been sent.');
        $this->assertSame(1, preg_match('#reset-password/([a-f0-9]{64})#', end($this->mailer->sent)['html'], $m));

        return $m[1];
    }

    private function passwordMatches(int $userId, string $password): bool
    {
        $hash = db_connect()->table('auth_identities')->where('user_id', $userId)->where('type', 'email_password')->get()->getRow('secret2');

        return service('passwords')->verify($password, $hash);
    }

    public function testUnknownEmailDoesNothingAndSendsNothing(): void
    {
        $this->svc->requestReset('nobody@example.com');

        $this->assertSame([], $this->mailer->sent);
        $this->assertSame(0, db_connect()->table('password_resets')->countAllResults());
    }

    public function testKnownLocalAccountGetsASingleUseLinkAndOnlyTheHashIsStored(): void
    {
        $id = $this->user();

        $this->svc->requestReset('  JANE@Example.com ');
        $token = $this->lastToken();

        $this->assertSame('jane@example.com', $this->mailer->sent[0]['to']);
        $row = db_connect()->table('password_resets')->get()->getRowArray();
        $this->assertSame($id, (int) $row['user_id']);
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertStringNotContainsString($token, json_encode($row), 'The raw token must never be stored.');
        $this->assertNotNull($this->svc->validate($token));
    }

    public function testMalformedOrUnknownTokensAreRejected(): void
    {
        $this->user();
        $this->svc->requestReset('jane@example.com');
        $token = $this->lastToken();

        foreach (['', 'abc', str_repeat('g', 64), strtoupper($token), substr($token, 0, 63), str_repeat('0', 64)] as $bad) {
            $this->assertNull($this->svc->validate($bad), "Token '{$bad}' must be rejected.");
        }
    }

    public function testResetChangesThePasswordAndTheLinkWorksOnlyOnce(): void
    {
        $id = $this->user();
        $this->svc->requestReset('jane@example.com');
        $hash = $this->svc->validate($this->lastToken());

        $this->assertSame($id, $this->svc->reset($hash, 'Correct-Horse-Battery-9'));
        $this->assertTrue($this->passwordMatches($id, 'Correct-Horse-Battery-9'));

        $this->expectException(RuntimeException::class);
        $this->svc->reset($hash, 'Another-Passw0rd-Entirely');
    }

    public function testPasswordStrengthIsCheckedAgainstTheAccountOwner(): void
    {
        $this->user('jane.doe@example.com');
        $this->svc->requestReset('jane.doe@example.com');
        $hash = $this->svc->validate($this->lastToken());

        foreach (['password1234', 'jane.doe@example.com', 'JaneDoe-2026-x', 'Jane-Doe-Jane-Doe'] as $weak) {
            $this->assertNotNull($this->svc->weaknessReason($hash, $weak), "'{$weak}' should be rejected.");
        }

        $this->assertNull($this->svc->weaknessReason($hash, 'Correct-Horse-Battery-9'));
        $this->assertNull($this->svc->weaknessReason(str_repeat('0', 64), 'anything'), 'An invalid link is left for reset() to refuse.');
    }

    public function testAFreshRequestSupersedesEarlierLinks(): void
    {
        $this->user();
        $this->svc->requestReset('jane@example.com');
        $first = $this->lastToken();
        $this->svc->requestReset('jane@example.com');
        $second = $this->lastToken();

        $this->assertNotSame($first, $second);
        $this->assertNull($this->svc->validate($first));
        $this->assertNotNull($this->svc->validate($second));
    }

    public function testExpiredLinksAreRejected(): void
    {
        $this->user();
        $this->svc->requestReset('jane@example.com');
        $token = $this->lastToken();
        $hash  = hash('sha256', $token);

        db_connect()->table('password_resets')->update(['expires_at' => date('Y-m-d H:i:s', time() - 5)]);

        $this->assertNull($this->svc->validate($token));
        $this->expectException(RuntimeException::class);
        $this->svc->reset($hash, 'Correct-Horse-Battery-9');
    }

    public function testResetClearsAForcedPasswordChangeAndNotifiesTheUser(): void
    {
        $id = $this->user();   // new accounts are forced to change their password
        $this->assertTrue(auth()->getProvider()->findById($id)->requiresPasswordReset());

        $this->svc->requestReset('jane@example.com');
        $this->svc->reset($this->svc->validate($this->lastToken()), 'Correct-Horse-Battery-9');

        $this->assertFalse(auth()->getProvider()->findById($id)->requiresPasswordReset());
        $this->seeInDatabase('notifications', ['user_id' => $id, 'type' => 'account.password_changed']);
        $this->seeInDatabase('audit_log', ['action' => 'auth.password_reset_completed', 'entity_id' => (string) $id]);
    }

    /**
     * @dataProvider ineligibleAccounts
     */
    public function testIneligibleAccountsGetNoEmailAndNoToken(string $setup): void
    {
        $id = $this->user();

        match ($setup) {
            'entra'    => db_connect()->table('staff_profiles')->where('user_id', $id)->update(['source' => 'entra']),
            'inactive' => db_connect()->table('staff_profiles')->where('user_id', $id)->update(['status' => 'inactive']),
            'banned'   => (function () use ($id): void {
                $u = auth()->getProvider()->findById($id);
                $u->ban('test');
                auth()->getProvider()->save($u);
            })(),
            'deleted'  => auth()->getProvider()->delete($id),
        };

        $this->svc->requestReset('jane@example.com');

        $this->assertSame([], $this->mailer->sent);
        $this->assertSame(0, db_connect()->table('password_resets')->countAllResults());
    }

    public static function ineligibleAccounts(): array
    {
        return ['entra-managed' => ['entra'], 'inactive' => ['inactive'], 'banned' => ['banned'], 'deleted' => ['deleted']];
    }

    public function testAnAccountThatBecomesIneligibleCannotCompleteAnOutstandingLink(): void
    {
        $id = $this->user();
        $this->svc->requestReset('jane@example.com');
        $hash = $this->svc->validate($this->lastToken());

        db_connect()->table('staff_profiles')->where('user_id', $id)->update(['status' => 'suspended']);

        $this->expectException(RuntimeException::class);
        $this->svc->reset($hash, 'Correct-Horse-Battery-9');
    }

    public function testRequestsPerAccountAreRateLimited(): void
    {
        $this->user();
        $limit = config(\Config\Backoffice::class)->passwordResetMaxPerHour;

        for ($i = 0; $i < $limit + 3; $i++) {
            $this->svc->requestReset('jane@example.com');
        }

        $this->assertCount($limit, $this->mailer->sent);
    }

    public function testNothingHappensWhenEmailIsNotConfigured(): void
    {
        $this->user();
        $this->mailer->configured = false;

        $this->assertFalse($this->svc->isAvailable());
        $this->svc->requestReset('jane@example.com');

        $this->assertSame(0, db_connect()->table('password_resets')->countAllResults());
    }
}

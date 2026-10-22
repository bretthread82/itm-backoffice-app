<?php

namespace Tests\Mfa;

use App\Core\Mfa\DuoService;
use Duo\DuoUniversal\Client;
use Duo\DuoUniversal\DuoException;
use RuntimeException;
use Tests\Support\Mfa\MfaTestCase;

/**
 * Duo is exercised with a stand-in client: nothing here talks to Duo.
 */
final class DuoServiceTest extends MfaTestCase
{
    private function stub(): Client
    {
        return new class (str_repeat('A', 20), str_repeat('B', 40), 'api-test.duosecurity.com', 'http://localhost/mfa/duo/callback') extends Client {
            public bool $down = false;
            public array $result = ['preferred_username' => 'jane@example.com', 'auth_result' => ['result' => 'allow']];

            public function healthCheck(): array
            {
                if ($this->down) {
                    throw new DuoException('Duo is down');
                }

                return ['stat' => 'OK'];
            }

            public function createAuthUrl(string $username, string $state): string
            {
                return 'https://api-test.duosecurity.com/oauth/v1/authorize?user=' . rawurlencode($username) . '&state=' . $state;
            }

            public function exchangeAuthorizationCodeFor2FAResult(string $duoCode, string $username, ?string $nonce = null): array
            {
                if ($duoCode === 'bad') {
                    throw new DuoException('Signature invalid');
                }

                return $this->result;
            }
        };
    }

    private function state(): string
    {
        return session()->get('mfa_duo')['state'];
    }

    public function testStartRedirectsToDuoAndRemembersTheFlow(): void
    {
        $svc = new DuoService($this->stub());
        $url = $svc->start(7, 'Jane@Example.com', 'login');

        $this->assertStringStartsWith('https://api-test.duosecurity.com/', $url);
        $this->assertStringContainsString('jane%40example.com', $url, 'The username is the lower-cased email.');
        $this->assertSame(7, session()->get('mfa_duo')['user']);
    }

    public function testCompleteSucceedsOnceAndReportsThePurpose(): void
    {
        $svc = new DuoService($this->stub());
        $svc->start(7, 'jane@example.com', 'enrol');
        $state = $this->state();

        $this->assertSame('enrol', $svc->complete('good', $state, 7));

        $this->expectException(RuntimeException::class);   // the flow state is single-use
        $svc->complete('good', $state, 7);
    }

    public function testWrongStateIsRejectedAndBurnsTheFlow(): void
    {
        $svc = new DuoService($this->stub());
        $svc->start(7, 'jane@example.com', 'login');
        $real = $this->state();

        try {
            $svc->complete('good', 'forged-state', 7);
            $this->fail('A wrong state must be rejected.');
        } catch (RuntimeException) {
            // expected
        }

        $this->expectException(RuntimeException::class);
        $svc->complete('good', $real, 7);   // already burned
    }

    public function testAnotherUsersSessionCannotCompleteTheFlow(): void
    {
        $svc = new DuoService($this->stub());
        $svc->start(7, 'jane@example.com', 'login');

        $this->expectException(RuntimeException::class);
        $svc->complete('good', $this->state(), 8);
    }

    public function testExpiredFlowIsRejected(): void
    {
        $svc = new DuoService($this->stub());
        $svc->start(7, 'jane@example.com', 'login');
        $state = $this->state();
        $flow = session()->get('mfa_duo');
        $flow['expires'] = time() - 1;
        session()->set('mfa_duo', $flow);

        $this->expectException(RuntimeException::class);
        $svc->complete('good', $state, 7);
    }

    public function testADenyResultIsNotAPass(): void
    {
        $client = $this->stub();
        $client->result['auth_result']['result'] = 'deny';
        $svc = new DuoService($client);
        $svc->start(7, 'jane@example.com', 'login');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not approve');
        $svc->complete('good', $this->state(), 7);
    }

    public function testAMissingResultIsNotAPass(): void
    {
        $client = $this->stub();
        $client->result = ['preferred_username' => 'jane@example.com'];
        $svc = new DuoService($client);
        $svc->start(7, 'jane@example.com', 'login');

        $this->expectException(RuntimeException::class);
        $svc->complete('good', $this->state(), 7);
    }

    public function testADuoErrorOnExchangeIsAFailureNotAPass(): void
    {
        $svc = new DuoService($this->stub());
        $svc->start(7, 'jane@example.com', 'login');

        $this->expectException(RuntimeException::class);
        $svc->complete('bad', $this->state(), 7);
    }

    public function testDuoBeingUnreachableFailsClosed(): void
    {
        $client = $this->stub();
        $client->down = true;
        $svc = new DuoService($client);

        try {
            $svc->start(7, 'jane@example.com', 'login');
            $this->fail('An unreachable Duo must not produce a redirect.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('could not be reached', $e->getMessage());
        }

        $this->assertNull(session()->get('mfa_duo'), 'No flow may be left behind.');
    }

    public function testUnconfiguredDuoCannotStart(): void
    {
        $this->expectException(RuntimeException::class);
        (new DuoService())->start(7, 'jane@example.com', 'login');   // .env holds placeholders
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Entra\EntraConfig;
use App\Services\Entra\EntraException;
use App\Services\Entra\MicrosoftClient;
use CodeIgniter\Test\CIUnitTestCase;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Exercises the real Azure provider code path with Microsoft replaced by an in-memory fake:
 * the test signs ID tokens with its own RSA key and publishes the matching key set.
 *
 * @internal
 */
final class MicrosoftClientTest extends CIUnitTestCase
{
    private const TENANT = '11111111-2222-3333-4444-555555555555';
    private const CLIENT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    private const OID    = '99999999-8888-7777-6666-555555555555';

    /** @var \OpenSSLAsymmetricKey */
    private $signingKey;
    private string $kid = 'test-key-1';

    /** @var list<string> requests the fake Microsoft received */
    private array $requests = [];

    private string $tokenBody = '';

    /** @var array<string, string> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['entra.tenantId' => self::TENANT, 'entra.clientId' => self::CLIENT, 'entra.clientSecret' => 'secret-value'] as $k => $v) {
            $this->savedEnv[$k] = $_SERVER[$k] ?? '';
            $_SERVER[$k]        = $v;
            $_ENV[$k]           = $v;
        }

        $this->signingKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->requests   = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            $v === '' ? ($_SERVER[$k] = $_ENV[$k] = '') : ($_SERVER[$k] = $_ENV[$k] = $v);
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------- the fake Microsoft

    private function publicJwk(\OpenSSLAsymmetricKey $key, string $kid): array
    {
        $d = openssl_pkey_get_details($key)['rsa'];

        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid,
            'n' => rtrim(strtr(base64_encode($d['n']), '+/', '-_'), '='), 'e' => rtrim(strtr(base64_encode($d['e']), '+/', '-_'), '=')];
    }

    /**
     * @param array<string, mixed> $claimOverrides
     */
    private function idToken(array $claimOverrides = [], ?\OpenSSLAsymmetricKey $signWith = null): string
    {
        $now    = time();
        $claims = array_merge([
            'aud' => self::CLIENT, 'iss' => 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0', 'iat' => $now, 'nbf' => $now - 5, 'exp' => $now + 3600,
            'tid' => self::TENANT, 'oid' => self::OID, 'sub' => 'subject', 'ver' => '2.0', 'nonce' => 'the-nonce',
            'name' => 'Pat Example', 'email' => 'Pat.Example@Maloney.test', 'preferred_username' => 'pat@maloney.test',
        ], $claimOverrides);

        $pem = '';
        openssl_pkey_export($signWith ?? $this->signingKey, $pem);

        return JWT::encode($claims, $pem, 'RS256', $this->kid);
    }

    private function http(string $idToken): Client
    {
        $handler = function (RequestInterface $request) use ($idToken) {
            $url = (string) $request->getUri();
            $this->requests[] = $request->getMethod() . ' ' . $url;

            if (str_contains($url, '.well-known/openid-configuration')) {
                $body = json_encode([
                    'issuer'                 => 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0',
                    'authorization_endpoint' => 'https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/authorize',
                    'token_endpoint'         => 'https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token',
                    'jwks_uri'               => 'https://login.microsoftonline.com/' . self::TENANT . '/discovery/v2.0/keys',
                ]);
            } elseif (str_contains($url, '/oauth2/v2.0/token')) {
                $this->tokenBody = (string) $request->getBody();
                $body = json_encode(['token_type' => 'Bearer', 'access_token' => 'access-token', 'expires_in' => 3600, 'id_token' => $idToken]);
            } elseif (str_contains($url, '/discovery/v2.0/keys')) {
                $body = json_encode(['keys' => [$this->publicJwk($this->signingKey, $this->kid)]]);
            } else {
                return \GuzzleHttp\Promise\Create::rejectionFor(new \RuntimeException('Unexpected request: ' . $url));
            }

            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], $body));
        };

        return new Client(['handler' => HandlerStack::create($handler)]);
    }

    private function complete(string $idToken, string $nonce = 'the-nonce'): \App\Services\Entra\EntraIdentity
    {
        return (new MicrosoftClient($this->http($idToken)))->complete('auth-code', $nonce, 'pkce-verifier');
    }

    // -------------------------------------------------------------- start

    public function testBeginBuildsASingleTenantPkceAuthorizationUrl(): void
    {
        $begin = (new MicrosoftClient($this->http('unused')))->begin();
        parse_str((string) parse_url($begin['url'], PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/authorize?', $begin['url']);
        $this->assertSame(self::CLIENT, $q['client_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame(EntraConfig::redirectUri(), $q['redirect_uri']);
        $this->assertSame($begin['state'], $q['state']);
        $this->assertSame($begin['nonce'], $q['nonce']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertArrayHasKey('code_challenge', $q);
        $this->assertNotEmpty($begin['pkce']);
        $this->assertStringContainsString('openid', $q['scope']);
        $this->assertNotEmpty($begin['state']);
        $this->assertSame(32, strlen($begin['nonce']));
    }

    public function testStateAndNonceAreDifferentEveryTime(): void
    {
        $a = (new MicrosoftClient($this->http('x')))->begin();
        $b = (new MicrosoftClient($this->http('x')))->begin();

        $this->assertNotSame($a['state'], $b['state']);
        $this->assertNotSame($a['nonce'], $b['nonce']);
        $this->assertNotSame($a['pkce'], $b['pkce']);
    }

    public function testStartingWhileMicrosoftIsUnreachableIsAnErrorNotACrash(): void
    {
        $offline = new Client(['handler' => HandlerStack::create(new MockHandler([
            new \GuzzleHttp\Exception\ConnectException('offline', new \GuzzleHttp\Psr7\Request('GET', 'https://login.microsoftonline.com')),
        ]))]);

        $this->expectException(EntraException::class);
        (new MicrosoftClient($offline))->begin();
    }

    public function testMicrosoftAnsweringWithGarbageWhenStartingIsAnErrorNotACrash(): void
    {
        $garbage = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], 'not json at all')]))]);

        $this->expectException(EntraException::class);
        (new MicrosoftClient($garbage))->begin();
    }

    public function testRefusesToStartWhenNotConfigured(): void
    {
        $_SERVER['entra.clientSecret'] = $_ENV['entra.clientSecret'] = '';

        $this->expectException(EntraException::class);
        (new MicrosoftClient($this->http('x')))->begin();
    }

    // -------------------------------------------------------------- a valid sign-in

    public function testValidTokenYieldsTheIdentity(): void
    {
        $identity = $this->complete($this->idToken());

        $this->assertSame(self::OID, $identity->oid);
        $this->assertSame(self::TENANT, $identity->tenantId);
        $this->assertSame('pat.example@maloney.test', $identity->email); // lower-cased
        $this->assertSame('Pat Example', $identity->name);
    }

    public function testTheCodeAndPkceVerifierAreSentToMicrosoft(): void
    {
        $this->complete($this->idToken());

        $this->assertCount(1, array_filter($this->requests, static fn (string $r): bool => str_starts_with($r, 'POST ') && str_contains($r, '/oauth2/v2.0/token')));

        parse_str($this->tokenBody, $body);
        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('auth-code', $body['code']);
        $this->assertSame('pkce-verifier', $body['code_verifier']);   // PKCE proof reaches Microsoft
        $this->assertSame(self::CLIENT, $body['client_id']);
        $this->assertSame('secret-value', $body['client_secret']);
        $this->assertSame(EntraConfig::redirectUri(), $body['redirect_uri']);
    }

    public function testEmailFallsBackToPreferredUsernameThenUpn(): void
    {
        $this->assertSame('pat@maloney.test', $this->complete($this->idToken(['email' => null]))->email);
        $this->assertSame('upn@maloney.test', $this->complete($this->idToken(['email' => '', 'preferred_username' => 'not an email', 'upn' => 'upn@maloney.test']))->email);
        $this->assertSame('', $this->complete($this->idToken(['email' => null, 'preferred_username' => null]))->email);
    }

    // -------------------------------------------------------------- everything wrong is refused

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badClaims(): array
    {
        return [
            'wrong audience (a token for another app)' => [['aud' => 'some-other-app']],
            'expired'                                 => [['nbf' => time() - 7200, 'exp' => time() - 3600]],
            'not valid yet'                           => [['nbf' => time() + 3600, 'exp' => time() + 7200]],
            'wrong issuer'                            => [['iss' => 'https://login.microsoftonline.com/other-tenant/v2.0']],
            'token from a different tenant'           => [['tid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff']],
            'missing tenant id'                       => [['tid' => null]],
            'missing object id'                       => [['oid' => null]],
            'object id with odd characters'           => [['oid' => '<script>']],
            'replayed nonce'                          => [['nonce' => 'a-different-nonce']],
            'no nonce'                                => [['nonce' => null]],
        ];
    }

    /**
     * @dataProvider badClaims
     *
     * @param array<string, mixed> $claims
     */
    public function testBadTokensAreRefused(array $claims): void
    {
        $this->expectException(EntraException::class);
        $this->complete($this->idToken($claims));
    }

    public function testATokenSignedWithTheWrongKeyIsRefused(): void
    {
        $attacker = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $this->expectException(EntraException::class);
        $this->complete($this->idToken([], $attacker)); // valid claims, but not signed by Microsoft's key
    }

    public function testATamperedTokenIsRefused(): void
    {
        [$h, $p, $s] = explode('.', $this->idToken());
        $claims       = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
        $claims['email'] = 'ceo@maloney.test';
        $forged       = $h . '.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.' . $s;

        $this->expectException(EntraException::class);
        $this->complete($forged);
    }

    public function testAnUnsignedTokenIsRefused(): void
    {
        [$h, $p] = explode('.', $this->idToken());

        $this->expectException(EntraException::class);
        $this->complete($h . '.' . $p . '.');
    }

    public function testMicrosoftBeingUnreachableIsAnErrorNotACrash(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new \GuzzleHttp\Exception\ConnectException('offline', new \GuzzleHttp\Psr7\Request('GET', 'https://login.microsoftonline.com'))]))]);

        $this->expectException(EntraException::class);
        (new MicrosoftClient($client))->complete('code', 'the-nonce', null);
    }

    // -------------------------------------------------------------- tenant pinning

    public function testMultiTenantEndpointsAreRefusedByConfiguration(): void
    {
        foreach (['common', 'organizations', 'consumers', 'COMMON'] as $tenant) {
            $_SERVER['entra.tenantId'] = $_ENV['entra.tenantId'] = $tenant;
            $this->assertFalse(EntraConfig::configured(), $tenant);
            $this->assertNotSame([], EntraConfig::problems());
        }

        $this->expectException(EntraException::class);
        (new MicrosoftClient($this->http('x')))->begin();
    }

    public function testConfigurationProblemsAreExplained(): void
    {
        $this->assertTrue(EntraConfig::configured());

        foreach (['entra.tenantId' => 'tenantId', 'entra.clientId' => 'clientId', 'entra.clientSecret' => 'clientSecret'] as $key => $name) {
            $saved = $_SERVER[$key];
            $_SERVER[$key] = $_ENV[$key] = '';

            $problems = implode(' ', EntraConfig::problems());
            $this->assertStringContainsString($name, $problems);
            $this->assertFalse(EntraConfig::configured());

            $_SERVER[$key] = $_ENV[$key] = $saved;
        }

        $_SERVER['entra.tenantId'] = $_ENV['entra.tenantId'] = 'bad tenant!';
        $this->assertFalse(EntraConfig::configured());
    }
}

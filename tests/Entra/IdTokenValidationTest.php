<?php

namespace Tests\Entra;

use Tests\Support\Entra\EntraTestCase;
use App\Core\Entra\EntraClient;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Proves the id_token checks reject anything that is not exactly what we asked for.
 */
final class IdTokenValidationTest extends EntraTestCase
{
    protected $seed = '';

    private \OpenSSLAsymmetricKey $key;
    private string $privatePem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($this->key, $pem);
        $this->privatePem = $pem;
        $d = openssl_pkey_get_details($this->key);

        $b64 = static fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        // Pre-seed the JWKS cache so no network call happens.
        cache()->save('entra_jwks', ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k1', 'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])]]], 3600);
    }

    private function token(array $override = [], ?string $pem = null): string
    {
        $claims = $override + [
            'iss' => 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0',
            'tid' => self::TENANT, 'aud' => self::CLIENT, 'nonce' => 'n-123', 'oid' => 'aaaa-bbbb',
            'iat' => time(), 'nbf' => time(), 'exp' => time() + 600,
        ];

        return JWT::encode($claims, $pem ?? $this->privatePem, 'RS256', 'k1');
    }

    public function testValidTokenIsAccepted(): void
    {
        $claims = (new EntraClient($this->entra))->validateIdToken($this->token(), 'n-123');

        $this->assertSame('aaaa-bbbb', $claims['oid']);
    }

    /**
     * @dataProvider badClaims
     */
    public function testTamperedClaimsAreRejected(array $override): void
    {
        $this->expectException(RuntimeException::class);

        (new EntraClient($this->entra))->validateIdToken($this->token($override), 'n-123');
    }

    public static function badClaims(): array
    {
        return [
            'wrong audience'  => [['aud' => 'someone-else']],
            'wrong issuer'    => [['iss' => 'https://login.microsoftonline.com/other/v2.0']],
            'wrong tenant'    => [['tid' => '99999999-9999-9999-9999-999999999999']],
            'wrong nonce'     => [['nonce' => 'replayed']],
            'missing oid'     => [['oid' => '']],
            'expired'         => [['exp' => time() - 3600, 'nbf' => time() - 7200, 'iat' => time() - 7200]],
        ];
    }

    public function testTokenSignedWithAnotherKeyIsRejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);

        $this->expectException(RuntimeException::class);

        (new EntraClient($this->entra))->validateIdToken($this->token([], $otherPem), 'n-123');
    }

    public function testUnsignedTokenIsRejected(): void
    {
        $header  = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT","kid":"k1"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['iss' => 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0', 'tid' => self::TENANT, 'aud' => self::CLIENT, 'nonce' => 'n-123', 'oid' => 'x', 'exp' => time() + 600])), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);

        (new EntraClient($this->entra))->validateIdToken("{$header}.{$payload}.", 'n-123');
    }

    public function testAuthorizeUrlUsesPkceAndTenantEndpoint(): void
    {
        $url = (new EntraClient($this->entra))->authorizeUrl('st', 'no', 'chal');

        $this->assertStringStartsWith('https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/authorize?', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('state=st', $url);
        $this->assertStringContainsString('nonce=no', $url);
    }
}

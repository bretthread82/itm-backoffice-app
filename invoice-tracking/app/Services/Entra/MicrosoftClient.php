<?php

declare(strict_types=1);

namespace App\Services\Entra;

use TheNetworg\OAuth2\Client\Provider\Azure;
use Throwable;

/**
 * Authorisation-code flow with PKCE against one pinned tenant, using TheNetworg's Azure provider.
 *
 * The provider already checks the ID token's signature (Microsoft's published keys), audience,
 * expiry and issuer. This class adds: refusing multi-tenant endpoints, the nonce, the tenant id claim,
 * and requiring a stable object id (oid).
 */
final class MicrosoftClient implements EntraClient
{
    /**
     * @param \GuzzleHttp\ClientInterface|null $http only tests pass one, to answer Microsoft's requests offline
     */
    public function __construct(private readonly ?\GuzzleHttp\ClientInterface $http = null)
    {
    }

    public function begin(): array
    {
        $this->assertConfigured();

        $nonce = bin2hex(random_bytes(16));

        // Building the URL asks Microsoft for the tenant's endpoints, so it can fail on the network.
        try {
            $provider = $this->provider();
            $url      = $provider->getAuthorizationUrl([
                'scope'  => ['openid', 'profile', 'email'],
                'nonce'  => $nonce,
                'prompt' => 'select_account',
            ]);
        } catch (Throwable $e) {
            throw new EntraException('Could not reach Microsoft to start sign-in: ' . $e->getMessage(), 0, $e);
        }

        return ['url' => $url, 'state' => $provider->getState(), 'nonce' => $nonce, 'pkce' => $provider->getPkceCode()];
    }

    public function complete(string $code, string $nonce, ?string $pkce): EntraIdentity
    {
        $this->assertConfigured();

        try {
            $provider = $this->provider();
            if ($pkce !== null && $pkce !== '') {
                $provider->setPkceCode($pkce);
            }

            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);

            // The library accepts an ID token with an empty signature as an unsigned v1 token and skips
            // verification. We only ever want signed tokens, so refuse anything else.
            $parts = explode('.', (string) $token->getIdToken());
            if (count($parts) !== 3 || $parts[2] === '') {
                throw new EntraException('The ID token was not signed.');
            }

            $claims = $token->getIdTokenClaims();
        } catch (Throwable $e) {
            throw new EntraException('Token exchange or validation failed: ' . $e->getMessage(), 0, $e);
        }

        return self::identityFromClaims(is_array($claims) ? $claims : [], $nonce, EntraConfig::tenant());
    }

    /**
     * Turns validated ID-token claims into an identity, or refuses.
     *
     * @param array<string, mixed> $claims
     *
     * @throws EntraException
     */
    public static function identityFromClaims(array $claims, string $expectedNonce, string $expectedTenant): EntraIdentity
    {
        if ($expectedNonce === '' || ! isset($claims['nonce']) || ! hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new EntraException('The sign-in response did not match the request (nonce).');
        }

        $tid = (string) ($claims['tid'] ?? '');
        if ($tid === '') {
            throw new EntraException('The token has no tenant id.');
        }
        // A tenant configured as a GUID must match exactly. A configured domain name cannot be compared
        // to the GUID in the token, so for that case the issuer check inside the provider is what pins it.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $expectedTenant) === 1 && ! hash_equals(strtolower($expectedTenant), strtolower($tid))) {
            throw new EntraException('The token is from a different organisation (' . $tid . ').');
        }

        $oid = (string) ($claims['oid'] ?? '');
        if ($oid === '' || preg_match('/^[0-9a-f-]{8,64}$/i', $oid) !== 1) {
            throw new EntraException('The token has no usable object id.');
        }

        $email = '';
        foreach (['email', 'preferred_username', 'upn'] as $key) {
            $candidate = strtolower(trim((string) ($claims[$key] ?? '')));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $email = $candidate;
                break;
            }
        }

        return new EntraIdentity(strtolower($oid), strtolower($tid), $email, trim((string) ($claims['name'] ?? '')));
    }

    private function assertConfigured(): void
    {
        $problems = EntraConfig::problems();
        if ($problems !== []) {
            throw new EntraException('Microsoft sign-in is not configured: ' . implode(' ', $problems));
        }
    }

    private function provider(): Azure
    {
        // PKCE is switched on by overriding getPkceMethod(); there is no constructor option for it.
        $provider = new class([
            'tenant'                 => EntraConfig::tenant(),
            'clientId'               => EntraConfig::clientId(),
            'clientSecret'           => EntraConfig::clientSecret(),
            'redirectUri'            => EntraConfig::redirectUri(),
            'defaultEndPointVersion' => '2.0',
        ], $this->http !== null ? ['httpClient' => $this->http] : []) extends Azure {
            protected function getPkceMethod(): string
            {
                return self::PKCE_METHOD_S256;
            }
        };

        return $provider;
    }
}

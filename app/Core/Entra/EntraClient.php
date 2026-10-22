<?php

namespace App\Core\Entra;

use CodeIgniter\HTTP\CURLRequest;
use Config\Entra;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use Throwable;

/**
 * Thin Microsoft identity platform / Graph client: OIDC authorization-code
 * flow with PKCE for sign-in, and client-credentials for directory reads.
 */
class EntraClient
{
    private const LOGIN_HOST = 'https://login.microsoftonline.com';
    private const GRAPH_HOST = 'https://graph.microsoft.com/v1.0';

    private Entra $config;

    public function __construct(?Entra $config = null)
    {
        $this->config = $config ?? config(Entra::class);
    }

    // ---- Sign-in (OIDC authorization code + PKCE) -------------------------

    public function authorizeUrl(string $state, string $nonce, string $codeChallenge): string
    {
        return $this->tenantUrl('/oauth2/v2.0/authorize') . '?' . http_build_query([
            'client_id'             => $this->config->clientId,
            'response_type'         => 'code',
            'redirect_uri'          => $this->config->callbackUrl(),
            'response_mode'         => 'query',
            'scope'                 => 'openid profile email',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed> token response (contains id_token)
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->config->callbackUrl(),
            'code_verifier' => $codeVerifier,
            'scope'         => 'openid profile email',
        ]);
    }

    /**
     * Verifies signature, issuer, audience, tenant, expiry and nonce.
     *
     * @return array<string, mixed> claims
     */
    public function validateIdToken(string $idToken, string $expectedNonce): array
    {
        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->signingKeys(), 'RS256'));
        } catch (Throwable $e) {
            throw new RuntimeException('The sign-in token could not be verified.', 0, $e);
        }

        $issuer = self::LOGIN_HOST . '/' . $this->config->tenantId . '/v2.0';

        if (($claims['iss'] ?? '') !== $issuer
            || ($claims['tid'] ?? '') !== $this->config->tenantId
            || ($claims['aud'] ?? '') !== $this->config->clientId
            || ! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))
            || empty($claims['oid'])) {
            throw new RuntimeException('The sign-in token did not match this application.');
        }

        return $claims;
    }

    // ---- Microsoft Graph (application permissions) ------------------------

    /**
     * GET a Graph URL (absolute for paging, or a path like /users) and decode JSON.
     *
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    public function graphGet(string $pathOrUrl, array $query = []): array
    {
        $url = str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : self::GRAPH_HOST . $pathOrUrl;

        // Only follow Graph URLs we were handed (nextLink), never arbitrary hosts.
        if (! str_starts_with($url, self::GRAPH_HOST . '/') && ! str_starts_with($url, 'https://graph.microsoft.com/')) {
            throw new RuntimeException('Refusing to call a non-Graph URL.');
        }

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = $this->http()->get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->graphToken(), 'Accept' => 'application/json', 'ConsistencyLevel' => 'eventual'],
                'query'   => $query,
            ]);
            $status = $response->getStatusCode();

            if (in_array($status, [429, 503, 504], true) && $attempt < 4) {
                sleep(min(30, max(1, (int) $response->getHeaderLine('Retry-After') ?: $attempt * 2)));

                continue;
            }

            $body = json_decode($response->getBody(), true);

            if ($status >= 400 || ! is_array($body)) {
                $message = $body['error']['message'] ?? ('HTTP ' . $status);

                throw new RuntimeException('Microsoft Graph error: ' . $message);
            }

            return $body;
        }

        throw new RuntimeException('Microsoft Graph is throttling requests. Try again later.');
    }

    /**
     * Follows @odata.nextLink and yields every item.
     *
     * @param array<string, scalar> $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function graphPaged(string $path, array $query = []): \Generator
    {
        $url   = $path;
        $first = true;

        do {
            $page = $this->graphGet($url, $first ? $query : []);
            $first = false;

            foreach ($page['value'] ?? [] as $item) {
                yield $item;
            }

            $url = $page['@odata.nextLink'] ?? null;
        } while ($url);
    }

    /**
     * Cheap end-to-end check used by the admin "Test connection" button.
     *
     * @return array{name: string}
     */
    public function testConnection(): array
    {
        $org = $this->graphGet('/organization', ['$select' => 'displayName,id']);
        $row = $org['value'][0] ?? null;

        if (! $row || ($row['id'] ?? '') !== $this->config->tenantId) {
            throw new RuntimeException('Connected, but the organisation does not match the configured tenant id.');
        }

        return ['name' => (string) ($row['displayName'] ?? '')];
    }

    // ---- internals --------------------------------------------------------

    private function graphToken(): string
    {
        $cached = cache('entra_graph_token');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->tokenRequest([
            'grant_type' => 'client_credentials',
            'scope'      => 'https://graph.microsoft.com/.default',
        ]);

        cache()->save('entra_graph_token', $token['access_token'], max(60, (int) ($token['expires_in'] ?? 3600) - 120));

        return $token['access_token'];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(array $params): array
    {
        $response = $this->http()->post($this->tenantUrl('/oauth2/v2.0/token'), [
            'form_params' => $params + ['client_id' => $this->config->clientId, 'client_secret' => $this->config->clientSecret],
        ]);

        $body = json_decode($response->getBody(), true);

        if ($response->getStatusCode() >= 400 || ! is_array($body) || empty($body['access_token'] ?? $body['id_token'] ?? null)) {
            // error_description can contain tenant details; log it, show a generic message.
            log_message('error', 'Entra token request failed: {e}', ['e' => $body['error_description'] ?? $body['error'] ?? ('HTTP ' . $response->getStatusCode())]);

            throw new RuntimeException('Microsoft rejected the request. Check the Entra configuration.');
        }

        return $body;
    }

    /**
     * @return array<string, mixed> JWKS
     */
    private function signingKeys(): array
    {
        $jwks = cache('entra_jwks');

        if (! is_array($jwks)) {
            $response = $this->http()->get($this->tenantUrl('/discovery/v2.0/keys'));
            $jwks     = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200 || ! isset($jwks['keys'])) {
                throw new RuntimeException('Could not load Microsoft signing keys.');
            }

            cache()->save('entra_jwks', $jwks, 3600);
        }

        return $jwks;
    }

    private function tenantUrl(string $path): string
    {
        return self::LOGIN_HOST . '/' . rawurlencode($this->config->tenantId) . $path;
    }

    private function http(): CURLRequest
    {
        return service('curlrequest', ['timeout' => 20, 'connect_timeout' => 10, 'http_errors' => false, 'verify' => true]);
    }
}

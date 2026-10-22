<?php

namespace App\Core\Mfa;

use Config\Duo;
use Duo\DuoUniversal\Client;
use Duo\DuoUniversal\DuoException;
use RuntimeException;

/**
 * Duo Universal Prompt. The browser is redirected to Duo, authenticates there
 * (Duo handles its own enrolment), and returns with a code that we exchange
 * for a signed result. Fails CLOSED: if Duo cannot be reached the user is not
 * let through.
 */
class DuoService
{
    private const SESSION_KEY = 'mfa_duo';
    private const STATE_TTL   = 600;

    public function __construct(private ?Client $client = null)
    {
    }

    public function isReady(): bool
    {
        return config(Duo::class)->isConfigured();
    }

    /**
     * Begins an authentication and returns the Duo URL to redirect to.
     *
     * @param 'login'|'enrol' $purpose
     */
    public function start(int $userId, string $username, string $purpose): string
    {
        $client = $this->client();

        try {
            $client->healthCheck();
            $state = $client->generateState();
            $url   = $client->createAuthUrl(strtolower($username), $state);
        } catch (DuoException $e) {
            log_message('error', 'Duo unavailable: ' . $e->getMessage());

            throw new RuntimeException('Duo could not be reached. Please try another method or contact an administrator.');
        }

        session()->set(self::SESSION_KEY, [
            'state'   => $state,
            'user'    => $userId,
            'name'    => strtolower($username),
            'purpose' => $purpose,
            'expires' => time() + self::STATE_TTL,
        ]);

        return $url;
    }

    /**
     * Finishes the round trip. Returns the purpose it was started for, or throws.
     *
     * @return 'login'|'enrol'
     */
    public function complete(string $code, string $state, int $expectedUserId): string
    {
        $flow = session()->get(self::SESSION_KEY);
        session()->remove(self::SESSION_KEY);   // single use whatever happens

        if (! is_array($flow) || $flow['expires'] < time() || $state === '' || ! hash_equals((string) $flow['state'], $state) || (int) $flow['user'] !== $expectedUserId) {
            throw new RuntimeException('Your Duo session expired. Please try again.');
        }

        try {
            $token = $this->client()->exchangeAuthorizationCodeFor2FAResult($code, (string) $flow['name']);
        } catch (DuoException $e) {
            log_message('error', 'Duo verification failed: ' . $e->getMessage());

            throw new RuntimeException('Duo verification failed. Please try again.');
        }

        // The library verifies signature, audience and username; the decision is ours to check.
        if (($token['auth_result']['result'] ?? '') !== 'allow') {
            throw new RuntimeException('Duo did not approve the sign-in.');
        }

        return (string) $flow['purpose'];
    }

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $config = config(Duo::class);

        if (! $config->isConfigured()) {
            throw new RuntimeException('Duo is not configured.');
        }

        return new Client($config->clientId, $config->clientSecret, $config->apiHost, $config->callbackUrl());
    }
}

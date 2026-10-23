<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\Entra\EntraConfig;
use App\Services\Entra\EntraException;
use App\Services\Entra\EntraLogin;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * "Sign in with Microsoft". Does nothing at all unless an administrator has switched it on and .env
 * is configured. Local email/password sign-in is unaffected either way.
 */
class MicrosoftAuth extends BaseController
{
    private const SESSION_KEY = 'entra_flow';
    private const MAX_AGE     = 600; // seconds to complete the Microsoft sign-in page

    public function start(): RedirectResponse
    {
        if (! EntraConfig::available()) {
            return $this->refuse('Microsoft sign-in is not available.');
        }
        if (auth()->loggedIn()) {
            return redirect()->to('/');
        }

        try {
            $begin = service('entra')->begin();
        } catch (EntraException $e) {
            log_message('error', 'Microsoft sign-in could not start: {m}', ['m' => $e->getMessage()]);

            return $this->refuse('Microsoft sign-in is not available right now.');
        }

        session()->set(self::SESSION_KEY, [
            'state' => $begin['state'], 'nonce' => $begin['nonce'], 'pkce' => $begin['pkce'], 'at' => time(),
        ]);

        return redirect()->to($begin['url']);
    }

    public function callback(): RedirectResponse
    {
        if (! EntraConfig::available()) {
            return $this->refuse('Microsoft sign-in is not available.');
        }

        // One flow, one use: whatever happens next, the stored values are gone.
        $flow = session()->get(self::SESSION_KEY);
        session()->remove(self::SESSION_KEY);

        $query = $this->request->getGet();
        if (isset($query['error'])) {
            log_message('warning', 'Microsoft sign-in returned an error: {e} ({d})', [
                'e' => (string) $query['error'], 'd' => mb_substr((string) ($query['error_description'] ?? ''), 0, 300),
            ]);

            return $this->deny('cancelled', null, 'Microsoft sign-in was cancelled or refused.');
        }

        if (! is_array($flow) || (time() - (int) ($flow['at'] ?? 0)) > self::MAX_AGE
            || ! isset($query['state'], $query['code']) || ! is_string($query['state']) || ! is_string($query['code'])
            || ! hash_equals((string) $flow['state'], $query['state'])) {
            return $this->deny('state', null, 'That sign-in attempt expired or was not valid. Please try again.');
        }

        try {
            $identity = service('entra')->complete($query['code'], (string) $flow['nonce'], $flow['pkce'] ?: null);
        } catch (EntraException $e) {
            log_message('error', 'Microsoft sign-in failed: {m}', ['m' => $e->getMessage()]);

            return $this->deny('token', null, 'Microsoft sign-in did not work. Please try again or use your email and password.');
        }

        $login  = new EntraLogin();
        $result = $login->findUser($identity);

        if ($result['user'] === null) {
            return $this->deny((string) $result['reason'], $identity->email, match ($result['reason']) {
                EntraLogin::INACTIVE => 'Your account is not active. Ask an administrator for help.',
                EntraLogin::CONFLICT => 'This email address is linked to a different Microsoft account. Ask an administrator for help.',
                default              => 'Your Microsoft account has not been set up for this system. Ask an administrator to add you.',
            });
        }

        $user = $result['user'];
        if ($result['needs_link']) {
            $login->link((int) $user->id, $identity);
        }

        auth()->login($user);
        (new AuditLogger())->log('auth.microsoft_login', (int) $user->id, 'user', (int) $user->id, ['linked_now' => $result['needs_link']]);

        return redirect()->to('/')->with('message', 'Signed in with Microsoft.');
    }

    private function deny(string $reason, ?string $email, string $message): RedirectResponse
    {
        (new AuditLogger())->log('auth.microsoft_denied', null, null, null, ['reason' => $reason, 'email' => $email]);

        return $this->refuse($message);
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->to('login')->with('error', $message);
    }
}

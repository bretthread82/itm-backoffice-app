<?php

namespace App\Controllers;

use App\Core\Entra\EntraClient;
use App\Core\Entra\EntraUserService;
use App\Models\StaffProfileModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Models\LoginModel;
use Config\Entra;
use Throwable;

/**
 * "Sign in with Microsoft": OIDC authorization code flow with PKCE.
 * Every route here is inert unless Entra is enabled and configured.
 */
class EntraAuth extends BaseController
{
    private const SESSION_KEY = 'entra_auth';
    private const TTL         = 600;

    public function login(): RedirectResponse
    {
        if (! $this->ready()) {
            return $this->fail('Microsoft sign-in is not available.');
        }

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $flow     = [
            'state'    => bin2hex(random_bytes(16)),
            'nonce'    => bin2hex(random_bytes(16)),
            'verifier' => $verifier,
            'expires'  => time() + self::TTL,
        ];

        session()->set(self::SESSION_KEY, $flow);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->to((new EntraClient())->authorizeUrl($flow['state'], $flow['nonce'], $challenge));
    }

    public function callback(): RedirectResponse
    {
        if (! $this->ready()) {
            return $this->fail('Microsoft sign-in is not available.');
        }

        // Single use: the flow state is consumed whatever happens next.
        $flow = session()->get(self::SESSION_KEY);
        session()->remove(self::SESSION_KEY);

        if ($this->request->getGet('error')) {
            // error_description is user-controlled text from the URL: log, do not display.
            log_message('notice', 'Entra sign-in returned an error: {e}', ['e' => (string) $this->request->getGet('error')]);

            return $this->fail('Microsoft sign-in was cancelled or refused.');
        }

        $state = (string) $this->request->getGet('state');
        $code  = (string) $this->request->getGet('code');

        if (! is_array($flow) || $flow['expires'] < time() || $state === '' || $code === '' || ! hash_equals($flow['state'], $state)) {
            return $this->fail('Your sign-in session expired. Please try again.');
        }

        try {
            $client = new EntraClient();
            $tokens = $client->exchangeCode($code, $flow['verifier']);
            $claims = $client->validateIdToken((string) ($tokens['id_token'] ?? ''), $flow['nonce']);
        } catch (Throwable $e) {
            log_message('error', 'Entra sign-in failed: ' . $e->getMessage());

            return $this->fail('Microsoft sign-in failed. Please try again or contact an administrator.');
        }

        $email = strtolower(trim((string) ($claims['email'] ?? $claims['preferred_username'] ?? '')));
        $name  = trim((string) ($claims['name'] ?? ''));
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $result = (new EntraUserService())->resolve([
            'oid'          => (string) $claims['oid'],
            'email'        => $email,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => $name,
        ], config(Entra::class)->jitProvisioning);

        $login = model(LoginModel::class);
        $ip    = $this->request->getIPAddress();
        $agent = (string) $this->request->getUserAgent();

        if ($result === null) {
            $login->recordLoginAttempt('entra', $email ?: (string) $claims['oid'], false, $ip, $agent);

            return $this->fail('Your Microsoft account does not have access to this application. Contact an administrator.');
        }

        $user    = auth()->getProvider()->findById($result['user_id']);
        $profile = model(StaffProfileModel::class)->find($result['user_id']);

        if (! $user || ! $profile || $profile['status'] !== 'active' || $user->isBanned()) {
            $login->recordLoginAttempt('entra', $email, false, $ip, $agent, $result['user_id']);

            return $this->fail('Your account is not active. Contact an administrator.');
        }

        auth('session')->login($user);
        $login->recordLoginAttempt('entra', $user->email ?? $email, true, $ip, $agent, $user->id);
        service('audit')->log('auth.entra_login', 'user', $user->id, null, null, (int) $user->id);

        return redirect()->to(config('Auth')->loginRedirect())->withCookies();
    }

    private function ready(): bool
    {
        return config(Entra::class)->isReady();
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->to(url_to('login'))->with('error', $message);
    }
}

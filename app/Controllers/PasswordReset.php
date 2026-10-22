<?php

namespace App\Controllers;

use App\Core\Deferred;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * Self-service "forgot password". Public (no session needed), so every action
 * here is rate-limited and none reveals whether an email address has an account.
 */
class PasswordReset extends BaseController
{
    private const SESSION_KEY = 'pw_reset';

    public function forgot(): string
    {
        return view('auth/forgot', [
            'title'     => 'Reset your password',
            'subtitle'  => 'We will email you a reset link',
            'available' => service('passwordReset')->isAvailable(),
        ]);
    }

    public function sendLink(): RedirectResponse
    {
        if (! service('passwordReset')->isAvailable()) {
            return redirect()->to('forgot-password');
        }

        if (! $this->allow('pwforgot', 5, 900)) {
            return redirect()->to('forgot-password')->with('error', 'Too many requests. Please try again in a few minutes.');
        }

        $email = trim((string) $this->request->getPost('email'));
        $ip    = $this->request->getIPAddress();

        // All the work (lookup, token, email) happens after the response, so timing
        // gives nothing away. The reply is identical whether or not the account exists.
        if ($email !== '' && strlen($email) <= 254) {
            Deferred::add(static fn () => service('passwordReset')->requestReset($email, $ip));
        }

        return redirect()->to('login')->with('message', 'If that email address belongs to an account, a reset link is on its way. It expires in ' . config(\Config\Backoffice::class)->passwordResetTtlMinutes . ' minutes.');
    }

    /**
     * Landing page for the emailed link. The token is moved into the session and
     * dropped from the URL so it cannot leak through history, logs or Referer.
     */
    public function open(string $token): RedirectResponse
    {
        if (! $this->allow('pwopen', 20, 900)) {
            return redirect()->to('forgot-password')->with('error', 'Too many attempts. Please try again later.');
        }

        $hash = service('passwordReset')->validate($token);

        if ($hash === null) {
            return redirect()->to('forgot-password')->with('error', 'That reset link is invalid or has expired. Please request a new one.');
        }

        session()->set(self::SESSION_KEY, $hash);

        return redirect()->to('reset-password');
    }

    public function form(): string|RedirectResponse
    {
        if (! session()->get(self::SESSION_KEY)) {
            return redirect()->to('forgot-password');
        }

        return view('auth/reset', ['title' => 'Choose a new password', 'subtitle' => 'Pick something long and unique']);
    }

    public function reset(): RedirectResponse
    {
        $hash = session()->get(self::SESSION_KEY);

        if (! $hash) {
            return redirect()->to('forgot-password')->with('error', 'Your reset session expired. Please request a new link.');
        }

        if (! $this->allow('pwreset', 10, 900)) {
            return redirect()->to('forgot-password')->with('error', 'Too many attempts. Please try again later.');
        }

        $min = config(\Config\Auth::class)->minimumPasswordLength;

        if (! $this->validate([
            'password'         => "required|min_length[{$min}]|max_byte[72]",
            'password_confirm' => 'required|matches[password]',
        ])) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        $password = (string) $this->request->getPost('password');

        // Shield's strong_password rule cannot be used here: the visitor is anonymous, so it
        // has no account to compare against. Check against the account the link belongs to.
        if ($reason = service('passwordReset')->weaknessReason($hash, $password)) {
            return redirect()->back()->with('errors', [$reason]);
        }

        try {
            service('passwordReset')->reset($hash, $password);
        } catch (RuntimeException $e) {
            session()->remove(self::SESSION_KEY);

            return redirect()->to('forgot-password')->with('error', $e->getMessage());
        }

        session()->remove(self::SESSION_KEY);

        return redirect()->to('login')->with('message', 'Your password has been changed. You can sign in now.');
    }

    private function allow(string $bucket, int $max, int $seconds): bool
    {
        return service('throttler')->check($bucket . '_' . md5($this->request->getIPAddress()), $max, $seconds);
    }
}

<?php

namespace App\Controllers;

use App\Auth\MfaAction;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Config\Mfa;
use RuntimeException;

/**
 * Second-factor challenge and enrolment.
 *
 * Two contexts, never mixed up with request data:
 *   - PENDING: the password was accepted and Shield is holding the login at
 *     MfaAction. The user is not signed in yet.
 *   - SIGNED IN (profile): a signed-in user who recently re-confirmed their
 *     password adds a factor.
 * The user is always taken from Shield's session state, never from the request.
 */
class MfaController extends BaseController
{
    private bool $pending = false;

    // ---- Challenge --------------------------------------------------------

    public function challenge(): string|RedirectResponse
    {
        $user = $this->pendingUser();

        if (! $user instanceof User) {
            return $this->toLogin();
        }

        $policy = service('mfaPolicy');
        $mfa    = service('mfa');

        // The admin may have switched MFA off while this sign-in was waiting.
        if (! $policy->enabled()) {
            return $this->finish($user, 'none', false);
        }

        if ($policy->needsEnrolment((int) $user->id)) {
            return redirect()->to('mfa/enrol');
        }

        $usable = $mfa->usableMethods((int) $user->id);

        if ($usable === []) {
            return $this->finish($user, 'none', false);
        }

        $wanted = (string) $this->request->getGet('method');
        $method = ($wanted === 'recovery' || in_array($wanted, $usable, true)) ? $wanted : $mfa->preferredMethod((int) $user->id);

        return view('mfa/challenge', [
            'title'     => 'Verify it is you',
            'subtitle'  => 'Two-step verification',
            'pending'   => true,
            'usable'    => $usable,
            'method'    => $method,
            'email'     => $this->maskEmail((string) $user->email),
            'resend'    => service('emailCode')->secondsUntilResend((int) $user->id),
            'canRemember' => $policy->rememberDays() > 0,
            'rememberDays' => $policy->rememberDays(),
            'hasRecovery' => $mfa->recoveryCodesRemaining((int) $user->id) > 0,
        ]);
    }

    public function verify(): RedirectResponse
    {
        $user = $this->pendingUser();

        if (! $user instanceof User) {
            return $this->toLogin();
        }

        $id     = (int) $user->id;
        $mfa    = service('mfa');
        $method = (string) $this->request->getPost('method');
        $code   = trim((string) $this->request->getPost('code'));

        if ($mfa->isLockedOut($id)) {
            return $this->abandon('Too many incorrect codes. Please try again in ' . config(Mfa::class)->lockoutMinutes . ' minutes.');
        }

        $usable = $mfa->usableMethods($id);

        $ok = match (true) {
            $method === 'totp' && in_array('totp', $usable, true)   => service('totp')->verify($id, $code),
            $method === 'email' && in_array('email', $usable, true) => service('emailCode')->verify($id, $code),
            $method === 'recovery'                                  => $mfa->useRecoveryCode($id, $code),
            default                                                 => false,
        };

        if (! $ok) {
            return $this->failed($id, $method);
        }

        return $this->finish($user, $method, (bool) $this->request->getPost('remember'));
    }

    public function sendEmail(): RedirectResponse
    {
        $user = $this->pendingUser();

        if (! $user instanceof User) {
            return $this->toLogin();
        }

        if (! in_array('email', service('mfa')->usableMethods((int) $user->id), true)) {
            return redirect()->to('mfa/challenge');
        }

        return $this->emailFlash((int) $user->id, (string) $user->email, 'mfa/challenge?method=email');
    }

    public function cancel(): RedirectResponse
    {
        if ($this->pendingUser() instanceof User) {
            auth()->logout();
        }

        return $this->toLogin();
    }

    // ---- Enrolment --------------------------------------------------------

    public function enrol(): string|RedirectResponse
    {
        $user = $this->enrolUser();

        if (! $user instanceof User) {
            return $this->denied();
        }

        $enabled = array_values(array_diff(service('mfaPolicy')->enabledMethods(), array_keys(service('mfa')->methods((int) $user->id))));

        return view('mfa/enrol', [
            'title' => 'Set up two-step verification', 'subtitle' => 'Choose how you want to verify',
            'pending' => $this->pending, 'step' => 'choose', 'options' => $enabled,
        ]);
    }

    public function enrolTotp(): string|RedirectResponse
    {
        $user = $this->enrolUser();

        if (! $user instanceof User || ! service('mfaPolicy')->switchedOn('totp') || ! service('mfaPolicy')->prerequisitesMet('totp')) {
            return $this->denied();
        }

        $totp = service('totp');
        $mfa  = service('mfa');

        if (strtolower($this->request->getMethod()) === 'post') {
            $secret = $this->sessionSecret();

            if ($secret === null) {
                return redirect()->to('mfa/enrol/totp')->with('error', 'Your setup session expired. Scan a new code.');
            }

            $step = $totp->matchStep($secret, (string) $this->request->getPost('code'));

            if ($step === null) {
                return redirect()->to('mfa/enrol/totp')->with('error', 'That code did not match. Check your phone\'s clock and try the next code.');
            }

            session()->remove('mfa_totp_secret');
            // The step used to prove possession is burned so it cannot be replayed at sign-in.
            $mfa->addMethod((int) $user->id, 'totp', $secret, $step);

            return $this->enrolled($user, 'totp', 'Authenticator app added');
        }

        $secret = $this->sessionSecret() ?? $this->newSessionSecret();

        return view('mfa/enrol', [
            'title' => 'Set up an authenticator app', 'subtitle' => 'Scan the code, then enter the 6 digits',
            'pending' => $this->pending, 'step' => 'totp',
            'qr' => $totp->qrDataUri($totp->provisioningUri($secret, (string) $user->email)),
            'secret' => trim(chunk_split($secret, 4, ' ')),
        ]);
    }

    public function enrolEmail(): string|RedirectResponse
    {
        $user = $this->enrolUser();

        if (! $user instanceof User || ! service('mfaPolicy')->switchedOn('email') || ! service('mfaPolicy')->prerequisitesMet('email')) {
            return $this->denied();
        }

        $id = (int) $user->id;

        if (strtolower($this->request->getMethod()) === 'post') {
            $action = (string) $this->request->getPost('action');

            if ($action === 'send') {
                return $this->emailFlash($id, (string) $user->email, 'mfa/enrol/email');
            }

            if (! service('emailCode')->verify($id, trim((string) $this->request->getPost('code')))) {
                return redirect()->to('mfa/enrol/email')->with('error', 'That code is wrong or has expired.');
            }

            service('mfa')->addMethod($id, 'email');

            return $this->enrolled($user, 'email', 'Email codes turned on');
        }

        return view('mfa/enrol', [
            'title' => 'Email me a code', 'subtitle' => 'We will email a code to confirm',
            'pending' => $this->pending, 'step' => 'email', 'email' => $this->maskEmail((string) $user->email),
            'resend' => service('emailCode')->secondsUntilResend($id),
        ]);
    }

    // ---- Duo --------------------------------------------------------------

    public function duoStart(): RedirectResponse
    {
        $user = $this->pending() ? $this->pendingUser() : $this->enrolUser();

        if (! $user instanceof User) {
            return $this->denied();
        }

        $policy = service('mfaPolicy');

        if (! $policy->switchedOn('duo') || ! $policy->prerequisitesMet('duo')) {
            return redirect()->to($this->pending ? 'mfa/challenge' : 'profile/mfa')->with('error', 'Duo is not available.');
        }

        // Carry the "remember this device" tick through the Duo round trip.
        if ($this->pending) {
            session()->set('mfa_remember', (bool) $this->request->getGet('remember'));
        }

        // Already enrolled = a sign-in check; otherwise this proves a new enrolment.
        $purpose = service('mfa')->hasMethod((int) $user->id, 'duo') ? 'login' : 'enrol';

        // A pending sign-in may not add a Duo factor unless it has no other factor (see enrolUser()).
        if ($this->pending && $purpose === 'enrol' && ! $policy->needsEnrolment((int) $user->id)) {
            return redirect()->to('mfa/challenge')->with('error', 'Duo is not set up for your account.');
        }

        try {
            return redirect()->to(service('duo')->start((int) $user->id, (string) $user->email, $purpose));
        } catch (RuntimeException $e) {
            return redirect()->to($this->pending ? 'mfa/challenge' : 'profile/mfa')->with('error', $e->getMessage());
        }
    }

    public function duoCallback(): RedirectResponse
    {
        $user = $this->pending() ? $this->pendingUser() : $this->enrolUser();

        if (! $user instanceof User) {
            return $this->denied();
        }

        $back = $this->pending ? 'mfa/challenge' : 'profile/mfa';

        if ($this->request->getGet('error')) {
            log_message('notice', 'Duo returned an error: {e}', ['e' => (string) $this->request->getGet('error')]);

            return redirect()->to($back)->with('error', 'Duo verification was cancelled or refused.');
        }

        $id = (int) $user->id;

        if (service('mfa')->isLockedOut($id)) {
            return $this->abandon('Too many failed attempts. Please try again later.');
        }

        try {
            $purpose = service('duo')->complete((string) $this->request->getGet('duo_code'), (string) $this->request->getGet('state'), $id);
        } catch (RuntimeException $e) {
            service('mfa')->recordFailure($id);

            return redirect()->to($back)->with('error', $e->getMessage());
        }

        if ($purpose === 'enrol') {
            if ($this->pending && ! service('mfaPolicy')->needsEnrolment($id)) {
                return redirect()->to('mfa/challenge')->with('error', 'Duo is not set up for your account.');
            }

            service('mfa')->addMethod($id, 'duo');

            return $this->enrolled($user, 'duo', 'Duo added');
        }

        return $this->pending ? $this->finish($user, 'duo', (bool) session()->get('mfa_remember')) : redirect()->to('profile/mfa');
    }

    // ---- Shared -----------------------------------------------------------

    /**
     * Completes a successful sign-in: hands Shield its marker back so the
     * session becomes fully logged in, and optionally trusts this browser.
     */
    private function finish(User $user, string $method, bool $remember, ?string $redirectTo = null): RedirectResponse
    {
        $auth     = auth('session')->getAuthenticator();
        $identity = model(UserIdentityModel::class)->getIdentityByType($user, MfaAction::TYPE);

        if (! $identity) {
            return $this->toLogin();
        }

        $id     = (int) $user->id;
        $mfa    = service('mfa');
        $policy = service('mfaPolicy');

        $mfa->clearFailures($id);
        session()->remove(['mfa_attempts', 'mfa_remember']);

        if (! $auth->checkAction($identity, $identity->secret)) {
            return $this->toLogin();
        }

        $response = redirect()->to($redirectTo ?? config('Auth')->loginRedirect());

        if ($method === 'recovery') {
            $left = $mfa->recoveryCodesRemaining($id);
            $mfa->announce($id, 'auth.mfa_recovery_used', 'A recovery code was used to sign in', $left . ' recovery code(s) left. If this was not you, change your password and tell an administrator.');
            $response->with('warning', $left === 0 ? 'That was your last recovery code. Generate new ones in your security settings.' : "Recovery code accepted. {$left} left.");
        } elseif ($method !== 'none') {
            service('audit')->log('auth.mfa_passed', 'user', $id, null, ['method' => $method], $id);
        }

        if ($remember && $method !== 'recovery' && $method !== 'none' && $policy->rememberDays() > 0) {
            $response->setCookie($mfa->issueTrustedDevice($id, $policy->rememberDays()));
        }

        return $response;
    }

    /**
     * A factor was just added. In a sign-in this finishes the login; either way
     * the first factor comes with recovery codes, shown once.
     */
    private function enrolled(User $user, string $type, string $title): RedirectResponse
    {
        $id  = (int) $user->id;
        $mfa = service('mfa');

        $firstFactor = $mfa->recoveryCodesRemaining($id) === 0;

        if ($firstFactor) {
            session()->set('mfa_new_codes', $mfa->generateRecoveryCodes($id));
        }

        $mfa->announce($id, 'auth.mfa_enrolled', $title, 'If you did not do this, contact an administrator immediately.');

        $next = $firstFactor ? 'profile/mfa/codes' : 'profile/mfa';

        if ($this->pending) {
            return $this->finish($user, $type, false, $next);
        }

        return redirect()->to($next)->with('message', $title . '.');
    }

    private function failed(int $userId, string $method): RedirectResponse
    {
        $mfa = service('mfa');
        $mfa->recordFailure($userId);
        service('audit')->log('auth.mfa_failed', 'user', $userId, null, ['method' => $method], $userId);

        $attempts = (int) session()->get('mfa_attempts') + 1;
        session()->set('mfa_attempts', $attempts);

        if ($attempts >= config(Mfa::class)->maxAttempts) {
            return $this->abandon('Too many incorrect codes. Please sign in again.');
        }

        $left = config(Mfa::class)->maxAttempts - $attempts;

        return redirect()->to('mfa/challenge?method=' . urlencode($method))->with('error', "That code is not correct. {$left} attempt(s) left.");
    }

    /**
     * Throws away the half-finished sign-in; the password is needed again.
     */
    private function abandon(string $message): RedirectResponse
    {
        auth()->logout();

        return redirect()->to(url_to('login'))->with('error', $message);
    }

    private function emailFlash(int $userId, string $address, string $back): RedirectResponse
    {
        $result = service('emailCode')->send($userId, $address);

        return redirect()->to($back)->with(
            $result === 'sent' ? 'message' : 'error',
            match ($result) {
                'sent'     => 'We emailed you a code to ' . $this->maskEmail($address) . '.',
                'cooldown' => 'A code was sent a moment ago. Please wait before asking for another.',
                default    => 'We could not send the email. Try another method or contact an administrator.',
            },
        );
    }

    // ---- Context ----------------------------------------------------------

    /**
     * The user whose sign-in is waiting for MFA, or null.
     */
    private function pendingUser(): ?User
    {
        $authenticator = auth('session')->getAuthenticator();

        if ($authenticator->isPending() && $authenticator->getAction() instanceof MfaAction) {
            $this->pending = true;

            return $authenticator->getPendingUser();
        }

        return null;
    }

    private function pending(): bool
    {
        return $this->pendingUser() instanceof User;
    }

    /**
     * Pending sign-in, or a signed-in user who confirmed their password recently.
     */
    private function enrolUser(): ?User
    {
        $pending = $this->pendingUser();

        if ($pending instanceof User) {
            // SECURITY: a half-signed-in user has only proved their password. Letting them add a
            // factor while they already have one would let a password thief replace it and finish
            // signing in. Enrolment is only offered when they have nothing to verify with.
            return service('mfaPolicy')->needsEnrolment((int) $pending->id) ? $pending : null;
        }

        return auth()->loggedIn() && service('mfa')->reauthFresh() ? auth()->user() : null;
    }

    private function denied(): RedirectResponse
    {
        if ($this->pendingUser() instanceof User) {
            return redirect()->to('mfa/challenge');
        }

        if (auth()->loggedIn()) {
            return redirect()->to('profile/mfa')->with('error', 'Please confirm your password first.');
        }

        return $this->toLogin();
    }

    private function toLogin(): RedirectResponse
    {
        return redirect()->to(url_to('login'));
    }

    private function sessionSecret(): ?string
    {
        $stored = session()->get('mfa_totp_secret');

        try {
            return is_string($stored) ? service('mfa')->decrypt($stored) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function newSessionSecret(): string
    {
        $secret = service('totp')->newSecret();
        session()->set('mfa_totp_secret', service('mfa')->encrypt($secret));

        return $secret;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return ($local === '' ? '' : mb_substr($local, 0, 1)) . '***@' . $domain;
    }
}

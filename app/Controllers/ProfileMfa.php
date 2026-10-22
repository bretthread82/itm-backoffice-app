<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Config\Mfa;

/**
 * A signed-in user's own two-step verification settings. Always acts on the
 * signed-in user; changes need the password re-confirmed within the last few
 * minutes, so a hijacked session cannot quietly swap factors.
 */
class ProfileMfa extends BaseController
{
    public function index(): string
    {
        $user   = auth()->user();
        $id     = (int) $user->id;
        $policy = service('mfaPolicy');
        $mfa    = service('mfa');
        $source = db_connect()->table('staff_profiles')->where('user_id', $id)->get()->getRow('source');

        $methods = $mfa->methods($id);
        $rows    = [];

        foreach (Mfa::METHODS as $type) {
            $rows[$type] = [
                'enrolled'  => isset($methods[$type]),
                'switchedOn' => $policy->switchedOn($type) && $policy->prerequisitesMet($type),
                'last_used' => $methods[$type]['last_used_at'] ?? null,
            ];
        }

        return view('profile/mfa', [
            'title'      => 'Two-step verification',
            'active'     => 'mfa',
            'isEntra'    => $source === 'entra',
            'mfaEnabled' => $policy->enabled(),
            'required'   => $policy->isRequiredFor($id),
            'rows'       => $rows,
            'recovery'   => $mfa->recoveryCodesRemaining($id),
            'devices'    => $mfa->deviceCount($id),
            'reauth'     => $mfa->reauthFresh(),
            'reauthMin'  => config(Mfa::class)->reauthMinutes,
        ]);
    }

    public function confirm(): RedirectResponse
    {
        $user = auth()->user();

        if (! service('throttler')->check('mfaconfirm_' . $user->id, 5, 300)) {
            return redirect()->back()->with('error', 'Too many attempts. Try again in a few minutes.');
        }

        $check = auth()->check(['email' => $user->email, 'password' => (string) $this->request->getPost('current_password')]);

        if (! $check->isOK()) {
            return redirect()->back()->with('error', 'That password is not correct.');
        }

        service('mfa')->markReauth();

        return redirect()->to('profile/mfa');
    }

    public function disable(string $type): RedirectResponse
    {
        $user = auth()->user();
        $id   = (int) $user->id;
        $mfa  = service('mfa');

        if (! service('mfa')->reauthFresh()) {
            return redirect()->to('profile/mfa')->with('error', 'Please confirm your password first.');
        }

        if (! in_array($type, Mfa::METHODS, true) || ! $mfa->hasMethod($id, $type)) {
            return redirect()->to('profile/mfa')->with('error', 'That method is not set up.');
        }

        // Someone who must use MFA cannot remove their last working factor.
        $remaining = array_diff($mfa->usableMethods($id), [$type]);

        if ($remaining === [] && service('mfaPolicy')->enabled() && service('mfaPolicy')->isRequiredFor($id)) {
            return redirect()->to('profile/mfa')->with('error', 'Your role requires two-step verification, so you cannot remove your last method. Add another one first.');
        }

        $mfa->removeMethod($id, $type);
        $mfa->announce($id, 'auth.mfa_removed', 'A two-step method was removed', 'If you did not do this, contact an administrator immediately.');

        return redirect()->to('profile/mfa')->with('message', 'Method removed.');
    }

    public function regenerate(): RedirectResponse
    {
        $id = (int) auth()->id();
        $mfa = service('mfa');

        if (! $mfa->reauthFresh()) {
            return redirect()->to('profile/mfa')->with('error', 'Please confirm your password first.');
        }

        if ($mfa->methods($id) === []) {
            return redirect()->to('profile/mfa')->with('error', 'Set up a method first.');
        }

        session()->set('mfa_new_codes', $mfa->generateRecoveryCodes($id));
        $mfa->announce($id, 'auth.mfa_codes_regenerated', 'New recovery codes were generated', 'Your old recovery codes no longer work.');

        return redirect()->to('profile/mfa/codes');
    }

    public function codes(): string|RedirectResponse
    {
        $codes = session()->get('mfa_new_codes');
        session()->remove('mfa_new_codes');   // shown once

        if (! is_array($codes) || $codes === []) {
            return redirect()->to('profile/mfa');
        }

        return view('profile/mfa_codes', ['title' => 'Save your recovery codes', 'active' => 'mfa', 'codes' => $codes]);
    }

    public function forgetDevices(): RedirectResponse
    {
        service('mfa')->revokeDevices((int) auth()->id());
        service('audit')->log('auth.mfa_devices_forgotten', 'user', auth()->id());

        return redirect()->to('profile/mfa')->with('message', 'All remembered devices were signed out of two-step trust.');
    }
}

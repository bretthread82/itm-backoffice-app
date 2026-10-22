<?php

namespace App\Auth;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Authentication\Actions\ConditionalActionInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * Pauses a password sign-in until a second factor is verified.
 *
 * Shield starts this action after the password is accepted, but only when
 * appliesTo() says so (the admin's MFA policy). While it is pending the user
 * is NOT logged in; Shield's session filter keeps sending them to show().
 * The actual challenge/enrolment screens live in MfaController; this class is
 * the thin bridge Shield needs. Sign-ins that do not go through
 * Session::attempt() (Microsoft Entra SSO) never start login actions, which is
 * how Entra users rely on Entra's own MFA.
 */
class MfaAction implements ActionInterface, ConditionalActionInterface
{
    public const TYPE = 'mfa_login';

    public function appliesTo(User $user): bool
    {
        return service('mfaPolicy')->appliesTo($user);
    }

    public function show(): RedirectResponse
    {
        return redirect()->to('mfa/challenge');
    }

    public function handle(IncomingRequest $request): RedirectResponse
    {
        return redirect()->to('mfa/challenge');
    }

    public function verify(IncomingRequest $request): RedirectResponse
    {
        return redirect()->to('mfa/challenge');
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * Creates the marker identity that Shield uses to remember "this login is
     * waiting for MFA". Its secret never leaves the server; MfaController
     * presents it back to Shield only after a factor has been verified.
     */
    public function createIdentity(User $user): string
    {
        $identities = model(UserIdentityModel::class);
        $identities->deleteIdentitiesByType($user, self::TYPE);

        return $identities->createCodeIdentity(
            $user,
            ['type' => self::TYPE, 'name' => 'login', 'extra' => 'Multi-factor authentication required'],
            static fn (): string => bin2hex(random_bytes(16)),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * Sends a user a single-use sign-in link (Shield magic link) and forces them to set a
 * password when they arrive. Used for new-user invitations and resends.
 */
class InvitationService
{
    public function __construct(private ?AuditLogger $audit = null)
    {
        $this->audit ??= new AuditLogger();
    }

    /**
     * Creates the link and returns it. Does not send anything, so it is easy to test.
     */
    public function createLink(User $user): string
    {
        helper('text');

        $identities = model(UserIdentityModel::class);
        $identities->deleteIdentitiesByType($user, Session::ID_TYPE_MAGIC_LINK);

        $token = random_string('crypto', 20);
        $identities->insert([
            'user_id' => $user->id,
            'type'    => Session::ID_TYPE_MAGIC_LINK,
            'secret'  => $token,
            'expires' => Time::now()->addSeconds(setting('Auth.magicLinkLifetime')),
        ]);

        $user->forcePasswordReset();

        return url_to('verify-magic-link') . '?token=' . $token;
    }

    /**
     * Emails an invitation. Returns false when the user has no email or sending fails.
     */
    public function send(int $userId, ?int $actorId = null): bool
    {
        $user = model(UserModel::class)->findById($userId);

        if (! $user instanceof User || $user->email === null || $user->email === '') {
            return false;
        }

        $link = $this->createLink($user);

        helper('email');
        $email = emailer(['mailType' => 'html'])
            ->setFrom(setting('Email.fromEmail'), setting('Email.fromName') ?? '');
        $email->setTo($user->email);
        $email->setSubject('Your Maloney Group Invoicing account');
        $email->setMessage(view('emails/invitation', [
            'name'    => $user->display_name ?: $user->username,
            'link'    => $link,
            'expires' => setting('Auth.magicLinkLifetime') / DAY,
        ]));

        $sent = $email->send(false);
        if ($sent === false) {
            log_message('error', 'Invitation email failed for user ' . $user->id . ': ' . $email->printDebugger(['headers']));
        }
        $email->clear();

        $this->audit->log($sent ? 'user.invited' : 'user.invite_failed', $actorId, 'user', $userId);

        return $sent;
    }
}

<?php

namespace App\Core;

use CodeIgniter\Shield\Models\UserModel;
use Config\Backoffice;
use RuntimeException;

/**
 * Email-link password reset for local accounts.
 *
 *  - The emailed token is 256 bits of randomness; only its SHA-256 is stored.
 *  - Links are single-use and expire (Backoffice::$passwordResetTtlMinutes).
 *  - requestReset() never reveals whether an account exists; it does nothing
 *    for unknown, inactive, banned or Entra-managed accounts.
 *  - A new request supersedes earlier links, and requests per account are rate-limited.
 */
class PasswordResetService
{
    public function isAvailable(): bool
    {
        return service('mailer')->isConfigured();
    }

    /**
     * Creates and emails a reset link if (and only if) the address belongs to a
     * local, active account. Silent in every other case.
     */
    public function requestReset(string $email, ?string $ip = null): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $config = config(Backoffice::class);
        $db     = db_connect();
        $email  = strtolower(trim($email));

        $row = $db->table('auth_identities ai')
            ->select('ai.user_id, sp.first_name, sp.status, sp.source')
            ->join('users u', 'u.id = ai.user_id AND u.deleted_at IS NULL')
            ->join('staff_profiles sp', 'sp.user_id = u.id AND sp.deleted_at IS NULL')
            ->where('ai.type', 'email_password')->where('ai.secret', $email)
            ->get()->getRowArray();

        // Housekeeping: drop long-dead rows.
        $db->table('password_resets')->where('created_at <', date('Y-m-d H:i:s', strtotime('-7 days')))->delete();

        if (! $row || $row['status'] !== 'active' || $row['source'] !== 'local') {
            return;
        }

        $userId = (int) $row['user_id'];
        $user   = auth()->getProvider()->findById($userId);

        if (! $user || $user->isBanned()) {
            return;
        }

        $recent = $db->table('password_resets')->where('user_id', $userId)
            ->where('created_at >', date('Y-m-d H:i:s', strtotime('-1 hour')))->countAllResults();

        if ($recent >= $config->passwordResetMaxPerHour) {
            log_message('notice', 'Password reset rate limit reached for user {id}.', ['id' => $userId]);

            return;
        }

        // Supersede any earlier links.
        $now = date('Y-m-d H:i:s');
        $db->table('password_resets')->where('user_id', $userId)->where('used_at', null)->update(['used_at' => $now]);

        $token = bin2hex(random_bytes(32));
        $db->table('password_resets')->insert([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'ip'         => $ip,
            'expires_at' => date('Y-m-d H:i:s', time() + $config->passwordResetTtlMinutes * 60),
            'created_at' => $now,
        ]);

        service('audit')->log('auth.password_reset_requested', 'user', $userId, null, null, $userId);

        service('mailer')->send($email, 'Reset your Back Office password', view('emails/password_reset', [
            'name'    => $row['first_name'],
            'url'     => site_url('reset-password/' . $token),
            'minutes' => $config->passwordResetTtlMinutes,
        ]));
    }

    /**
     * @return string|null SHA-256 of the token if it is a currently valid link
     */
    public function validate(string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);

        return $this->findActive($hash) ? $hash : null;
    }

    /**
     * Strength check against the account the link belongs to (so a password
     * containing their name or email is rejected). Null = acceptable.
     */
    public function weaknessReason(string $tokenHash, string $password): ?string
    {
        $row  = $this->findActive($tokenHash);
        $user = $row ? auth()->getProvider()->findById((int) $row['user_id']) : null;

        if (! $user) {
            return null;   // reset() will refuse an invalid link
        }

        $result = service('passwords')->check($password, $user);

        return $result->isOK() ? null : ($result->reason() ?: 'That password is too easy to guess.');
    }

    /**
     * Sets the new password and burns the link. Throws RuntimeException with a
     * user-safe message when the link is no longer valid.
     */
    public function reset(string $tokenHash, string $newPassword): int
    {
        $row = $this->findActive($tokenHash);

        if (! $row) {
            throw new RuntimeException('This reset link is invalid or has expired. Please request a new one.');
        }

        $userId  = (int) $row['user_id'];
        $users   = auth()->getProvider();
        $user    = $users->findById($userId);
        $profile = db_connect()->table('staff_profiles')->where('user_id', $userId)->get()->getRowArray();

        if (! $user || $user->isBanned() || ! $profile || $profile['status'] !== 'active' || $profile['source'] !== 'local') {
            throw new RuntimeException('This reset link is invalid or has expired. Please request a new one.');
        }

        $user->password = $newPassword;
        $users->save($user);
        $user->undoForcePasswordReset();

        $db = db_connect();
        // Burn this link and any other outstanding ones; end any "remember me" sessions.
        $db->table('password_resets')->where('user_id', $userId)->where('used_at', null)->update(['used_at' => date('Y-m-d H:i:s')]);
        $db->table('auth_remember_tokens')->where('user_id', $userId)->delete();
        service('permissions')->forget($userId);
        service('mfa')->revokeDevices($userId);   // trusted browsers must re-verify after a password reset

        service('audit')->log('auth.password_reset_completed', 'user', $userId, null, null, $userId);
        service('notifier')->send($userId, 'account.password_changed', 'Your password was changed', 'Your password was reset using an emailed link. If this was not you, contact an administrator immediately.');

        return $userId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActive(string $tokenHash): ?array
    {
        return db_connect()->table('password_resets')
            ->where('token_hash', $tokenHash)->where('used_at', null)->where('expires_at >', date('Y-m-d H:i:s'))
            ->get()->getRowArray();
    }
}

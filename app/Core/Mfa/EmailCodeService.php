<?php

namespace App\Core\Mfa;

use Config\Mfa;

/**
 * Six-digit codes sent by email: single use, short-lived, attempt-limited and
 * stored only as a keyed hash.
 */
class EmailCodeService
{
    public const SENT        = 'sent';
    public const COOLDOWN    = 'cooldown';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @return self::SENT|self::COOLDOWN|self::UNAVAILABLE
     */
    public function send(int $userId, string $address): string
    {
        $config = config(Mfa::class);
        $db     = db_connect();
        $mfa    = service('mfa');

        if (! service('mailer')->isConfigured() || ! $mfa->cryptoReady()) {
            return self::UNAVAILABLE;
        }

        $existing = $db->table('mfa_challenges')->where('user_id', $userId)->get()->getRowArray();

        if ($existing && strtotime($existing['created_at']) > time() - $config->emailResendSeconds) {
            return self::COOLDOWN;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $db->table('mfa_challenges')->where('user_id', $userId)->delete();
        $db->table('mfa_challenges')->insert([
            'user_id'    => $userId,
            'code_hash'  => $mfa->hmac($userId, $code),
            'attempts'   => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + $config->emailCodeTtlMinutes * 60),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $sent = service('mailer')->send($address, 'Your Back Office sign-in code', view('emails/mfa_code', [
            'code'    => $code,
            'minutes' => $config->emailCodeTtlMinutes,
        ]));

        if (! $sent) {
            $db->table('mfa_challenges')->where('user_id', $userId)->delete();

            return self::UNAVAILABLE;
        }

        return self::SENT;
    }

    public function verify(int $userId, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $db  = db_connect();
        $row = $db->table('mfa_challenges')->where('user_id', $userId)->get()->getRowArray();

        if (! $row) {
            return false;
        }

        if (strtotime($row['expires_at']) < time()) {
            $db->table('mfa_challenges')->where('id', $row['id'])->delete();

            return false;
        }

        // Count the attempt first, atomically, so parallel guesses cannot exceed the cap.
        $db->query('UPDATE mfa_challenges SET attempts = attempts + 1 WHERE id = ? AND attempts < ?', [$row['id'], config(Mfa::class)->maxAttempts]);

        if ($db->affectedRows() !== 1) {
            return false;
        }

        if (! hash_equals($row['code_hash'], service('mfa')->hmac($userId, $code))) {
            return false;
        }

        // Burn on success; only the first of two simultaneous correct submissions gets through.
        $db->table('mfa_challenges')->where(['id' => $row['id'], 'code_hash' => $row['code_hash']])->delete();

        return $db->affectedRows() === 1;
    }

    public function secondsUntilResend(int $userId): int
    {
        $row = db_connect()->table('mfa_challenges')->where('user_id', $userId)->get()->getRowArray();

        return $row ? max(0, strtotime($row['created_at']) + config(Mfa::class)->emailResendSeconds - time()) : 0;
    }
}

<?php

namespace App\Core\Mfa;

use Config\Mfa;
use RuntimeException;
use Throwable;

/**
 * Storage and bookkeeping shared by every MFA method: enrolled factors,
 * recovery codes, trusted devices, failure lockout, and keyed hashing.
 */
class MfaService
{
    public const DEVICE_COOKIE = 'mfa_device';

    // ---- Crypto -----------------------------------------------------------

    public function cryptoReady(): bool
    {
        try {
            service('encrypter');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function encrypt(string $plain): string
    {
        if (! $this->cryptoReady()) {
            throw new RuntimeException('Multi-factor authentication needs an encryption key (php spark key:generate).');
        }

        return base64_encode(service('encrypter')->encrypt($plain));
    }

    public function decrypt(string $stored): string
    {
        if (! $this->cryptoReady()) {
            throw new RuntimeException('Multi-factor authentication needs an encryption key (php spark key:generate).');
        }

        return service('encrypter')->decrypt((string) base64_decode($stored, true));
    }

    /**
     * Keyed hash for low-entropy secrets (6-digit and recovery codes): a database
     * leak alone cannot be brute-forced, and each hash is bound to its user.
     */
    public function hmac(int $userId, string $value): string
    {
        $key = (string) config('Encryption')->key;

        if ($key === '') {
            throw new RuntimeException('Multi-factor authentication needs an encryption key (php spark key:generate).');
        }

        return hash_hmac('sha256', $userId . '|' . $value, hash('sha256', 'mfa|' . $key, true));
    }

    // ---- Enrolled factors -------------------------------------------------

    /**
     * @return array<string, array<string, mixed>> keyed by type
     */
    public function methods(int $userId): array
    {
        $rows = db_connect()->table('user_mfa_methods')->where('user_id', $userId)->get()->getResultArray();

        return array_column($rows, null, 'type');
    }

    public function hasMethod(int $userId, string $type): bool
    {
        return isset($this->methods($userId)[$type]);
    }

    /**
     * Enrolled factors the admin currently allows, in preference order.
     *
     * @return list<string>
     */
    public function usableMethods(int $userId): array
    {
        $enrolled = array_keys($this->methods($userId));

        return array_values(array_filter(
            Mfa::METHODS,
            static fn (string $type): bool => in_array($type, $enrolled, true) && in_array($type, service('mfaPolicy')->enabledMethods(), true),
        ));
    }

    public function addMethod(int $userId, string $type, ?string $plainSecret = null, ?int $lastStep = null): void
    {
        if (! in_array($type, Mfa::METHODS, true)) {
            throw new RuntimeException('Unknown MFA method.');
        }

        $db = db_connect();
        $db->table('user_mfa_methods')->where(['user_id' => $userId, 'type' => $type])->delete();
        $db->table('user_mfa_methods')->insert([
            'user_id'      => $userId,
            'type'         => $type,
            'secret_enc'   => $plainSecret === null ? null : $this->encrypt($plainSecret),
            'last_step'    => $lastStep,
            'confirmed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Removes one factor. Without any factor left, recovery codes and trusted
     * devices are meaningless and are dropped too.
     */
    public function removeMethod(int $userId, string $type): void
    {
        db_connect()->table('user_mfa_methods')->where(['user_id' => $userId, 'type' => $type])->delete();
        $this->revokeDevices($userId);

        if ($this->methods($userId) === []) {
            db_connect()->table('mfa_recovery_codes')->where('user_id', $userId)->delete();
        }
    }

    public function touch(int $userId, string $type): void
    {
        db_connect()->table('user_mfa_methods')->where(['user_id' => $userId, 'type' => $type])->update(['last_used_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Preferred method for the challenge screen: last used, else policy order.
     */
    public function preferredMethod(int $userId): ?string
    {
        $usable = $this->usableMethods($userId);

        if ($usable === []) {
            return null;
        }

        $methods = $this->methods($userId);
        usort($usable, static fn ($a, $b) => strcmp((string) ($methods[$b]['last_used_at'] ?? ''), (string) ($methods[$a]['last_used_at'] ?? '')));

        return $usable[0];
    }

    /**
     * Everything MFA knows about a user. Used by the admin reset and when a
     * password is reset by an administrator.
     */
    public function resetAll(int $userId): void
    {
        $db = db_connect();

        foreach (['user_mfa_methods', 'mfa_recovery_codes', 'mfa_challenges', 'mfa_trusted_devices'] as $table) {
            $db->table($table)->where('user_id', $userId)->delete();
        }

        $this->clearFailures($userId);
    }

    // ---- Recovery codes ---------------------------------------------------

    /**
     * Replaces any existing codes. The plain codes are returned once; only keyed hashes are stored.
     *
     * @return list<string> formatted like ABCDE-FGHJK
     */
    public function generateRecoveryCodes(int $userId): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $db       = db_connect();
        $now      = date('Y-m-d H:i:s');
        $codes    = [];

        $db->table('mfa_recovery_codes')->where('user_id', $userId)->delete();

        for ($i = 0; $i < config(Mfa::class)->recoveryCodeCount; $i++) {
            $raw = '';

            for ($j = 0; $j < 10; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $db->table('mfa_recovery_codes')->insert(['user_id' => $userId, 'code_hash' => $this->hmac($userId, $raw), 'created_at' => $now]);
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }

        return $codes;
    }

    public function recoveryCodesRemaining(int $userId): int
    {
        return db_connect()->table('mfa_recovery_codes')->where('user_id', $userId)->where('used_at', null)->countAllResults();
    }

    /**
     * Single use, race-safe: the UPDATE only succeeds for the first caller.
     */
    public function useRecoveryCode(int $userId, string $code): bool
    {
        $raw = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        if (strlen($raw) !== 10) {
            return false;
        }

        $db = db_connect();
        $db->table('mfa_recovery_codes')
            ->where(['user_id' => $userId, 'code_hash' => $this->hmac($userId, $raw), 'used_at' => null])
            ->update(['used_at' => date('Y-m-d H:i:s')]);

        return $db->affectedRows() === 1;
    }

    // ---- Trusted devices --------------------------------------------------

    /**
     * Creates a trusted-device record and returns cookie settings for Response::setCookie().
     *
     * @return array<string, mixed>
     */
    public function issueTrustedDevice(int $userId, int $days): array
    {
        $db    = db_connect();
        $token = bin2hex(random_bytes(32));
        $agent = service('request') instanceof \CodeIgniter\HTTP\IncomingRequest ? (string) service('request')->getUserAgent() : '';

        $db->table('mfa_trusted_devices')->where('expires_at <', date('Y-m-d H:i:s'))->delete();
        $db->table('mfa_trusted_devices')->insert([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'user_agent' => mb_substr($agent, 0, 255),
            'ip'         => service('request') instanceof \CodeIgniter\HTTP\IncomingRequest ? service('request')->getIPAddress() : null,
            'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'name'     => self::DEVICE_COOKIE,
            'value'    => $token,
            'expire'   => $days * 86400,
            'path'     => '/',
            'httponly' => true,
            'secure'   => ENVIRONMENT === 'production',
            'samesite' => 'Lax',
        ];
    }

    public function isTrustedDevice(int $userId): bool
    {
        $request = service('request');
        $token   = $request instanceof \CodeIgniter\HTTP\IncomingRequest ? $request->getCookie(self::DEVICE_COOKIE) : null;

        if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        return db_connect()->table('mfa_trusted_devices')
            ->where(['user_id' => $userId, 'token_hash' => hash('sha256', $token)])
            ->where('expires_at >', date('Y-m-d H:i:s'))->countAllResults() > 0;
    }

    public function revokeDevices(int $userId): void
    {
        db_connect()->table('mfa_trusted_devices')->where('user_id', $userId)->delete();
    }

    public function revokeAllDevices(): void
    {
        db_connect()->table('mfa_trusted_devices')->truncate();
    }

    public function deviceCount(int $userId): int
    {
        return db_connect()->table('mfa_trusted_devices')->where('user_id', $userId)->where('expires_at >', date('Y-m-d H:i:s'))->countAllResults();
    }

    // ---- Failure lockout (per account, across sessions) -------------------

    public function isLockedOut(int $userId): bool
    {
        $state = cache($this->failureKey($userId));

        return is_array($state) && ($state['count'] ?? 0) >= config(Mfa::class)->lockoutFailures;
    }

    public function recordFailure(int $userId): void
    {
        $state = cache($this->failureKey($userId));
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;

        cache()->save($this->failureKey($userId), ['count' => $count + 1], config(Mfa::class)->lockoutMinutes * 60);
    }

    public function clearFailures(int $userId): void
    {
        cache()->delete($this->failureKey($userId));
    }

    private function failureKey(int $userId): string
    {
        return 'mfa_fail_' . $userId;
    }

    // ---- Re-authentication for sensitive changes --------------------------

    public function markReauth(): void
    {
        session()->set('mfa_reauth', time());
    }

    public function reauthFresh(): bool
    {
        $at = session()->get('mfa_reauth');

        return is_int($at) && time() - $at < config(Mfa::class)->reauthMinutes * 60;
    }

    // ---- Auditing and notice ---------------------------------------------

    /**
     * Records an MFA change and tells the account owner (the notice is locked on).
     */
    public function announce(int $userId, string $action, string $title, string $body = ''): void
    {
        service('audit')->log($action, 'user', $userId, null, null, auth()->id() === null ? $userId : (int) auth()->id());
        service('notifier')->send($userId, 'account.mfa_changed', $title, $body === '' ? null : $body, 'profile/mfa');
    }
}

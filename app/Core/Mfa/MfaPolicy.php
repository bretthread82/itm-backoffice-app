<?php

namespace App\Core\Mfa;

use CodeIgniter\Shield\Entities\User;
use Config\Duo;
use Config\Mfa;

/**
 * What the administrator has decided about MFA, and what that means for a
 * given user at sign-in. Settings are saved through Shield's settings store
 * (setting('Mfa.*')); Config\Mfa holds the defaults.
 */
class MfaPolicy
{
    // ---- Admin switches ---------------------------------------------------

    public function enabled(): bool
    {
        return (bool) setting('Mfa.enabled');
    }

    public function rememberDays(): int
    {
        return max(0, min(90, (int) setting('Mfa.rememberDays')));
    }

    /**
     * @return list<int>
     */
    public function requiredRoleIds(): array
    {
        return array_values(array_map('intval', (array) (setting('Mfa.requiredRoles') ?? [])));
    }

    /**
     * Whether the admin has switched this method on, ignoring prerequisites.
     */
    public function switchedOn(string $type): bool
    {
        return in_array($type, Mfa::METHODS, true) && (bool) setting('Mfa.' . $type);
    }

    /**
     * Whether the method can work at all on this system right now.
     */
    public function prerequisitesMet(string $type): bool
    {
        return match ($type) {
            'totp'  => service('mfa')->cryptoReady(),
            'email' => service('mailer')->isConfigured() && service('mfa')->cryptoReady(),
            'duo'   => config(Duo::class)->isConfigured(),
            default => false,
        };
    }

    /**
     * Why a method cannot be switched on (null = it can).
     */
    public function unavailableReason(string $type): ?string
    {
        if ($this->prerequisitesMet($type)) {
            return null;
        }

        return match ($type) {
            'totp'  => 'The encryption key is not set (run php spark key:generate).',
            'email' => service('mfa')->cryptoReady() ? 'Outbound email is not configured (email.fromEmail).' : 'The encryption key is not set (run php spark key:generate).',
            'duo'   => 'Duo is not configured in .env (duo.clientId, duo.clientSecret, duo.apiHost).',
            default => 'Unknown method.',
        };
    }

    /**
     * Methods users can actually be challenged with right now.
     *
     * @return list<string>
     */
    public function enabledMethods(): array
    {
        return array_values(array_filter(
            Mfa::METHODS,
            fn (string $type): bool => $this->switchedOn($type) && $this->prerequisitesMet($type),
        ));
    }

    /**
     * @param array{enabled?: bool, totp?: bool, email?: bool, duo?: bool, requiredRoles?: list<int>, rememberDays?: int} $values
     */
    public function save(array $values): void
    {
        foreach (['enabled', 'totp', 'email', 'duo'] as $key) {
            if (array_key_exists($key, $values)) {
                setting()->set('Mfa.' . $key, (bool) $values[$key]);
            }
        }

        if (array_key_exists('requiredRoles', $values)) {
            setting()->set('Mfa.requiredRoles', array_values(array_unique(array_map('intval', $values['requiredRoles']))));
        }

        if (array_key_exists('rememberDays', $values)) {
            setting()->set('Mfa.rememberDays', max(0, min(90, (int) $values['rememberDays'])));
        }
    }

    /**
     * Checks a proposed set of admin settings. Returns an error message, or null if acceptable.
     *
     * @param array{enabled: bool, totp: bool, email: bool, duo: bool, requiredRoles: list<int>, rememberDays: int} $new
     */
    public function validateChange(array $new, int $actingUserId): ?string
    {
        $db = db_connect();

        if ($new['rememberDays'] < 0 || $new['rememberDays'] > 90) {
            return 'Remember-device days must be between 0 and 90.';
        }

        if ($new['requiredRoles'] !== []) {
            $unique = array_values(array_unique($new['requiredRoles']));
            $valid  = $db->table('roles')->whereIn('id', $unique)->countAllResults();

            if ($valid !== count($unique)) {
                return 'One of the selected roles does not exist.';
            }
        }

        // A method can only be switched on if it can actually work here.
        foreach (Mfa::METHODS as $type) {
            if ($new[$type] && ! $this->prerequisitesMet($type)) {
                return ucfirst($type) . ' cannot be turned on: ' . $this->unavailableReason($type);
            }
        }

        $offered = array_values(array_filter(Mfa::METHODS, static fn (string $t) => $new[$t]));

        if ($new['enabled'] && $offered === []) {
            return 'Turn on at least one method before enabling two-step verification.';
        }

        // Do not let an admin lock themselves out: if they will be required to use MFA they must
        // already have an enrolled, offered factor, which also proves at least one method works.
        if ($new['enabled'] && $new['requiredRoles'] !== []) {
            $mine = $db->table('user_roles')->where('user_id', $actingUserId)->whereIn('role_id', $new['requiredRoles'])->countAllResults() > 0;
            $have = array_intersect(array_keys(service('mfa')->methods($actingUserId)), $offered) !== [];

            if ($mine && ! $have) {
                return 'Your own role would be required to use two-step verification. Set up a method for yourself first (Profile > Two-step verification), so you know it works, then save again.';
            }
        }

        return null;
    }

    /**
     * Saves validated settings. Signs out every remembered device when the rules
     * get tighter, so old trust cannot outlive a policy change. Returns the previous values.
     *
     * @param array{enabled: bool, totp: bool, email: bool, duo: bool, requiredRoles: list<int>, rememberDays: int} $new
     *
     * @return array<string, mixed>
     */
    public function apply(array $new): array
    {
        $before = [
            'enabled' => $this->enabled(), 'totp' => $this->switchedOn('totp'), 'email' => $this->switchedOn('email'), 'duo' => $this->switchedOn('duo'),
            'rememberDays' => $this->rememberDays(), 'requiredRoles' => $this->requiredRoleIds(),
        ];

        $this->save($new);

        $tightened = ($before['enabled'] && ! $new['enabled']) || $new['rememberDays'] < $before['rememberDays']
            || array_filter(Mfa::METHODS, static fn (string $t) => $before[$t] && ! $new[$t]) !== [];

        if ($tightened) {
            service('mfa')->revokeAllDevices();
        }

        return $before;
    }

    // ---- Per-user decisions ----------------------------------------------

    public function isRequiredFor(int $userId): bool
    {
        $roles = $this->requiredRoleIds();

        if ($roles === []) {
            return false;
        }

        return db_connect()->table('user_roles')->where('user_id', $userId)->whereIn('role_id', $roles)->countAllResults() > 0;
    }

    /**
     * True when the user must enrol a factor before they can finish signing in.
     */
    public function needsEnrolment(int $userId): bool
    {
        return $this->enabled()
            && $this->enabledMethods() !== []
            && $this->isRequiredFor($userId)
            && service('mfa')->usableMethods($userId) === [];
    }

    /**
     * Shield's ConditionalActionInterface hook: should this password login be
     * paused for a second factor?
     */
    public function appliesTo(User $user): bool
    {
        $userId = (int) $user->id;

        if (! $this->enabled()) {
            return false;
        }

        if ($this->enabledMethods() === []) {
            // Nothing could be offered. Never lock everyone out because of a misconfiguration.
            log_message('warning', 'MFA is enabled but no method is available; skipping the challenge.');

            return false;
        }

        $usable   = service('mfa')->usableMethods($userId);
        $required = $this->isRequiredFor($userId);

        // Optional and not enrolled: nothing to challenge, and nothing forces enrolment.
        if ($usable === [] && ! $required) {
            return false;
        }

        // A trusted browser may skip the challenge, but never enrolment.
        if ($usable !== [] && $this->rememberDays() > 0 && service('mfa')->isTrustedDevice($userId)) {
            return false;
        }

        return true;
    }
}

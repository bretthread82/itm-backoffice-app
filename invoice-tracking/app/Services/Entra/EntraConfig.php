<?php

declare(strict_types=1);

namespace App\Services\Entra;

/**
 * Microsoft Entra ID sign-in settings.
 *
 * Two independent things must be true before the "Sign in with Microsoft" button appears:
 *  - it is configured: tenant id, client id and client secret are in .env (entra.tenantId,
 *    entra.clientId, entra.clientSecret); and
 *  - it is switched on by an administrator (Admin > Settings). Off by default.
 *
 * Local email and password sign-in is never affected by any of this.
 */
final class EntraConfig
{
    /** Values Microsoft accepts as a "tenant" that would let accounts from any organisation in. */
    private const FORBIDDEN_TENANTS = ['common', 'organizations', 'consumers'];

    public static function tenant(): string
    {
        return trim((string) env('entra.tenantId', ''));
    }

    public static function clientId(): string
    {
        return trim((string) env('entra.clientId', ''));
    }

    public static function clientSecret(): string
    {
        return (string) env('entra.clientSecret', '');
    }

    public static function redirectUri(): string
    {
        return site_url('auth/microsoft/callback');
    }

    /**
     * Why Microsoft sign-in cannot be used yet. Empty means it is fully configured.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $p      = [];
        $tenant = self::tenant();

        if ($tenant === '') {
            $p[] = 'entra.tenantId is not set in .env (the organisation\'s directory/tenant ID).';
        } elseif (in_array(strtolower($tenant), self::FORBIDDEN_TENANTS, true)) {
            $p[] = 'entra.tenantId must be the organisation\'s own tenant ID, not "' . $tenant . '", otherwise accounts from other organisations could sign in.';
        } elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/', $tenant) !== 1) {
            $p[] = 'entra.tenantId is not a valid tenant ID or domain.';
        }
        if (self::clientId() === '') {
            $p[] = 'entra.clientId is not set in .env (the app registration\'s application ID).';
        }
        if (self::clientSecret() === '') {
            $p[] = 'entra.clientSecret is not set in .env (a client secret from the app registration).';
        }

        return $p;
    }

    public static function configured(): bool
    {
        return self::problems() === [];
    }

    public static function enabled(): bool
    {
        return (bool) setting('AppSettings.entraEnabled');
    }

    /** True only when configured and switched on. */
    public static function available(): bool
    {
        return self::enabled() && self::configured();
    }
}

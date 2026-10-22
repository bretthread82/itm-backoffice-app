<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Microsoft Entra ID integration. Entirely optional: with entra.enabled = false
 * (the default) no Entra UI or routes do anything. Every value can be set from
 * .env using the `entra.` prefix, e.g. entra.tenantId = '...'. The client
 * secret belongs in .env only; it is never stored in the database.
 */
class Entra extends BaseConfig
{
    /**
     * Master switch. Also requires valid tenant/client ids and a secret.
     */
    public bool $enabled = false;

    /**
     * Directory (tenant) ID, a GUID. Never use "common" or "organizations":
     * issuer validation relies on a single tenant.
     */
    public string $tenantId = '';

    /**
     * Application (client) ID, a GUID.
     */
    public string $clientId = '';

    public string $clientSecret = '';

    /**
     * Must match the Redirect URI registered in Entra exactly.
     * Empty = site_url('auth/entra/callback').
     */
    public string $redirectUri = '';

    /**
     * Create an account on first Entra sign-in when none exists.
     */
    public bool $jitProvisioning = true;

    /**
     * On first Entra sign-in, attach to an existing local account with the
     * same email (then matched by Entra object id from then on).
     */
    public bool $linkExistingByEmail = true;

    /**
     * Directory sync: create accounts for Entra users that have none.
     */
    public bool $syncCreatesUsers = true;

    /**
     * Directory sync: deactivate Entra-sourced accounts that were removed or
     * disabled in Entra.
     */
    public bool $syncDeactivates = true;

    /**
     * Safety stop: a sync that would deactivate more than this percentage of
     * Entra-sourced accounts aborts instead (guards against a bad/partial
     * Graph response wiping access).
     */
    public int $deactivateLimitPercent = 25;

    private const PLACEHOLDER_GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * True when real (non-placeholder) credentials are present.
     */
    public function isConfigured(): bool
    {
        return preg_match(self::PLACEHOLDER_GUID, $this->tenantId) === 1
            && preg_match(self::PLACEHOLDER_GUID, $this->clientId) === 1
            && $this->clientSecret !== ''
            && ! str_starts_with($this->clientSecret, 'YOUR-')
            && $this->tenantId !== '00000000-0000-0000-0000-000000000000'
            && $this->clientId !== '00000000-0000-0000-0000-000000000000';
    }

    /**
     * True when Entra is switched on AND configured.
     */
    public function isReady(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    public function callbackUrl(): string
    {
        return $this->redirectUri !== '' ? $this->redirectUri : site_url('auth/entra/callback');
    }
}

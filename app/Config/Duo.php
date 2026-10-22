<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Duo Universal Prompt (optional). Values come from .env with the `duo.`
 * prefix, e.g. duo.clientId = '...'. Until real values are present the Duo
 * method is unavailable in the admin settings and invisible to users.
 *
 * In the Duo Admin Panel: Applications -> Protect an Application ->
 * "Web SDK" (Universal Prompt), then copy the Client ID, Client secret and
 * API hostname, and add the redirect URL shown on Admin -> Multi-factor auth.
 */
class Duo extends BaseConfig
{
    public string $clientId     = '';
    public string $clientSecret = '';
    public string $apiHost      = '';

    /**
     * Must match the redirect URL registered in Duo exactly.
     * Empty = site_url('mfa/duo/callback').
     */
    public string $redirectUri = '';

    /**
     * True when real (non-placeholder) credentials are present. Duo client ids
     * are 20 characters and secrets 40; hosts look like api-xxxxxxxx.duosecurity.com.
     */
    public function isConfigured(): bool
    {
        return preg_match('/^[A-Za-z0-9]{20}$/', $this->clientId) === 1
            && preg_match('/^[A-Za-z0-9]{40}$/', $this->clientSecret) === 1
            && preg_match('/^api-[a-z0-9]+\.duosecurity\.com$/i', $this->apiHost) === 1
            && ! str_contains(strtoupper($this->clientId . $this->clientSecret), 'XXXX')
            && ! str_starts_with(strtoupper($this->clientId), 'YOUR');
    }

    public function callbackUrl(): string
    {
        return $this->redirectUri !== '' ? $this->redirectUri : site_url('mfa/duo/callback');
    }
}

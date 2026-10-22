<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Multi-factor authentication.
 *
 * The first block holds the ADMIN-CONTROLLED defaults. Admins change them at
 * Admin -> Multi-factor auth; the saved values live in the settings table and
 * are read with setting('Mfa.enabled') etc. (Shield's settings store), so these
 * properties are only the fallback until an admin saves something.
 *
 * The second block is fixed security parameters, not exposed in the UI.
 */
class Mfa extends BaseConfig
{
    // ---- Admin-controlled (defaults) -------------------------------------

    /** Master switch. Off = nobody is ever challenged and enrolments sit dormant. */
    public bool $enabled = false;

    /** Authenticator app (RFC 6238 TOTP). */
    public bool $totp = true;

    /** Emailed one-time code. Needs outbound email to be configured. */
    public bool $email = false;

    /** Duo Universal Prompt. Needs Config\Duo to be configured (.env). */
    public bool $duo = false;

    /**
     * Role ids that MUST use MFA (they are made to enrol at sign-in).
     *
     * @var list<int>
     */
    public array $requiredRoles = [];

    /** Days a browser may skip MFA after "remember this device". 0 disables the feature. */
    public int $rememberDays = 0;

    // ---- Fixed parameters ------------------------------------------------

    public string $issuer = 'ITM Back Office';

    public int $emailCodeTtlMinutes = 10;
    public int $emailResendSeconds  = 60;

    /** Wrong codes allowed in one pending sign-in before the password is required again. */
    public int $maxAttempts = 5;

    /** Failures per account within lockoutMinutes before MFA verification is refused for a while. */
    public int $lockoutFailures = 10;
    public int $lockoutMinutes  = 15;

    public int $recoveryCodeCount = 10;

    /** How long after confirming the password the security settings stay editable. */
    public int $reauthMinutes = 10;

    public const METHODS = ['totp', 'email', 'duo'];
}

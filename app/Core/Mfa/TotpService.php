<?php

namespace App\Core\Mfa;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Config\Mfa;
use OTPHP\TOTP;

/**
 * Authenticator-app codes (RFC 6238: SHA-1, 6 digits, 30 s) accepting one step
 * either side of now. A code can be used once: the matched time step must be
 * newer than the last one accepted for the account.
 */
class TotpService
{
    private const PERIOD = 30;

    public function newSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function provisioningUri(string $secret, string $account): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($account);
        $totp->setIssuer(config(Mfa::class)->issuer);

        return $totp->getProvisioningUri();
    }

    /**
     * QR code as an image data URI (no external requests; allowed by the CSP's img-src data:).
     */
    public function qrDataUri(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 2), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($text));
    }

    /**
     * Checks a code against a secret. Returns the matched time step, or null.
     * All three candidate steps are always evaluated so timing reveals nothing.
     */
    public function matchStep(string $secret, string $code, ?int $afterStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $totp    = TOTP::createFromSecret($secret);
        $now     = time();
        $matched = null;

        foreach ([-1, 0, 1] as $offset) {
            $timestamp = $now + $offset * self::PERIOD;
            $step      = intdiv($timestamp, self::PERIOD);

            if (hash_equals($totp->at($timestamp), $code) && ($afterStep === null || $step > $afterStep)) {
                $matched = max($matched ?? 0, $step);
            }
        }

        return $matched;
    }

    /**
     * Verifies a sign-in code for an enrolled user and burns the time step.
     */
    public function verify(int $userId, string $code): bool
    {
        $mfa = service('mfa');
        $row = $mfa->methods($userId)['totp'] ?? null;

        if (! $row || empty($row['secret_enc'])) {
            return false;
        }

        try {
            $secret = $mfa->decrypt($row['secret_enc']);
        } catch (\Throwable) {
            return false;
        }

        $step = $this->matchStep($secret, $code, $row['last_step'] === null ? null : (int) $row['last_step']);

        if ($step === null) {
            return false;
        }

        // Race-safe: only the first request to present this step wins.
        $db = db_connect();
        $db->query('UPDATE user_mfa_methods SET last_step = ?, last_used_at = ? WHERE id = ? AND (last_step IS NULL OR last_step < ?)', [
            $step, date('Y-m-d H:i:s'), $row['id'], $step,
        ]);

        return $db->affectedRows() === 1;
    }
}

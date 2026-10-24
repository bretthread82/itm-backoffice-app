<?php

declare(strict_types=1);

namespace App\Core\Backups;

use RuntimeException;
use Throwable;

/**
 * Encrypts destination settings (which hold passwords and keys) with the application encryption key,
 * the same way MFA secrets are stored. Backup files themselves use the separate backup passphrase.
 */
final class SecretBox
{
    public function ready(): bool
    {
        try {
            service('encrypter');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function seal(array $settings): string
    {
        if (! $this->ready()) {
            throw new RuntimeException('Backups need an encryption key to store destination passwords (php spark key:generate).');
        }

        return base64_encode(service('encrypter')->encrypt(json_encode($settings, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>
     */
    public function open(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        if (! $this->ready()) {
            throw new RuntimeException('Backups need an encryption key to read destination passwords (php spark key:generate).');
        }

        $json = service('encrypter')->decrypt((string) base64_decode($stored, true));
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}

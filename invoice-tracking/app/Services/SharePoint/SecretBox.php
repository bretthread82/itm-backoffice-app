<?php

declare(strict_types=1);

namespace App\Services\SharePoint;

use RuntimeException;

/**
 * Encrypts stored credentials with the application's encryption key (encryption.key in .env).
 * Anyone with only a copy of the database cannot read the client secrets.
 */
final class SecretBox
{
    public static function seal(string $plain): string
    {
        return base64_encode(service('encrypter')->encrypt($plain));
    }

    public static function open(?string $sealed): string
    {
        if ($sealed === null || $sealed === '') {
            return '';
        }

        $raw = base64_decode($sealed, true);
        if ($raw === false) {
            throw new RuntimeException('The stored secret is not readable.');
        }

        return service('encrypter')->decrypt($raw);
    }
}

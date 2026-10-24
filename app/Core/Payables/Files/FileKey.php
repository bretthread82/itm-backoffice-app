<?php

declare(strict_types=1);

namespace App\Core\Payables\Files;

use InvalidArgumentException;

/** Validates store keys so a key can never climb out of its folder. */
final class FileKey
{
    public static function check(string $key): string
    {
        if (preg_match('#^[a-z]+/[A-Za-z0-9][A-Za-z0-9._-]*$#', $key) !== 1 || str_contains($key, '..')) {
            throw new InvalidArgumentException('Invalid file key.');
        }

        return $key;
    }
}

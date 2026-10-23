<?php

declare(strict_types=1);

namespace App\Services\Entra;

/**
 * Who Microsoft says the person is, after the ID token has been validated.
 */
final class EntraIdentity
{
    public function __construct(
        public readonly string $oid,
        public readonly string $tenantId,
        public readonly string $email,
        public readonly string $name = '',
    ) {
    }
}

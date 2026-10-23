<?php

declare(strict_types=1);

namespace App\Services\Entra;

use RuntimeException;

/**
 * Microsoft sign-in failed. The detail is for the log only: users are shown a generic message.
 */
final class EntraException extends RuntimeException
{
}

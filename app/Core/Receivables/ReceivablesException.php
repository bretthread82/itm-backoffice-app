<?php

declare(strict_types=1);

namespace App\Core\Receivables;

use RuntimeException;

/**
 * A rule was broken (not allowed, missing note, wrong state). The message is safe to show the user.
 */
final class ReceivablesException extends RuntimeException
{
}

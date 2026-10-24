<?php

declare(strict_types=1);

namespace App\Core\Payables;

use RuntimeException;

/**
 * A rule was broken (not allowed, missing note, wrong state). The message is safe to show the user.
 */
final class WorkflowException extends RuntimeException
{
}

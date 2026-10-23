<?php

declare(strict_types=1);

namespace App\Services\SharePoint;

use RuntimeException;

/**
 * A SharePoint call failed. The message is safe to show to administrators (never contains secrets).
 */
final class SharePointException extends RuntimeException
{
}

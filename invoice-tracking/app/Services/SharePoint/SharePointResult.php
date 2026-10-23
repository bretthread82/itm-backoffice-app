<?php

declare(strict_types=1);

namespace App\Services\SharePoint;

final class SharePointResult
{
    public function __construct(
        public readonly string $fileUrl,
        public readonly ?string $itemId,
        public readonly ?string $itemUrl = null,
    ) {
    }
}

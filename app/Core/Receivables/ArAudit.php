<?php

declare(strict_types=1);

namespace App\Core\Receivables;

/**
 * The receivables workflow's audit calls, recorded in the platform audit log (AuditService).
 * Event names are prefixed ar., e.g. ar.approved.
 */
class ArAudit
{
    /**
     * @param array<string, mixed> $detail
     */
    public function log(string $event, ?int $userId, ?string $entity = null, ?int $entityId = null, array $detail = []): void
    {
        service('audit')->log($event, $entity, $entityId, null, $detail === [] ? null : $detail, $userId);
    }
}

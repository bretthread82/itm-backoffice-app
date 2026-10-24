<?php

declare(strict_types=1);

namespace App\Core\Payables;

use CodeIgniter\Database\ConnectionInterface;

/**
 * The invoice workflow's audit calls, recorded in the platform audit log (AuditService).
 * Event names keep the invoice-tracking vocabulary, e.g. invoice.approved.
 */
class InvoiceAudit
{
    public function __construct(?ConnectionInterface $db = null)
    {
        // The connection is accepted for constructor compatibility; AuditService writes through the shared one.
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function log(string $event, ?int $userId, ?string $entity = null, ?int $entityId = null, array $detail = []): void
    {
        service('audit')->log($event, $entity, $entityId, null, $detail === [] ? null : $detail, $userId);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Invoices;

use CodeIgniter\Database\ConnectionInterface;

/**
 * Who may manage other people's invoice work. Replaces invoice-tracking's is_admin / is_manager flags
 * with the platform permission `invoices.manage`; account status changes go through UserService.
 */
class AdminAccess
{
    public function __construct(?ConnectionInterface $db = null, ?InvoiceAudit $audit = null)
    {
    }

    public function canManage(int $userId): bool
    {
        return service('permissions')->can($userId, 'invoices.manage');
    }

    /**
     * @throws WorkflowException
     */
    public function requireCanAdminister(int $actorId, int $targetId): void
    {
        if (! $this->canManage($actorId)) {
            throw new WorkflowException('You do not have permission to do that.');
        }

        // Only a Super Admin may act on a Super Admin.
        if (service('permissions')->isSuper($targetId) && ! service('permissions')->isSuper($actorId)) {
            throw new WorkflowException('Only a Super Admin can change a Super Admin.');
        }
    }

    public function setActive(int $actorId, int $userId, bool $active): void
    {
        $this->requireCanAdminister($actorId, $userId);

        try {
            service('userService')->setStatus($userId, $active ? 'active' : 'inactive');
        } catch (\RuntimeException $e) {
            throw new WorkflowException($e->getMessage(), 0, $e);
        }
    }
}

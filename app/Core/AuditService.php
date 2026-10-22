<?php

namespace App\Core;

/**
 * Append-only record of who changed what. Values are stored as JSON; never
 * pass secrets (passwords, tokens) in $before/$after.
 */
class AuditService
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(string $action, ?string $entity = null, int|string|null $entityId = null, ?array $before = null, ?array $after = null, ?int $actorId = null): void
    {
        db_connect()->table('audit_log')->insert([
            'actor_id'   => $actorId ?? auth()->id(),
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId === null ? null : (string) $entityId,
            'before'     => $before === null ? null : json_encode($before),
            'after'      => $after === null ? null : json_encode($after),
            'ip'         => service('request') instanceof \CodeIgniter\HTTP\IncomingRequest ? service('request')->getIPAddress() : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\ConnectionInterface;

/**
 * Append-only audit trail. Nothing in the app updates or deletes audit_log rows.
 */
class AuditLogger
{
    public function __construct(private ?ConnectionInterface $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function log(string $event, ?int $userId, ?string $entity = null, ?int $entityId = null, array $detail = []): void
    {
        $ip = null;
        if (PHP_SAPI !== 'cli') {
            $ip = service('request')->getIPAddress();
        }

        $this->db->table('audit_log')->insert([
            'user_id'    => $userId,
            'event'      => $event,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'detail'     => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_SLASHES),
            'ip'         => $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

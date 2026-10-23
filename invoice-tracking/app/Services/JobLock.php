<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * One run of a scheduled job at a time across all servers. Uses a MySQL named lock (GET_LOCK) on its own connection,
 * so it is released the moment the job finishes, crashes, or the server loses its connection. A second copy that
 * starts while the first is running does nothing and reports that it was skipped.
 */
final class JobLock
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        // Not the shared connection: the lock belongs to this connection and must not be dropped by other code.
        $this->db = $db ?? db_connect(null, false);
    }

    /**
     * @template T
     *
     * @param callable(): T $job
     *
     * @return array{ran: bool, result: T|null}
     */
    public function run(string $name, callable $job): array
    {
        $lock = 'invoicing:' . $this->db->getDatabase() . ':' . $name;

        $got = $this->db->query('SELECT GET_LOCK(?, 0) AS got', [$lock])->getRowArray();
        if ((int) ($got['got'] ?? 0) !== 1) {
            return ['ran' => false, 'result' => null];
        }

        try {
            return ['ran' => true, 'result' => $job()];
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}

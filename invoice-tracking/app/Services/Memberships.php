<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\ConnectionInterface;

/**
 * A user's per-company memberships (who can see and submit for which company).
 */
class Memberships
{
    public function __construct(private ?ConnectionInterface $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * Makes the user's memberships exactly match $wanted.
     *
     * @param array<int, array{can_submit?: bool, can_approve?: bool, job_title?: string|null}> $wanted keyed by company id
     */
    public function sync(int $userId, array $wanted): void
    {
        $existing = [];
        foreach ($this->db->table('user_companies')->where('user_id', $userId)->get()->getResultArray() as $row) {
            $existing[(int) $row['company_id']] = $row;
        }

        foreach ($existing as $companyId => $_) {
            if (! isset($wanted[$companyId])) {
                $this->db->table('user_companies')->where(['user_id' => $userId, 'company_id' => $companyId])->delete();
            }
        }

        foreach ($wanted as $companyId => $opts) {
            $values = [
                'can_submit'  => ! empty($opts['can_submit']) ? 1 : 0,
                'can_approve' => ! empty($opts['can_approve']) ? 1 : 0,
                'job_title'  => ($opts['job_title'] ?? '') !== '' ? $opts['job_title'] : null,
            ];

            if (isset($existing[$companyId])) {
                $this->db->table('user_companies')->where(['user_id' => $userId, 'company_id' => $companyId])->update($values);
            } else {
                $this->db->table('user_companies')->insert($values + [
                    'user_id' => $userId, 'company_id' => $companyId, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}

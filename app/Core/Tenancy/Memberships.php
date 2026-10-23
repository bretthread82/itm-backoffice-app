<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use CodeIgniter\Database\ConnectionInterface;
use Config\Backoffice;

/**
 * A user's per-company memberships (who can see, submit and approve for which company)
 * and the group-level boundary.
 */
class Memberships
{
    public function __construct(private ?ConnectionInterface $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * The company capabilities modules declare ('companyAccess'), minus those of switched-off modules.
     *
     * @return array<string, array{module: string, label: string, help: string, column: string|null}> keyed '<module>.<key>'
     */
    public function capabilities(): array
    {
        return array_filter(
            config(Backoffice::class)->companyCapabilities(),
            static fn (array $cap): bool => service('modules')->enabled($cap['module']),
        );
    }

    /**
     * @return array<int, array<string, mixed>> membership rows keyed by company id, each with a
     *                                           'capabilities' list of the '<module>.<key>' rights held
     */
    public function forUser(int $userId): array
    {
        $out    = [];
        $legacy = array_filter(config(Backoffice::class)->companyCapabilities(), static fn (array $c): bool => $c['column'] !== null);

        foreach ($this->db->table('user_companies')->where('user_id', $userId)->get()->getResultArray() as $row) {
            $row['capabilities'] = [];

            foreach ($legacy as $key => $cap) {
                if (! empty($row[$cap['column']])) {
                    $row['capabilities'][] = $key;
                }
            }

            $out[(int) $row['company_id']] = $row;
        }

        $stored = $this->db->table('user_company_capabilities')->where('user_id', $userId)->get()->getResultArray();

        foreach ($stored as $row) {
            $out[(int) $row['company_id']]['capabilities'][] = $row['capability'];
        }

        return $out;
    }

    /**
     * Makes the user's memberships exactly match $wanted.
     *
     * Each entry may carry 'capabilities' (a list of '<module>.<key>') or, for the invoices flags,
     * the older 'can_submit' / 'can_approve' booleans. Capabilities of switched-off modules are left
     * as they are, because the form does not show them.
     *
     * @param array<int, array{capabilities?: list<string>, can_submit?: bool, can_approve?: bool, job_title?: string|null}> $wanted keyed by company id
     */
    public function sync(int $userId, array $wanted): void
    {
        $existing = $this->forUser($userId);
        $offered  = $this->capabilities();

        foreach (array_keys($existing) as $companyId) {
            if (! isset($wanted[$companyId])) {
                $this->db->table('user_companies')->where(['user_id' => $userId, 'company_id' => $companyId])->delete();
            }
        }

        foreach ($wanted as $companyId => $opts) {
            $values = ['job_title' => ($opts['job_title'] ?? '') !== '' ? $opts['job_title'] : null];
            $held   = isset($opts['capabilities']) ? array_values(array_intersect($opts['capabilities'], array_keys($offered))) : null;

            foreach (config(Backoffice::class)->companyCapabilities() as $key => $cap) {
                if ($cap['column'] === null) {
                    continue;
                }

                if ($held !== null) {
                    if (isset($offered[$key])) {
                        $values[$cap['column']] = in_array($key, $held, true) ? 1 : 0;
                    } elseif (! isset($existing[$companyId])) {
                        $values[$cap['column']] = 0;
                    }
                } else {
                    $values[$cap['column']] = ! empty($opts[$cap['column']]) ? 1 : 0;
                }
            }

            if (isset($existing[$companyId])) {
                $this->db->table('user_companies')->where(['user_id' => $userId, 'company_id' => $companyId])->update($values);
            } else {
                $this->db->table('user_companies')->insert($values + [
                    'user_id' => $userId, 'company_id' => $companyId, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if ($held !== null) {
                $this->syncStored($userId, $companyId, $held, array_keys(array_filter($offered, static fn (array $c): bool => $c['column'] === null)));
            }
        }
    }

    /**
     * @param list<string> $held    capabilities to hold
     * @param list<string> $managed stored capabilities the form offered (the only ones touched)
     */
    private function syncStored(int $userId, int $companyId, array $held, array $managed): void
    {
        $where = ['user_id' => $userId, 'company_id' => $companyId];

        if ($managed !== []) {
            $this->db->table('user_company_capabilities')->where($where)->whereIn('capability', $managed)->delete();
        }

        foreach (array_intersect($held, $managed) as $capability) {
            $this->db->table('user_company_capabilities')->insert($where + ['capability' => $capability]);
        }
    }

    public function grantGroupLevel(int $userId, ?int $grantedBy): void
    {
        $this->db->table('group_level_users')->ignore(true)->insert([
            'user_id' => $userId, 'granted_by' => $grantedBy, 'granted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function revokeGroupLevel(int $userId): void
    {
        $this->db->table('group_level_users')->where('user_id', $userId)->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Invoices;

use CodeIgniter\Database\BaseConnection;

/**
 * Copies a default invoice type into a company as an ordinary per-company type.
 */
class DefaultTypeAssigner
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return 'created'|'existing'
     */
    public function assign(int $defaultTypeId, string $name, int $companyId): string
    {
        $found = $this->db->table('inv_invoice_types')
            ->where('company_id', $companyId)
            ->where('LOWER(name)', mb_strtolower($name))
            ->get()->getRowArray();

        if ($found !== null) {
            // Already there (made by hand or by import): keep it, just record the link.
            if ($found['default_type_id'] === null) {
                $this->db->table('inv_invoice_types')->where('id', $found['id'])->update(['default_type_id' => $defaultTypeId]);
            }

            return 'existing';
        }

        $default = $this->db->table('inv_default_invoice_types')->select('high_priority')->where('id', $defaultTypeId)->get()->getRowArray();

        $now = date('Y-m-d H:i:s');
        $this->db->table('inv_invoice_types')->insert([
            'company_id' => $companyId, 'name' => $name, 'active' => 1, 'high_priority' => (int) ($default['high_priority'] ?? 0),
            'default_type_id' => $defaultTypeId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return 'created';
    }
}

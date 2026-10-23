<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCodeToSuppliers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('suppliers', [
            'code' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'after' => 'id'],
        ]);

        // Suppliers created before codes existed get a placeholder a manager can overwrite.
        $this->db->query("UPDATE suppliers SET code = CONCAT('S', LPAD(id, 4, '0')) WHERE code IS NULL");

        $this->forge->modifyColumn('suppliers', [
            'code' => ['name' => 'code', 'type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
        ]);
        $this->db->query('ALTER TABLE suppliers ADD UNIQUE KEY suppliers_code_unique (code)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE suppliers DROP INDEX suppliers_code_unique');
        $this->forge->dropColumn('suppliers', 'code');
    }
}

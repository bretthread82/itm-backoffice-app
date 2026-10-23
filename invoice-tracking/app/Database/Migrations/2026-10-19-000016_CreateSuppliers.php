<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSuppliers extends Migration
{
    public function up(): void
    {
        // The pick-list behind the Supplier field on the invoice form. Global, maintained by managers.
        // Invoices keep the supplier name as text, so renaming or switching one off never alters an invoice.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('suppliers');

        // Start the list from the suppliers already on invoices so nothing in flight is orphaned.
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            "INSERT INTO suppliers (name, active, created_at, updated_at)
             SELECT MIN(TRIM(supplier)), 1, ?, ? FROM invoices
             WHERE supplier IS NOT NULL AND TRIM(supplier) <> '' GROUP BY LOWER(TRIM(supplier))",
            [$now, $now],
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('suppliers', true);
    }
}

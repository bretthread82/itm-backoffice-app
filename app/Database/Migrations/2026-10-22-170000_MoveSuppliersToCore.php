<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Suppliers become a core list any module can use. Moves inv_suppliers to suppliers (ids kept) and
 * links invoices by id while keeping the supplier name on the invoice as a snapshot.
 */
class MoveSuppliersToCore extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'code'       => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('suppliers');

        if ($this->db->tableExists('inv_suppliers')) {
            $this->db->query('INSERT INTO suppliers (id, code, name, active, created_at, updated_at) SELECT id, code, name, active, created_at, updated_at FROM inv_suppliers');
        }

        $this->forge->addColumn('inv_invoices', [
            'supplier_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'supplier'],
        ]);
        $this->db->query('ALTER TABLE inv_invoices ADD INDEX inv_invoices_supplier_id (supplier_id)');
        $this->db->query('UPDATE inv_invoices i JOIN suppliers s ON s.name = i.supplier SET i.supplier_id = s.id');
        $this->db->query('ALTER TABLE inv_invoices ADD CONSTRAINT inv_invoices_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL ON UPDATE CASCADE');

        $this->forge->dropTable('inv_suppliers', true);
    }

    public function down(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'code'       => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('inv_suppliers');
        $this->db->query('INSERT INTO inv_suppliers (id, code, name, active, created_at, updated_at) SELECT id, code, name, active, created_at, updated_at FROM suppliers');

        $this->db->query('ALTER TABLE inv_invoices DROP FOREIGN KEY inv_invoices_supplier_fk');
        $this->db->query('ALTER TABLE inv_invoices DROP INDEX inv_invoices_supplier_id');
        $this->forge->dropColumn('inv_invoices', 'supplier_id');
        $this->forge->dropTable('suppliers', true);
    }
}

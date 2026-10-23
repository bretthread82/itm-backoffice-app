<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A library of default invoice types. Assigning one to a company copies it into invoice_types,
 * so routes, invoices and workflow keep using ordinary per-company types.
 */
class CreateDefaultInvoiceTypes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('default_invoice_types');

        $this->forge->addColumn('invoice_types', [
            'default_type_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('default_type_id');
        $this->forge->processIndexes('invoice_types');
    }

    public function down(): void
    {
        $this->forge->dropColumn('invoice_types', 'default_type_id');
        $this->forge->dropTable('default_invoice_types', true);
    }
}

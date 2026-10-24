<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Customers become a core list any module can use (receivables, and later CRM, quotes, projects). Kept neutral:
 * no balances or credit limits here; those belong in the consuming module's own tables, keyed by customer id.
 */
class CreateCustomers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'code'              => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 200],
            'legal_name'        => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'tax_id'            => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'email'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'phone'             => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'address_line1'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'address_line2'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'city'              => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'region'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'postal_code'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'country'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'payment_terms_days' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
            'default_currency'  => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'active'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('customers');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'customer_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'email'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'phone'       => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'role'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'is_primary'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('customer_id');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('customer_contacts');
    }

    public function down(): void
    {
        $this->forge->dropTable('customer_contacts', true);
        $this->forge->dropTable('customers', true);
    }
}

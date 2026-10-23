<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-user, per-company capabilities declared by modules under 'companyAccess' in the registry
 * (key '<module>.<key>'). Invoices keeps its submit/approve flags on user_companies.
 */
class CreateUserCompanyCapabilities extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'company_id' => ['type' => 'INT', 'unsigned' => true],
            'capability' => ['type' => 'VARCHAR', 'constraint' => 80],
        ]);
        $this->forge->addKey(['user_id', 'company_id', 'capability'], true);
        $this->forge->addForeignKey(['user_id', 'company_id'], 'user_companies', ['user_id', 'company_id'], 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_company_capabilities');
    }

    public function down(): void
    {
        $this->forge->dropTable('user_company_capabilities', true);
    }
}

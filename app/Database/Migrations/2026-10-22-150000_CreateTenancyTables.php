<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Core tenancy: company groups, companies, per-user company memberships and the
 * group-level boundary. Any module that scopes data by company uses these.
 */
class CreateTenancyTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'parent_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->addForeignKey('parent_id', 'company_groups', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('company_groups');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'group_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'code'       => ['type' => 'VARCHAR', 'constraint' => 30],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 160],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'logo_file'  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'logo_mime'  => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('group_id');
        $this->forge->addForeignKey('group_id', 'company_groups', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('companies');

        $this->forge->addField([
            'user_id'     => ['type' => 'INT', 'unsigned' => true],
            'company_id'  => ['type' => 'INT', 'unsigned' => true],
            'can_submit'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'can_approve' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'job_title'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey(['user_id', 'company_id'], true);
        $this->forge->addKey('company_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_companies');

        $this->forge->addField([
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'granted_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'granted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('group_level_users');
    }

    public function down(): void
    {
        foreach (['group_level_users', 'user_companies', 'companies', 'company_groups'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}

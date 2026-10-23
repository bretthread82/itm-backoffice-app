<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Organisation structure: sub-groups, SharePoint connection placeholders, companies,
 * user extensions and membership. Written with portable Forge types (SQLite for dev/tests,
 * MySQL 8 in production).
 */
class CreateOrgTables extends Migration
{
    public function up(): void
    {
        // Sub-groups are organisational only. They never grant or limit access.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'parent_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 150],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('parent_id');
        $this->forge->createTable('company_groups');

        // Placeholders until the Microsoft app registration exists. Disabled by default.
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 150],
            'tenant_id'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'client_id'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'client_secret' => ['type' => 'TEXT', 'null' => true], // stored encrypted
            'site_url'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'list_name'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'library_name'  => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'field_mapping' => ['type' => 'TEXT', 'null' => true], // JSON
            'is_default'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'enabled'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'last_tested_at' => ['type' => 'DATETIME', 'null' => true],
            'last_error'    => ['type' => 'TEXT', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('sharepoint_connections');

        $this->forge->addField([
            'id'                       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'group_id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'code'                     => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'                     => ['type' => 'VARCHAR', 'constraint' => 150],
            'active'                   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sharepoint_connection_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'               => ['type' => 'DATETIME', 'null' => true],
            'updated_at'               => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('group_id');
        $this->forge->createTable('companies');

        // Extend Shield's users table.
        $this->forge->addColumn('users', [
            'display_name'         => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'auth_source'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'local'],
            'entra_oid'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'is_admin'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'email_missing'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'company_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'can_submit' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'job_title'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'company_id']);
        $this->forge->createTable('user_companies');

        // The single group-level security boundary. Only administrators may change it.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'granted_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'granted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('user_id');
        $this->forge->createTable('group_level_users');

        $this->forge->addField([
            'id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 150],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('job_titles');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'name']);
        $this->forge->createTable('invoice_types');
    }

    public function down(): void
    {
        $this->forge->dropTable('invoice_types', true);
        $this->forge->dropTable('job_titles', true);
        $this->forge->dropTable('group_level_users', true);
        $this->forge->dropTable('user_companies', true);
        $this->forge->dropColumn('users', ['display_name', 'auth_source', 'entra_oid', 'is_admin', 'email_missing']);
        $this->forge->dropTable('companies', true);
        $this->forge->dropTable('sharepoint_connections', true);
        $this->forge->dropTable('company_groups', true);
    }
}

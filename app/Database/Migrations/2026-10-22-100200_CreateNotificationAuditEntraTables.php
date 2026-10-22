<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateNotificationAuditEntraTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'type'       => ['type' => 'VARCHAR', 'constraint' => 80],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 200],
            'body'       => ['type' => 'TEXT', 'null' => true],
            'url'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'data'       => ['type' => 'JSON', 'null' => true],
            'read_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'read_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('notifications');

        $this->forge->addField([
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'type'    => ['type' => 'VARCHAR', 'constraint' => 80],
            'in_app'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'email'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addKey(['user_id', 'type'], true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('notification_preferences');

        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'actor_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'action'     => ['type' => 'VARCHAR', 'constraint' => 80],
            'entity'     => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'entity_id'  => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'before'     => ['type' => 'JSON', 'null' => true],
            'after'      => ['type' => 'JSON', 'null' => true],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['entity', 'entity_id']);
        $this->forge->addKey('actor_id');
        $this->forge->createTable('audit_log');

        $this->forge->addField([
            'entra_group_id' => ['type' => 'CHAR', 'constraint' => 36],
            'group_name'     => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'role_id'        => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addKey(['entra_group_id', 'role_id'], true);
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('entra_group_role_map');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'started_at'  => ['type' => 'DATETIME'],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'created'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'updated'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'deactivated' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'errors'      => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('entra_sync_runs');
    }

    public function down(): void
    {
        foreach (['entra_sync_runs', 'entra_group_role_map', 'audit_log', 'notification_preferences', 'notifications'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}

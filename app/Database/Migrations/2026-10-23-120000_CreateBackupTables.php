<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Backups: where copies are sent (bk_destinations, settings stored encrypted), each run (bk_runs) and the
 * outcome of that run per destination (bk_run_destinations). Foreign-key rules are (update, delete).
 */
class CreateBackupTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'type'           => ['type' => 'VARCHAR', 'constraint' => 20],
            'settings'       => ['type' => 'TEXT', 'null' => true],
            'enabled'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'keep_last'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 14],
            'last_test_at'   => ['type' => 'DATETIME', 'null' => true],
            'last_test_ok'   => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'last_test_error' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('bk_destinations');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'trigger_type' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'cron'],
            'triggered_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'running'],
            'started_at'   => ['type' => 'DATETIME'],
            'finished_at'  => ['type' => 'DATETIME', 'null' => true],
            'file_name'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'size_bytes'   => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'sha256'       => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'encrypted'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'error'        => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('started_at');
        $this->forge->createTable('bk_runs');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'run_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'destination_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'pending'],
            'remote_path'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'bytes'          => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'error'          => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'finished_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['run_id', 'destination_id']);
        $this->forge->addForeignKey('run_id', 'bk_runs', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('destination_id', 'bk_destinations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('bk_run_destinations');
    }

    public function down(): void
    {
        $this->forge->dropTable('bk_run_destinations', true);
        $this->forge->dropTable('bk_runs', true);
        $this->forge->dropTable('bk_destinations', true);
    }
}

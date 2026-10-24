<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per run of a scheduled command, written by the command itself (see MonitoredCommand). A plain log: no
 * foreign keys, pruned after 90 days by cron:check.
 */
class CreateCronRuns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'command'     => ['type' => 'VARCHAR', 'constraint' => 80],
            'started_at'  => ['type' => 'DATETIME'],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'running'],
            'exit_code'   => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'message'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['command', 'started_at']);
        $this->forge->addKey('started_at');
        $this->forge->createTable('cron_runs');
    }

    public function down(): void
    {
        $this->forge->dropTable('cron_runs', true);
    }
}

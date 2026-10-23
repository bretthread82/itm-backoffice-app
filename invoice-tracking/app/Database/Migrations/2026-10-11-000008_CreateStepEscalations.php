<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStepEscalations extends Migration
{
    public function up(): void
    {
        // One row per reminder/escalation actually sent, so the scheduled job never repeats itself.
        // step_entered_at is part of the key so an invoice that comes back round to the same step
        // (after a resubmit) is tracked afresh. Append-only.
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'step_order'      => ['type' => 'INT', 'constraint' => 11],
            'step_entered_at' => ['type' => 'DATETIME'],
            'level'           => ['type' => 'VARCHAR', 'constraint' => 12], // reminder | escalation
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['invoice_id', 'step_order', 'step_entered_at', 'level']);
        $this->forge->createTable('step_escalations');
    }

    public function down(): void
    {
        $this->forge->dropTable('step_escalations', true);
    }
}

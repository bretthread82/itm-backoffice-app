<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProfileChangeRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'       => ['type' => 'INT', 'unsigned' => true],
            'field'         => ['type' => 'VARCHAR', 'constraint' => 40],
            'new_value'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'reason'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'        => ['type' => 'ENUM', 'constraint' => ['pending', 'approved', 'rejected'], 'default' => 'pending'],
            'decided_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'decision_note' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'decided_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('profile_change_requests');
    }

    public function down(): void
    {
        $this->forge->dropTable('profile_change_requests', true);
    }
}

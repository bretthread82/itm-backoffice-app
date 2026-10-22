<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePasswordResets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            // Only a SHA-256 of the emailed token is stored; a database leak cannot be replayed.
            'token_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'expires_at' => ['type' => 'DATETIME'],
            'used_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('password_resets');
    }

    public function down(): void
    {
        $this->forge->dropTable('password_resets', true);
    }
}

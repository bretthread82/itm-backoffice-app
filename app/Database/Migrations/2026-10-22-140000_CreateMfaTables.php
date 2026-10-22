<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMfaTables extends Migration
{
    public function up(): void
    {
        // A user's enrolled factors. TOTP secrets are encrypted; email/duo rows carry no secret.
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'INT', 'unsigned' => true],
            'type'         => ['type' => 'VARCHAR', 'constraint' => 10],
            'secret_enc'   => ['type' => 'TEXT', 'null' => true],
            'last_step'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'confirmed_at' => ['type' => 'DATETIME'],
            'last_used_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'type']);
        // (onUpdate, onDelete): deleting a user removes their factors.
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_mfa_methods');

        // One-time backup codes, stored only as keyed hashes.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'code_hash'  => ['type' => 'CHAR', 'constraint' => 64],
            'used_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'used_at']);
        $this->forge->addUniqueKey('code_hash');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mfa_recovery_codes');

        // Emailed one-time codes (keyed hash only), at most one live per user.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'code_hash'  => ['type' => 'CHAR', 'constraint' => 64],
            'attempts'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'expires_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mfa_challenges');

        // "Remember this device": only a SHA-256 of the cookie token is stored.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'token_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'expires_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['user_id', 'expires_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mfa_trusted_devices');
    }

    public function down(): void
    {
        foreach (['mfa_trusted_devices', 'mfa_challenges', 'mfa_recovery_codes', 'user_mfa_methods'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}

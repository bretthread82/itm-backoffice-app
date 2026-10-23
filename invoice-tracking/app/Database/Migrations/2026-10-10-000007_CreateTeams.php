<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTeams extends Migration
{
    public function up(): void
    {
        // Teams are global (not per company). Invoice visibility still follows AccessScope.
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'active'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('teams');

        $this->forge->addField([
            'id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'team_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['team_id', 'user_id']);
        $this->forge->addKey('user_id');
        $this->forge->createTable('team_members');

        // Who accepted a team step. An open row (released_at null) is the current acceptor;
        // rows are only ever closed, never edited or deleted. The audit trail is in invoice_actions.
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'step_order'  => ['type' => 'INT', 'constraint' => 11],
            'team_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'claimed_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'claimed_at'  => ['type' => 'DATETIME', 'null' => true],
            'released_at' => ['type' => 'DATETIME', 'null' => true],
            'released_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['invoice_id', 'released_at']);
        $this->forge->addKey('claimed_by');
        $this->forge->createTable('invoice_step_claims');

        $this->forge->addColumn('route_steps', [
            'team_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'assignee_user_id'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('route_steps', 'team_id');
        $this->forge->dropTable('invoice_step_claims', true);
        $this->forge->dropTable('team_members', true);
        $this->forge->dropTable('teams', true);
    }
}

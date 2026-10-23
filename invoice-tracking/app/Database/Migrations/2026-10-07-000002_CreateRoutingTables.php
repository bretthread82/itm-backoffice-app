<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRoutingTables extends Migration
{
    public function up(): void
    {
        // A route is the ordered approval chain for one (company, invoice type).
        // "- Yes" routes from the SharePoint list load as extra_approval variants, inactive,
        // with variant_trigger = none until the customer defines the rule.
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'company_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'invoice_type_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'variant'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'default'],
            'variant_trigger' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'none'],
            'trigger_value'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'version'         => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'active'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'source_title'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['company_id', 'invoice_type_id']);
        $this->forge->createTable('routes');

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'route_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'step_order'       => ['type' => 'INT', 'constraint' => 11],
            'step_type'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'approve'],
            'job_title_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'assignee_kind'    => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'user'],
            'assignee_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'assignee_email'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'assignee_label'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'sla_days'         => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['route_id', 'step_order']);
        $this->forge->createTable('route_steps');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'delegator_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'delegate_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'company_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'starts_at'    => ['type' => 'DATETIME'],
            'ends_at'      => ['type' => 'DATETIME'],
            'reason'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'active'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('delegate_id');
        $this->forge->addKey('delegator_id');
        $this->forge->createTable('delegations');
    }

    public function down(): void
    {
        $this->forge->dropTable('delegations', true);
        $this->forge->dropTable('route_steps', true);
        $this->forge->dropTable('routes', true);
    }
}

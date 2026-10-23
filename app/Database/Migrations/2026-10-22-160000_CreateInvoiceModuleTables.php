<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Invoices module schema (phase 1), the final shape of the invoice-tracking tables with an inv_ prefix.
 * Company-owned tables reference companies (core tenancy); people are plain user ids so history survives
 * a user being removed. Child rows of an invoice or route cascade.
 */
class CreateInvoiceModuleTables extends Migration
{
    private function id(): array
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true];
    }

    private function uint(bool $null = false): array
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => true] + ($null ? ['null' => true] : []);
    }

    private function flag(int $default): array
    {
        return ['type' => 'TINYINT', 'constraint' => 1, 'default' => $default];
    }

    private function stamps(): array
    {
        return [
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ];
    }

    public function up(): void
    {
        // Lookups -----------------------------------------------------------------------------
        $this->forge->addField(['id' => $this->id(), 'name' => ['type' => 'VARCHAR', 'constraint' => 150]]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('inv_job_titles');

        $this->forge->addField([
            'id' => $this->id(), 'name' => ['type' => 'VARCHAR', 'constraint' => 200], 'active' => $this->flag(1),
            'high_priority' => $this->flag(0),
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('inv_default_invoice_types');

        $this->forge->addField([
            'id' => $this->id(), 'company_id' => $this->uint(), 'name' => ['type' => 'VARCHAR', 'constraint' => 200],
            'active' => $this->flag(1), 'default_type_id' => $this->uint(true), 'high_priority' => $this->flag(0),
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'name']);
        $this->forge->addKey('default_type_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('default_type_id', 'inv_default_invoice_types', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('inv_invoice_types');

        $this->forge->addField([
            'id' => $this->id(), 'code' => ['type' => 'VARCHAR', 'constraint' => 50], 'name' => ['type' => 'VARCHAR', 'constraint' => 200],
            'active' => $this->flag(1),
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('inv_suppliers');

        $this->forge->addField([
            'id' => $this->id(), 'variant_key' => ['type' => 'VARCHAR', 'constraint' => 20], 'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'is_standard' => $this->flag(0), 'active' => $this->flag(1), 'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 100],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('variant_key');
        $this->forge->createTable('inv_route_variants');

        $now = date('Y-m-d H:i:s');
        $this->db->table('inv_route_variants')->insertBatch([
            ['variant_key' => 'default', 'name' => 'Standard', 'is_standard' => 1, 'active' => 1, 'sort_order' => 0, 'created_at' => $now],
            ['variant_key' => 'extra_approval', 'name' => 'High Value', 'is_standard' => 0, 'active' => 1, 'sort_order' => 10, 'created_at' => $now],
        ]);

        // Teams ---------------------------------------------------------------------------------
        $this->forge->addField([
            'id' => $this->id(), 'name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'group_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], 'active' => $this->flag(1),
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('inv_teams');

        $this->forge->addField(['team_id' => $this->uint(), 'user_id' => $this->uint()]);
        $this->forge->addKey(['team_id', 'user_id'], true);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('team_id', 'inv_teams', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inv_team_members');

        // Routing -------------------------------------------------------------------------------
        $this->forge->addField([
            'id' => $this->id(), 'company_id' => $this->uint(), 'invoice_type_id' => $this->uint(),
            'variant' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'default'],
            'variant_trigger' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'none'],
            'trigger_value' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'version' => ['type' => 'INT', 'constraint' => 11, 'default' => 1], 'active' => $this->flag(1),
            'source_title' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['company_id', 'invoice_type_id']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('invoice_type_id', 'inv_invoice_types', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inv_routes');

        $this->forge->addField([
            'id' => $this->id(), 'route_id' => $this->uint(), 'step_order' => ['type' => 'INT', 'constraint' => 11],
            'step_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'approve'],
            'job_title_id' => $this->uint(true),
            'assignee_kind' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'user'],
            'assignee_user_id' => $this->uint(true), 'team_id' => $this->uint(true),
            'assignee_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'assignee_label' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'sla_days' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['route_id', 'step_order']);
        $this->forge->addForeignKey('route_id', 'inv_routes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('job_title_id', 'inv_job_titles', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('team_id', 'inv_teams', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('inv_route_steps');

        $this->forge->addField([
            'id' => $this->id(), 'delegator_id' => $this->uint(), 'delegate_id' => $this->uint(), 'company_id' => $this->uint(true),
            'starts_at' => ['type' => 'DATETIME'], 'ends_at' => ['type' => 'DATETIME'],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], 'active' => $this->flag(1),
            'created_by' => $this->uint(true), 'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('delegate_id');
        $this->forge->addKey('delegator_id');
        $this->forge->addForeignKey('delegator_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('delegate_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inv_delegations');

        // Invoices ------------------------------------------------------------------------------
        $this->forge->addField([
            'id' => $this->id(), 'company_id' => $this->uint(), 'invoice_type_id' => $this->uint(), 'route_id' => $this->uint(true),
            'route_snapshot' => ['type' => 'TEXT', 'null' => true],
            'supplier' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'invoice_number' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'invoice_date' => ['type' => 'DATE', 'null' => true], 'due_date' => ['type' => 'DATE', 'null' => true],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'currency' => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'USD'],
            'po_reference' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'payment_reference' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'payment_date' => ['type' => 'DATE', 'null' => true],
            'extra_approval' => $this->flag(0), 'high_priority' => $this->flag(0),
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
            'current_step_order' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'submitted_by' => $this->uint(),
            'submitted_at' => ['type' => 'DATETIME', 'null' => true], 'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'processed_at' => ['type' => 'DATETIME', 'null' => true], 'paid_at' => ['type' => 'DATETIME', 'null' => true],
            'ended_at' => ['type' => 'DATETIME', 'null' => true],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey('company_id');
        $this->forge->addKey('status');
        $this->forge->addKey('submitted_by');
        $this->forge->addKey('due_date');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('invoice_type_id', 'inv_invoice_types', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('route_id', 'inv_routes', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('inv_invoices');

        $this->forge->addField([
            'id' => $this->id(), 'invoice_id' => $this->uint(),
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255], 'stored_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'mime' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => 'application/pdf'],
            'size_bytes' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'sha256' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true], 'is_current' => $this->flag(1),
            'uploaded_by' => $this->uint(), 'uploaded_at' => ['type' => 'DATETIME', 'null' => true], 'purged_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addForeignKey('invoice_id', 'inv_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inv_files');

        // Append-only: the app never updates or deletes notes and actions.
        $this->forge->addField([
            'id' => $this->id(), 'invoice_id' => $this->uint(), 'user_id' => $this->uint(),
            'kind' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'note'], 'body' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addForeignKey('invoice_id', 'inv_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inv_notes');

        $this->forge->addField([
            'id' => $this->id(), 'invoice_id' => $this->uint(), 'step_order' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'acted_by' => $this->uint(), 'on_behalf_of' => $this->uint(true),
            'acted_as' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'normal'],
            'action' => ['type' => 'VARCHAR', 'constraint' => 20], 'note_id' => $this->uint(true),
            'acted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addKey('acted_as');
        $this->forge->addForeignKey('invoice_id', 'inv_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inv_actions');

        $this->forge->addField([
            'id' => $this->id(), 'invoice_id' => $this->uint(),
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255], 'stored_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'mime' => ['type' => 'VARCHAR', 'constraint' => 120], 'size_bytes' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'sha256' => ['type' => 'CHAR', 'constraint' => 64], 'uploaded_by' => $this->uint(),
            'acted_as' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'normal'], 'note_id' => $this->uint(true),
            'uploaded_at' => ['type' => 'DATETIME'], 'purged_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addForeignKey('invoice_id', 'inv_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inv_documents');

        $this->forge->addField([
            'id' => $this->id(), 'invoice_id' => $this->uint(), 'step_order' => ['type' => 'INT', 'constraint' => 11],
            'team_id' => $this->uint(), 'claimed_by' => $this->uint(), 'claimed_at' => ['type' => 'DATETIME', 'null' => true],
            'released_at' => ['type' => 'DATETIME', 'null' => true], 'released_by' => $this->uint(true),
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['invoice_id', 'released_at']);
        $this->forge->addKey('claimed_by');
        $this->forge->addForeignKey('invoice_id', 'inv_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('inv_step_claims');

        // Read-only "who is this person" view for the module: name and active flag come from the staff
        // profile (a user with no profile counts as active), email from the sign-in identity.
        $this->db->query(
            "CREATE OR REPLACE VIEW inv_users AS "
            . "SELECT u.id, u.username, "
            . "COALESCE(NULLIF(sp.display_name, ''), NULLIF(TRIM(CONCAT_WS(' ', sp.first_name, sp.last_name)), ''), u.username) AS display_name, "
            . "CASE WHEN u.deleted_at IS NOT NULL THEN 0 WHEN sp.status IS NULL THEN 1 WHEN sp.status = 'active' THEN 1 ELSE 0 END AS active, "
            . "ai.secret AS email "
            . "FROM users u "
            . "LEFT JOIN staff_profiles sp ON sp.user_id = u.id AND sp.deleted_at IS NULL "
            . "LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.type = 'email_password'",
        );
    }

    public function down(): void
    {
        $this->db->query('DROP VIEW IF EXISTS inv_users');

        foreach ([
            'inv_step_claims', 'inv_documents', 'inv_actions', 'inv_notes', 'inv_files', 'inv_invoices', 'inv_delegations',
            'inv_route_steps', 'inv_routes', 'inv_team_members', 'inv_teams', 'inv_route_variants', 'inv_suppliers',
            'inv_invoice_types', 'inv_default_invoice_types', 'inv_job_titles',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}

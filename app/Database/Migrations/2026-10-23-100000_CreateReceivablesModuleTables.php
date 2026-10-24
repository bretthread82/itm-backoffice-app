<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Receivables module schema: customer invoices we issue and collect on, with an ar_ prefix. A structural twin of the
 * inv_ payables tables (same routing, history and claim tables) plus ar_payments, because collection is recorded as
 * receipts against an issued invoice rather than as a route step. Company-owned tables reference companies; people are
 * plain user ids so history survives a user being removed; the customer is a core `customers` row plus a name snapshot.
 * Names come from the existing inv_users view.
 *
 * Forge::addForeignKey takes (onUpdate, onDelete), in that order: the rules below are written as (update, delete).
 */
class CreateReceivablesModuleTables extends Migration
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
        $this->forge->createTable('ar_job_titles');

        $this->forge->addField([
            'id' => $this->id(), 'name' => ['type' => 'VARCHAR', 'constraint' => 200], 'active' => $this->flag(1),
            'high_priority' => $this->flag(0),
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('ar_default_invoice_types');

        $this->forge->addField([
            'id' => $this->id(), 'company_id' => $this->uint(), 'name' => ['type' => 'VARCHAR', 'constraint' => 200],
            'active' => $this->flag(1), 'default_type_id' => $this->uint(true), 'high_priority' => $this->flag(0),
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['company_id', 'name']);
        $this->forge->addKey('default_type_id');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('default_type_id', 'ar_default_invoice_types', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('ar_invoice_types');

        $this->forge->addField([
            'id' => $this->id(), 'variant_key' => ['type' => 'VARCHAR', 'constraint' => 20], 'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'is_standard' => $this->flag(0), 'active' => $this->flag(1), 'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 100],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('variant_key');
        $this->forge->createTable('ar_route_variants');

        $now = date('Y-m-d H:i:s');
        $this->db->table('ar_route_variants')->insertBatch([
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
        $this->forge->createTable('ar_teams');

        $this->forge->addField(['team_id' => $this->uint(), 'user_id' => $this->uint()]);
        $this->forge->addKey(['team_id', 'user_id'], true);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('team_id', 'ar_teams', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_team_members');

        // Routing: step_type is one of submit, approve, issue, notify (there is no pay step) -----
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
        $this->forge->addForeignKey('invoice_type_id', 'ar_invoice_types', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_routes');

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
        $this->forge->addForeignKey('route_id', 'ar_routes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('job_title_id', 'ar_job_titles', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('team_id', 'ar_teams', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('ar_route_steps');

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
        $this->forge->createTable('ar_delegations');

        // Invoices ------------------------------------------------------------------------------
        // status: draft, in_approval, approved, issued, part_paid, paid, returned, rejected, cancelled, written_off.
        // Overdue is never stored: it is derived from due_date for issued and part_paid invoices.
        // amount_paid is derived from ar_payments and written only by the payment service.
        $this->forge->addField([
            'id' => $this->id(), 'company_id' => $this->uint(), 'invoice_type_id' => $this->uint(), 'route_id' => $this->uint(true),
            'route_snapshot' => ['type' => 'TEXT', 'null' => true],
            'customer' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'customer_id' => $this->uint(true),
            'invoice_number' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'invoice_date' => ['type' => 'DATE', 'null' => true], 'due_date' => ['type' => 'DATE', 'null' => true],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'amount_paid' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'currency' => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'USD'],
            'customer_po_reference' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'sent_to' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'last_payment_date' => ['type' => 'DATE', 'null' => true],
            'extra_approval' => $this->flag(0), 'high_priority' => $this->flag(0),
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
            'current_step_order' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'submitted_by' => $this->uint(),
            'submitted_at' => ['type' => 'DATETIME', 'null' => true], 'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'issued_at' => ['type' => 'DATETIME', 'null' => true], 'paid_at' => ['type' => 'DATETIME', 'null' => true],
            'ended_at' => ['type' => 'DATETIME', 'null' => true],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey('company_id');
        $this->forge->addKey('customer_id');
        $this->forge->addKey('status');
        $this->forge->addKey('submitted_by');
        $this->forge->addKey('due_date');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('invoice_type_id', 'ar_invoice_types', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('route_id', 'ar_routes', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('ar_invoices');

        $this->db->query('ALTER TABLE ar_invoices ADD CONSTRAINT ar_invoices_paid_chk CHECK (amount_paid >= 0 AND (amount IS NULL OR amount_paid <= amount))');

        // Receipts: append-only. A reversal is a negative row that points at the receipt it reverses, with a note.
        $this->forge->addField([
            'id' => $this->id(), 'invoice_id' => $this->uint(),
            'amount' => ['type' => 'DECIMAL', 'constraint' => '14,2'],
            'received_date' => ['type' => 'DATE'],
            'reference' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'method' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'reverses_id' => $this->uint(true), 'note' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'recorded_by' => $this->uint(), 'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addUniqueKey('reverses_id');
        $this->forge->addForeignKey('invoice_id', 'ar_invoices', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('reverses_id', 'ar_payments', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('ar_payments');

        $this->db->query('ALTER TABLE ar_payments ADD CONSTRAINT ar_payments_amount_chk CHECK (amount <> 0)');

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
        $this->forge->addForeignKey('invoice_id', 'ar_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_files');

        // Append-only: the app never updates or deletes notes and actions.
        $this->forge->addField([
            'id' => $this->id(), 'invoice_id' => $this->uint(), 'user_id' => $this->uint(),
            'kind' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'note'], 'body' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addForeignKey('invoice_id', 'ar_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_notes');

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
        $this->forge->addForeignKey('invoice_id', 'ar_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_actions');

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
        $this->forge->addForeignKey('invoice_id', 'ar_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_documents');

        $this->forge->addField([
            'id' => $this->id(), 'invoice_id' => $this->uint(), 'step_order' => ['type' => 'INT', 'constraint' => 11],
            'team_id' => $this->uint(), 'claimed_by' => $this->uint(), 'claimed_at' => ['type' => 'DATETIME', 'null' => true],
            'released_at' => ['type' => 'DATETIME', 'null' => true], 'released_by' => $this->uint(true),
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['invoice_id', 'released_at']);
        $this->forge->addKey('claimed_by');
        $this->forge->addForeignKey('invoice_id', 'ar_invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ar_step_claims');
    }

    public function down(): void
    {
        foreach ([
            'ar_step_claims', 'ar_documents', 'ar_actions', 'ar_notes', 'ar_files', 'ar_payments', 'ar_invoices', 'ar_delegations',
            'ar_route_steps', 'ar_routes', 'ar_team_members', 'ar_teams', 'ar_route_variants', 'ar_invoice_types',
            'ar_default_invoice_types', 'ar_job_titles',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}

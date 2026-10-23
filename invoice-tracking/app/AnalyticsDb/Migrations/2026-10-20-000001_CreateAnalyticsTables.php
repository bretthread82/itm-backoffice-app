<?php

declare(strict_types=1);

namespace App\AnalyticsDb\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Long-term analytics store. Everything here is derived from the app database and can be rebuilt with
 * `analytics:backfill`. No note text, audit detail, files or SharePoint identifiers are ever copied.
 */
class CreateAnalyticsTables extends Migration
{
    protected $DBGroup = 'analytics';

    public function up(): void
    {
        $id = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true];

        $this->forge->addField([
            'company_id' => $id,
            'code'       => ['type' => 'VARCHAR', 'constraint' => 40],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'group_id'   => $id + ['null' => true],
            'group_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true], // ",1,4,9," root..own group, for sub-group filters
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addKey('company_id', true);
        $this->forge->createTable('dim_company');

        $this->forge->addField([
            'invoice_type_id' => $id,
            'company_id'      => $id,
            'name'            => ['type' => 'VARCHAR', 'constraint' => 200],
            'high_priority'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);
        $this->forge->addKey('invoice_type_id', true);
        $this->forge->createTable('dim_invoice_type');

        // One row per invoice, rebuilt whenever the invoice (or its actions) change. Timestamps describe the latest submission cycle.
        $this->forge->addField([
            'invoice_id'         => $id,
            'company_id'         => $id,
            'invoice_type_id'    => $id + ['null' => true],
            'supplier_code'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'supplier_name'      => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'currency'           => ['type' => 'VARCHAR', 'constraint' => 3],
            'amount'             => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 20],
            'outstanding'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'high_priority'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'extra_approval'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'invoice_date'       => ['type' => 'DATE', 'null' => true],
            'due_date'           => ['type' => 'DATE', 'null' => true],
            'first_submitted_at' => ['type' => 'DATETIME', 'null' => true],
            'submitted_at'       => ['type' => 'DATETIME', 'null' => true],
            'processed_at'       => ['type' => 'DATETIME', 'null' => true],
            'paid_at'            => ['type' => 'DATETIME', 'null' => true],
            'ended_at'           => ['type' => 'DATETIME', 'null' => true],
            'days_to_processed'  => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'days_to_paid'       => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'on_time'            => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true], // processed/paid on or before the due date
            'return_count'       => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'resubmit_count'     => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'override_count'     => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'step_count'         => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'loaded_at'          => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('invoice_id', true);
        $this->forge->addKey(['company_id', 'submitted_at']);
        $this->forge->addKey(['company_id', 'processed_at']);
        $this->forge->addKey(['company_id', 'paid_at']);
        $this->forge->addKey('invoice_type_id');
        $this->forge->addKey('supplier_code');
        $this->forge->addKey('status');
        $this->forge->createTable('fact_invoice');

        // One row per visit to an approval step. Rebuilt together with the invoice.
        $this->forge->addField([
            'id'               => $id + ['auto_increment' => true],
            'invoice_id'       => $id,
            'company_id'       => $id,
            'cycle'            => ['type' => 'INT', 'constraint' => 11, 'default' => 1], // 1 = first submission, 2+ = after a resubmit
            'step_order'       => ['type' => 'INT', 'constraint' => 11],
            'step_type'        => ['type' => 'VARCHAR', 'constraint' => 12],
            'assignee_kind'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'team_id'          => $id + ['null' => true],
            'user_id'          => $id + ['null' => true],
            'assignee_label'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'entered_at'       => ['type' => 'DATETIME'],
            'left_at'          => ['type' => 'DATETIME', 'null' => true],
            'duration_hours'   => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'outcome'          => ['type' => 'VARCHAR', 'constraint' => 20], // approved | returned | returned_previous | rejected | open
            'acted_by'         => $id + ['null' => true],
            'acted_by_name'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'override'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'sla_days'         => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'sla_breached'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'escalation_level' => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true], // highest of reminder | escalation reached
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addKey(['company_id', 'entered_at']);
        $this->forge->addKey(['step_type', 'entered_at']);
        $this->forge->addKey('team_id');
        $this->forge->addKey('acted_by');
        $this->forge->createTable('fact_step');

        // Outstanding invoices by ageing band, one set of rows per day. reconstructed = rebuilt from history, not observed.
        $this->forge->addField([
            'snapshot_date'  => ['type' => 'DATE'],
            'company_id'     => $id,
            'currency'       => ['type' => 'VARCHAR', 'constraint' => 3],
            'age_band'       => ['type' => 'VARCHAR', 'constraint' => 8],
            'invoice_count'  => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'amount'         => ['type' => 'DECIMAL', 'constraint' => '16,2', 'default' => 0],
            'reconstructed'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);
        $this->forge->addUniqueKey(['snapshot_date', 'company_id', 'currency', 'age_band']);
        $this->forge->addKey('company_id');
        $this->forge->createTable('snap_backlog_daily');

        $this->forge->addField([
            'source'         => ['type' => 'VARCHAR', 'constraint' => 40],
            'watermark'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'last_run'       => ['type' => 'DATETIME', 'null' => true],
            'rows_processed' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
        ]);
        $this->forge->addKey('source', true);
        $this->forge->createTable('etl_state');
    }

    public function down(): void
    {
        foreach (['etl_state', 'snap_backlog_daily', 'fact_step', 'fact_invoice', 'dim_invoice_type', 'dim_company'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}

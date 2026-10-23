<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInvoiceTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'company_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'invoice_type_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'route_id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'route_snapshot'     => ['type' => 'TEXT', 'null' => true], // JSON, fixed at submission
            'supplier'           => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'invoice_number'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'invoice_date'       => ['type' => 'DATE', 'null' => true],
            'due_date'           => ['type' => 'DATE', 'null' => true],
            'amount'             => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'currency'           => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'USD'],
            'po_reference'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'extra_approval'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
            'current_step_order' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'submitted_by'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'submitted_at'       => ['type' => 'DATETIME', 'null' => true],
            'completed_at'       => ['type' => 'DATETIME', 'null' => true],
            'ended_at'           => ['type' => 'DATETIME', 'null' => true], // completed, rejected or cancelled
            'sharepoint_item_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'sharepoint_url'     => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'sync_status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'synced_at'          => ['type' => 'DATETIME', 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('company_id');
        $this->forge->addKey('status');
        $this->forge->addKey('submitted_by');
        $this->forge->addKey('due_date');
        $this->forge->createTable('invoices');

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'stored_name'   => ['type' => 'VARCHAR', 'constraint' => 100],
            'mime'          => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => 'application/pdf'],
            'size_bytes'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'sha256'        => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'is_current'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'uploaded_by'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'uploaded_at'   => ['type' => 'DATETIME', 'null' => true],
            'purged_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->createTable('invoice_files');

        // Append-only. The app never updates or deletes these rows.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'kind'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'note'],
            'body'       => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->createTable('invoice_notes');

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'step_order'    => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'acted_by'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'on_behalf_of'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'acted_as'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'normal'],
            'action'        => ['type' => 'VARCHAR', 'constraint' => 20],
            'note_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'acted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->addKey('acted_as');
        $this->forge->createTable('invoice_actions');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'event'      => ['type' => 'VARCHAR', 'constraint' => 60],
            'entity'     => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'entity_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'detail'     => ['type' => 'TEXT', 'null' => true], // JSON
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('event');
        $this->forge->addKey(['entity', 'entity_id']);
        $this->forge->createTable('audit_log');

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'connection_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'attempts'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'next_attempt_at' => ['type' => 'DATETIME', 'null' => true],
            'last_error'    => ['type' => 'TEXT', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('invoice_id');
        $this->forge->addKey('status');
        $this->forge->createTable('sync_queue');

        // Bell alerts.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'invoice_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'event'      => ['type' => 'VARCHAR', 'constraint' => 60],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'body'       => ['type' => 'TEXT', 'null' => true],
            'url'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'read_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'read_at']);
        $this->forge->createTable('user_notifications');

        // Email log.
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'invoice_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'event'               => ['type' => 'VARCHAR', 'constraint' => 60],
            'to_email'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'subject'             => ['type' => 'VARCHAR', 'constraint' => 255],
            'body'                => ['type' => 'TEXT', 'null' => true],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'attempts'            => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'sendgrid_message_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'error'               => ['type' => 'TEXT', 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'sent_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('notifications');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'event'        => ['type' => 'VARCHAR', 'constraint' => 60],
            'email'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'daily_digest' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'event']);
        $this->forge->createTable('notification_prefs');
    }

    public function down(): void
    {
        foreach (['notification_prefs', 'notifications', 'user_notifications', 'sync_queue', 'audit_log', 'invoice_actions', 'invoice_notes', 'invoice_files', 'invoices'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}

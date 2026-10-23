<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Supporting documents attached to an invoice during approval (quotes, purchase orders, emails).
 * Kept apart from invoice_files so the invoice PDF logic (one current file, the SharePoint filing of that
 * PDF) is untouched. Append-only: rows are never edited or deleted, only marked purged by the retention job.
 */
class CreateInvoiceDocuments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'original_name'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'stored_name'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'mime'               => ['type' => 'VARCHAR', 'constraint' => 120],
            'size_bytes'         => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'sha256'             => ['type' => 'CHAR', 'constraint' => 64],
            'uploaded_by'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'acted_as'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'normal'],
            'note_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'uploaded_at'        => ['type' => 'DATETIME'],
            'purged_at'          => ['type' => 'DATETIME', 'null' => true],
            'sync_status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'sharepoint_url'     => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'sharepoint_item_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'synced_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->createTable('invoice_documents');
    }

    public function down(): void
    {
        $this->forge->dropTable('invoice_documents', true);
    }
}

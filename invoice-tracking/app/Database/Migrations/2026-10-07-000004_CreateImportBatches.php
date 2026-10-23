<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Uploaded routing exports. The file is kept outside the web root and re-analysed on demand,
 * so the review always reflects the current companies and users.
 */
class CreateImportBatches extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'filename'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'stored_name'  => ['type' => 'VARCHAR', 'constraint' => 100],
            'uploaded_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'uploaded'], // uploaded|committed|discarded
            'summary'      => ['type' => 'TEXT', 'null' => true], // JSON, set on commit
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'committed_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('routing_import_batches');
    }

    public function down(): void
    {
        $this->forge->dropTable('routing_import_batches', true);
    }
}

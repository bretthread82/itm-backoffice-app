<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSharePointFolder extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('sharepoint_connections', [
            'folder' => ['type' => 'VARCHAR', 'constraint' => 200, 'default' => 'Invoices'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('sharepoint_connections', 'folder');
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLogoToCompanies extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('companies', [
            'logo_file' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'logo_mime' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('companies', ['logo_file', 'logo_mime']);
    }
}

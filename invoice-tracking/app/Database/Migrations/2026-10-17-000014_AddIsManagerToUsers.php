<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIsManagerToUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'is_manager' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'is_admin'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'is_manager');
    }
}

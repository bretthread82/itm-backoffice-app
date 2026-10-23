<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIsWorkflowToUsers extends Migration
{
    public function up(): void
    {
        // Workflow Manager: may maintain invoice types, suppliers, approval routes and route variants, and nothing else.
        $this->forge->addColumn('users', [
            'is_workflow' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'is_manager'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'is_workflow');
    }
}

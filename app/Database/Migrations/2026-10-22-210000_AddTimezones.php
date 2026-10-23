<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Timestamps stay in UTC. A company has a default timezone; a user may override it on their profile.
 */
class AddTimezones extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('companies', [
            'timezone' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'name'],
        ]);
        $this->forge->addColumn('staff_profiles', [
            'timezone' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'theme'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('companies', 'timezone');
        $this->forge->dropColumn('staff_profiles', 'timezone');
    }
}

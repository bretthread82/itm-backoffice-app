<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRouteVariants extends Migration
{
    public function up(): void
    {
        // routes.variant holds variant_key. The standard variant ("default") always exists and is the fallback;
        // every other variant is chosen by the trigger set on its route.
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'variant_key' => ['type' => 'VARCHAR', 'constraint' => 20],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'is_standard' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'active'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sort_order'  => ['type' => 'INT', 'constraint' => 11, 'default' => 100],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('variant_key');
        $this->forge->createTable('route_variants');

        $now = date('Y-m-d H:i:s');
        $this->db->table('route_variants')->insertBatch([
            ['variant_key' => 'default', 'name' => 'Standard', 'is_standard' => 1, 'active' => 1, 'sort_order' => 0, 'created_at' => $now],
            ['variant_key' => 'extra_approval', 'name' => '- Yes', 'is_standard' => 0, 'active' => 1, 'sort_order' => 10, 'created_at' => $now],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('route_variants', true);
    }
}

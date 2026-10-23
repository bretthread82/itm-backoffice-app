<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameYesVariantToHighValue extends Migration
{
    public function up(): void
    {
        // The customer confirmed the "- Yes" routes are the extra approval for higher-value invoices.
        // The variant key stays (routes and invoices point at it); only the display name changes,
        // and only if an administrator has not already renamed it.
        $this->db->table('route_variants')->where(['variant_key' => 'extra_approval', 'name' => '- Yes'])->update(['name' => 'High Value']);
    }

    public function down(): void
    {
        $this->db->table('route_variants')->where(['variant_key' => 'extra_approval', 'name' => 'High Value'])->update(['name' => '- Yes']);
    }
}

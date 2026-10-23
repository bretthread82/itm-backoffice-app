<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The top-level group. Sub-groups are organisational only and added by admins.
        if ($this->db->table('company_groups')->countAllResults() === 0) {
            $this->db->table('company_groups')->insert([
                'name'       => 'Maloney Group',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->call(DefaultAdminSeeder::class);
    }
}

<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Config\Backoffice;

/**
 * Idempotent: syncs permissions from ConfigBackoffice and creates/updates the
 * system roles. Safe to re-run after adding a module.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $config = config(Backoffice::class);
        $now    = date('Y-m-d H:i:s');

        foreach ($config->modules as $module => $def) {
            foreach ($def['permissions'] as $key => $description) {
                $row = ['key' => $key, 'module' => $module, 'description' => $description];
                $existing = $this->db->table('permissions')->where('key', $key)->get()->getRowArray();

                $existing
                    ? $this->db->table('permissions')->where('id', $existing['id'])->update($row)
                    : $this->db->table('permissions')->insert($row);
            }
        }

        $permissionIds = array_column($this->db->table('permissions')->get()->getResultArray(), 'id', 'key');

        foreach ($config->roleDefinitions() as $key => $def) {
            $role = $this->db->table('roles')->where('key', $key)->get()->getRowArray();

            if ($role) {
                $this->db->table('roles')->where('id', $role['id'])
                    ->update(['name' => $def['name'], 'description' => $def['description'], 'is_system' => 1, 'updated_at' => $now]);
                $roleId = (int) $role['id'];
            } else {
                $this->db->table('roles')->insert([
                    'key' => $key, 'name' => $def['name'], 'description' => $def['description'],
                    'is_system' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $roleId = (int) $this->db->insertID();
            }

            // Only add missing grants; never strip permissions an admin added to a system role.
            $wanted = $def['permissions'] === ['*'] ? array_keys($permissionIds) : $def['permissions'];

            foreach ($wanted as $permission) {
                if (! isset($permissionIds[$permission])) {
                    continue;
                }

                $pair = ['role_id' => $roleId, 'permission_id' => $permissionIds[$permission]];

                if (! $this->db->table('role_permissions')->where($pair)->countAllResults()) {
                    $this->db->table('role_permissions')->insert($pair);
                }
            }
        }
    }
}

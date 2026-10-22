<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The first version of staff_profiles declared department_id / manager_id with
 * the onUpdate/onDelete actions swapped (ON DELETE CASCADE), so deleting a
 * department or a manager deleted other people's profiles. Databases created
 * before the fix need these two keys rebuilt. On a fresh install the keys are
 * already correct and this simply recreates them identically.
 */
class FixStaffProfileForeignKeys extends Migration
{
    public function up(): void
    {
        $this->rebuild('SET NULL');
    }

    public function down(): void
    {
        // Deliberately keeps the safe behaviour: never re-introduce the cascade.
    }

    private function rebuild(string $onDelete): void
    {
        $db = $this->db;

        foreach (['department_id' => 'departments', 'manager_id' => 'users'] as $column => $parent) {
            $name = "staff_profiles_{$column}_foreign";

            $exists = $db->query(
                'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() '
                . "AND TABLE_NAME = 'staff_profiles' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$name],
            )->getNumRows() > 0;

            if ($exists) {
                $db->query("ALTER TABLE staff_profiles DROP FOREIGN KEY `{$name}`");
            }

            $db->query(
                "ALTER TABLE staff_profiles ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) "
                . "REFERENCES `{$parent}` (`id`) ON DELETE {$onDelete} ON UPDATE CASCADE",
            );
        }
    }
}

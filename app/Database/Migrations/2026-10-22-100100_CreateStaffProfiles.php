<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStaffProfiles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'user_id'         => ['type' => 'INT', 'unsigned' => true],
            'employee_no'     => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'first_name'      => ['type' => 'VARCHAR', 'constraint' => 80],
            'last_name'       => ['type' => 'VARCHAR', 'constraint' => 80],
            'display_name'    => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'job_title'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'department_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'manager_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'phone'           => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'mobile'          => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'location'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'bio'             => ['type' => 'TEXT', 'null' => true],
            'start_date'      => ['type' => 'DATE', 'null' => true],
            'avatar_path'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'entra_object_id' => ['type' => 'CHAR', 'constraint' => 36, 'null' => true],
            'source'          => ['type' => 'ENUM', 'constraint' => ['local', 'entra'], 'default' => 'local'],
            'status'          => ['type' => 'ENUM', 'constraint' => ['active', 'inactive', 'suspended'], 'default' => 'active'],
            'theme'           => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'auto'],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addUniqueKey('entra_object_id');
        $this->forge->addUniqueKey('employee_no');
        $this->forge->addKey('department_id');
        $this->forge->addKey('manager_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('department_id', 'departments', 'id', 'CASCADE', 'SET NULL'); // (onUpdate, onDelete)
        $this->forge->addForeignKey('manager_id', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('staff_profiles');
    }

    public function down(): void
    {
        $this->forge->dropTable('staff_profiles', true);
    }
}

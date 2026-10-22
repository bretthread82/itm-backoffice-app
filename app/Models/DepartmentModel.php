<?php

namespace App\Models;

use CodeIgniter\Model;

class DepartmentModel extends Model
{
    protected $table         = 'departments';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'parent_id', 'head_user_id'];

    /**
     * @return list<array<string, mixed>>
     */
    public function withCounts(): array
    {
        return $this->db->query(
            'SELECT d.*, p.name AS parent_name, '
            . '(SELECT COUNT(*) FROM staff_profiles sp WHERE sp.department_id = d.id AND sp.deleted_at IS NULL) AS staff_count '
            . 'FROM departments d LEFT JOIN departments p ON p.id = d.parent_id ORDER BY d.name',
        )->getResultArray();
    }
}

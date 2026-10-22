<?php

namespace App\Models;

use CodeIgniter\Model;

class StaffProfileModel extends Model
{
    protected $table            = 'staff_profiles';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'user_id', 'employee_no', 'first_name', 'last_name', 'display_name', 'job_title',
        'department_id', 'manager_id', 'phone', 'mobile', 'location', 'bio', 'start_date',
        'avatar_path', 'entra_object_id', 'source', 'status', 'theme',
    ];

    /**
     * Staff rows joined with login email, department and role names.
     * Filters: q (text), status, department_id, role_id.
     *
     * @param array<string, mixed> $filters
     */
    public function listing(array $filters = []): \CodeIgniter\Database\BaseBuilder
    {
        $builder = $this->db->table('staff_profiles sp')
            ->select('sp.*, u.id AS id, ai.secret AS email, d.name AS department, '
                . "(SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = sp.user_id) AS roles", false)
            ->join('users u', 'u.id = sp.user_id AND u.deleted_at IS NULL')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left')
            ->join('departments d', 'd.id = sp.department_id', 'left')
            ->where('sp.deleted_at', null);

        if (! empty($filters['q'])) {
            $builder->groupStart()
                ->like('sp.first_name', $filters['q'])
                ->orLike('sp.last_name', $filters['q'])
                ->orLike('sp.display_name', $filters['q'])
                ->orLike('sp.job_title', $filters['q'])
                ->orLike('ai.secret', $filters['q'])
                ->orLike('sp.employee_no', $filters['q'])
                ->groupEnd();
        }

        if (! empty($filters['status'])) {
            $builder->where('sp.status', $filters['status']);
        }

        if (! empty($filters['department_id'])) {
            $builder->where('sp.department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['role_id'])) {
            $builder->where('EXISTS (SELECT 1 FROM user_roles x WHERE x.user_id = sp.user_id AND x.role_id = ' . (int) $filters['role_id'] . ')', null, false);
        }

        if (! empty($filters['location'])) {
            $builder->where('sp.location', $filters['location']);
        }

        return $builder;
    }
}

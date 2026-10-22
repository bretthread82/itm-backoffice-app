<?php

namespace App\Models;

use CodeIgniter\Model;

class RoleModel extends Model
{
    protected $table         = 'roles';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['key', 'name', 'description', 'is_system'];

    /**
     * Roles with member and permission counts.
     *
     * @return list<array<string, mixed>>
     */
    public function withCounts(): array
    {
        return $this->db->query(
            'SELECT r.*, '
            . '(SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS members, '
            . '(SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count '
            . 'FROM roles r ORDER BY r.is_system DESC, r.name',
        )->getResultArray();
    }

    /**
     * @return list<int>
     */
    public function permissionIds(int $roleId): array
    {
        return array_map('intval', array_column(
            $this->db->table('role_permissions')->where('role_id', $roleId)->get()->getResultArray(),
            'permission_id',
        ));
    }

    /**
     * @return list<int>
     */
    public function memberIds(int $roleId): array
    {
        return array_map('intval', array_column(
            $this->db->table('user_roles')->where('role_id', $roleId)->get()->getResultArray(),
            'user_id',
        ));
    }
}

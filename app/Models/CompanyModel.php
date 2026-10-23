<?php

namespace App\Models;

use CodeIgniter\Model;

class CompanyModel extends Model
{
    protected $table         = 'companies';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['group_id', 'code', 'name', 'timezone', 'active', 'logo_file', 'logo_mime'];

    /**
     * @return list<array<string, mixed>>
     */
    public function withGroups(): array
    {
        return $this->db->query(
            'SELECT c.*, g.name AS group_name, '
            . '(SELECT COUNT(*) FROM user_companies uc WHERE uc.company_id = c.id) AS member_count '
            . 'FROM companies c LEFT JOIN company_groups g ON g.id = c.group_id ORDER BY c.name',
        )->getResultArray();
    }
}

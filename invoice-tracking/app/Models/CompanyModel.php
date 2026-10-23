<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CompanyModel extends Model
{
    protected $table          = 'companies';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $allowedFields  = ['group_id', 'code', 'name', 'active', 'sharepoint_connection_id', 'logo_file', 'logo_mime'];

    /** @return list<array<string, mixed>> with the sub-group name attached */
    public function withGroups(): array
    {
        return $this->select('companies.*, company_groups.name AS group_name, sharepoint_connections.name AS connection_name')
            ->join('company_groups', 'company_groups.id = companies.group_id', 'left')
            ->join('sharepoint_connections', 'sharepoint_connections.id = companies.sharepoint_connection_id', 'left')
            ->orderBy('companies.code')
            ->findAll();
    }
}

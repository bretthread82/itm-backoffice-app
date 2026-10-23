<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Sub-groups are organisational only. They never grant or limit access.
 */
class CompanyGroupModel extends Model
{
    protected $table         = 'company_groups';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['parent_id', 'name'];
}

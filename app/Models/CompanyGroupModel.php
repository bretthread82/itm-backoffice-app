<?php

namespace App\Models;

use CodeIgniter\Model;

class CompanyGroupModel extends Model
{
    protected $table         = 'company_groups';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['parent_id', 'name'];
}

<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Shield\Models\UserModel as ShieldUserModel;

/**
 * Shield's user model plus the columns this app adds to the users table.
 */
class UserModel extends ShieldUserModel
{
    protected function initialize(): void
    {
        parent::initialize();

        $this->allowedFields = [
            ...$this->allowedFields,
            'display_name',
            'auth_source',
            'entra_oid',
            'is_admin',
            'is_manager',
            'is_workflow',
            'email_missing',
        ];
    }
}

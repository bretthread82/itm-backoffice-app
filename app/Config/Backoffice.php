<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Module registry. Each module declares the permissions it owns and the
 * sidebar entries it contributes. The core builds navigation and seeds the
 * permissions table from this list, so adding a module means adding an entry.
 *
 * Menu item: label, icon (Bootstrap Icons name), url, permission (null = any
 * signed-in user), order.
 */
class Backoffice extends BaseConfig
{
    /**
     * Role key that implicitly holds every permission.
     */
    public string $superRole = 'super_admin';

    /**
     * @var array<string, array{permissions: array<string, string>, menu: list<array<string, mixed>>}>
     */
    public array $modules = [
        'core' => [
            'permissions' => [],
            'menu'        => [
                ['label' => 'Dashboard', 'icon' => 'speedometer2', 'url' => '/', 'permission' => null, 'order' => 10],
            ],
        ],
        'directory' => [
            'permissions' => [
                'directory.view'    => 'View the staff directory',
                'departments.manage' => 'Create and edit departments',
            ],
            'menu' => [
                ['label' => 'Directory', 'icon' => 'person-lines-fill', 'url' => 'directory', 'permission' => 'directory.view', 'order' => 20],
                ['label' => 'Departments', 'icon' => 'diagram-3', 'url' => 'admin/departments', 'permission' => 'departments.manage', 'order' => 35],
            ],
        ],
        'users' => [
            'permissions' => [
                'users.view'         => 'View user accounts',
                'users.create'       => 'Create user accounts',
                'users.edit'         => 'Edit user accounts',
                'users.delete'       => 'Deactivate and delete user accounts',
                'users.assign_roles' => 'Assign roles to users',
            ],
            'menu' => [
                ['label' => 'Users', 'icon' => 'people', 'url' => 'admin/users', 'permission' => 'users.view', 'order' => 30],
                ['label' => 'Change requests', 'icon' => 'inbox', 'url' => 'admin/requests', 'permission' => 'users.edit', 'order' => 32],
            ],
        ],
        'roles' => [
            'permissions' => [
                'roles.manage' => 'Create roles and edit their permissions',
            ],
            'menu' => [
                ['label' => 'Roles', 'icon' => 'shield-lock', 'url' => 'admin/roles', 'permission' => 'roles.manage', 'order' => 40],
            ],
        ],
        'audit' => [
            'permissions' => [
                'audit.view' => 'View the audit log',
            ],
            'menu' => [
                ['label' => 'Audit log', 'icon' => 'journal-text', 'url' => 'admin/audit', 'permission' => 'audit.view', 'order' => 50],
            ],
        ],
        'mfa' => [
            'permissions' => [
                'mfa.manage' => 'Turn multi-factor authentication on or off and choose who must use it',
            ],
            'menu' => [
                ['label' => 'Multi-factor auth', 'icon' => 'shield-check', 'url' => 'admin/mfa', 'permission' => 'mfa.manage', 'order' => 55],
            ],
        ],
        'entra' => [
            'permissions' => [
                'entra.manage' => 'Configure Microsoft Entra and run directory sync',
            ],
            'menu' => [
                ['label' => 'Microsoft Entra', 'icon' => 'microsoft', 'url' => 'admin/entra', 'permission' => 'entra.manage', 'order' => 60],
            ],
        ],
    ];

    /**
     * System roles and the permissions they receive on seeding. '*' = all.
     *
     * @var array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public array $systemRoles = [
        'super_admin' => [
            'name'        => 'Super Admin',
            'description' => 'Full access to everything, including future modules.',
            'permissions' => ['*'],
        ],
        'admin' => [
            'name'        => 'Admin',
            'description' => 'Manages users, departments and the audit log.',
            'permissions' => ['directory.view', 'departments.manage', 'users.view', 'users.create', 'users.edit', 'users.delete', 'users.assign_roles', 'audit.view'],
        ],
        'manager' => [
            'name'        => 'Manager',
            'description' => 'Can view users and the staff directory.',
            'permissions' => ['directory.view', 'users.view'],
        ],
        'staff' => [
            'name'        => 'Staff',
            'description' => 'Standard staff member.',
            'permissions' => ['directory.view'],
        ],
    ];

    /**
     * Notification types. Modules add their own. 'locked' types cannot be
     * switched off in-app (security-relevant); email can still be disabled.
     *
     * @var array<string, array{label: string, in_app: bool, email: bool, locked?: bool}>
     */
    public array $notificationTypes = [
        'account.created'         => ['label' => 'Account created', 'in_app' => true, 'email' => true, 'locked' => true],
        'account.roles_changed'   => ['label' => 'My roles changed', 'in_app' => true, 'email' => false],
        'account.password_reset'  => ['label' => 'Password reset by an administrator', 'in_app' => true, 'email' => true, 'locked' => true],
        'account.password_changed' => ['label' => 'My password changed', 'in_app' => true, 'email' => true, 'locked' => true],
        'account.mfa_changed'     => ['label' => 'My two-factor settings changed', 'in_app' => true, 'email' => true, 'locked' => true],
        'profile.request_new'     => ['label' => 'New profile change request (approvers)', 'in_app' => true, 'email' => false],
        'profile.request_decided' => ['label' => 'My profile change request decided', 'in_app' => true, 'email' => true],
        'entra.sync_failed'       => ['label' => 'Entra directory sync failed (admins)', 'in_app' => true, 'email' => true],
    ];

    /**
     * Password reset by emailed link: how long a link lives, and how many links one
     * account may be sent per hour.
     */
    public int $passwordResetTtlMinutes = 60;
    public int $passwordResetMaxPerHour = 3;

    /**
     * Development only (ignored in production): write outgoing email to
     * writable/logs/mail.log instead of sending it. Set backoffice.mailLogOnly = true in .env.
     * Reset links are written in clear text there, so never use it on a real system.
     */
    public bool $mailLogOnly = false;

    /**
     * Role keys given to a newly created or provisioned user by default.
     *
     * @var list<string>
     */
    public array $defaultRoles = ['staff'];

    /**
     * @return array<string, string> permission key => description
     */
    public function allPermissions(): array
    {
        $all = [];

        foreach ($this->modules as $module) {
            $all += $module['permissions'];
        }

        return $all;
    }
}

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
     * Named sidebar groups shared across modules. A menu item joins one with 'group' => '<key>'.
     * (A module can also fold all its own items under one heading with a module-level 'group' array.)
     *
     * @var array<string, array{label: string, icon: string, order: int}>
     */
    public array $navGroups = [
        'admin' => ['label' => 'Administration', 'icon' => 'gear', 'order' => 80],
    ];

    /**
     * Role key that implicitly holds every permission.
     */
    public string $superRole = 'super_admin';

    /**
     * Platform version (major.minor.bug). Bump with `php spark version:bump platform <major|minor|bug>`.
     * Each module below carries its own 'version' in the same format.
     */
    public string $platformVersion = '1.3.0';

    /**
     * A module that needs per-user, per-company rights declares them in 'companyAccess'
     * (key => ['label', 'help', optional legacy 'column']); they appear on Manage company access
     * and are checked with service('scope')->can($userId, $companyId, '<module>.<key>').
     *
     * @var array<string, array{version: string, roles?: array<string, array{name: string, description: string, permissions: list<string>, key?: string}>, permissions: array<string, string>, menu: list<array<string, mixed>>, companyAccess?: array<string, array{label: string, help?: string, column?: string}>, optional?: bool, title?: string, description?: string, dashboard?: array{label: string, icon: string, permission: string, order: int, provider: class-string}}>
     */
    public array $modules = [
        'core' => [
            'version'     => '1.3.0',
            'permissions' => [],
            'menu'        => [
                ['label' => 'Dashboard', 'icon' => 'speedometer2', 'url' => '/', 'permission' => null, 'order' => 10],
            ],
        ],
        'directory' => [
            'version'     => '1.2.0',
            'roles'       => [
                'user' => [
                    'name'        => 'Directory User',
                    'description' => 'Browse the staff directory.',
                    'permissions' => ['directory.view'],
                ],
                'manager' => [
                    'name'        => 'Directory Manager',
                    'description' => 'Everything a Directory User can do, plus create and edit departments.',
                    'permissions' => ['departments.manage'],
                ],
            ],
            'optional'    => true,
            'title'       => 'Staff directory',
            'description' => 'Searchable staff directory and departments.',
            'permissions' => [
                'directory.view'    => 'View the staff directory',
                'departments.manage' => 'Create and edit departments',
            ],
            'menu' => [
                ['label' => 'Directory', 'icon' => 'person-lines-fill', 'url' => 'directory', 'permission' => 'directory.view', 'order' => 20],
                ['label' => 'Departments', 'icon' => 'diagram-3', 'url' => 'admin/departments', 'permission' => 'departments.manage', 'order' => 35, 'group' => 'admin'],
            ],
        ],
        'users' => [
            'version'     => '1.2.0',
            'roles'       => [
                'user' => [
                    'name'        => 'Users User',
                    'description' => 'View user accounts.',
                    'permissions' => ['users.view'],
                ],
                'manager' => [
                    'name'        => 'Users Manager',
                    'description' => 'Everything a Users User can do, plus create, edit, deactivate and assign roles to accounts.',
                    'permissions' => ['users.create', 'users.edit', 'users.delete', 'users.assign_roles'],
                ],
            ],
            'dashboard'   => ['label' => 'People', 'icon' => 'people', 'permission' => 'users.edit', 'order' => 20, 'provider' => \App\Core\Users\DashboardTab::class],
            'permissions' => [
                'users.view'         => 'View user accounts',
                'users.create'       => 'Create user accounts',
                'users.edit'         => 'Edit user accounts',
                'users.delete'       => 'Deactivate and delete user accounts',
                'users.assign_roles' => 'Assign roles to users',
            ],
            'menu' => [
                ['label' => 'Users', 'icon' => 'people', 'url' => 'admin/users', 'permission' => 'users.view', 'order' => 30, 'group' => 'admin'],
                ['label' => 'Change requests', 'icon' => 'inbox', 'url' => 'admin/requests', 'permission' => 'users.edit', 'order' => 32, 'group' => 'admin'],
            ],
        ],
        'roles' => [
            'version'     => '1.1.0',
            'roles'       => [
                'manager' => [
                    'name'        => 'Roles Manager',
                    'description' => 'Create roles and edit their permissions. This is effectively full access, so assign it with care.',
                    'permissions' => ['roles.manage'],
                ],
            ],
            'permissions' => [
                'roles.manage' => 'Create roles and edit their permissions',
            ],
            'menu' => [
                ['label' => 'Roles', 'icon' => 'shield-lock', 'url' => 'admin/roles', 'permission' => 'roles.manage', 'order' => 40, 'group' => 'admin'],
            ],
        ],
        'tenancy' => [
            'version'     => '1.2.0',
            'roles'       => [
                'manager' => [
                    'name'        => 'Companies Manager',
                    'description' => 'Create and edit companies and groups, and choose which companies each user belongs to. Group-level access stays with Super Admin.',
                    'permissions' => ['companies.manage', 'tenancy.memberships'],
                ],
            ],
            'permissions' => [
                'companies.manage'    => 'Create and edit companies and company groups',
                'tenancy.memberships' => 'Choose which companies each user belongs to and can submit or approve for',
                'tenancy.group_level' => 'Grant or remove group-level access (sees every company)',
            ],
            'menu' => [
                ['label' => 'Companies', 'icon' => 'buildings', 'url' => 'admin/companies', 'permission' => 'companies.manage', 'order' => 36, 'group' => 'admin'],
                ['label' => 'Group-level users', 'icon' => 'globe2', 'url' => 'admin/group-level', 'permission' => 'tenancy.group_level', 'order' => 37, 'group' => 'admin'],
            ],
        ],
        'invoices' => [
            'version'     => '1.5.0',
            'roles'       => [
                'user' => [
                    'key'         => 'invoice_user',
                    'name'        => 'Invoices User',
                    'description' => 'Opens the invoice register and My tasks, creates and submits invoices, approves for companies they belong to, runs reports. Company rights decide what they see.',
                    'permissions' => ['invoices.view', 'invoices.reports', 'suppliers.view'],
                ],
                'manager' => [
                    'key'         => 'invoice_workflow',
                    'name'        => 'Invoices Manager',
                    'description' => 'Everything an Invoices User can do, plus approval routes, route variants, invoice types, teams, delegations for others and the supplier list.',
                    'permissions' => ['invoices.manage', 'invoices.workflow', 'suppliers.manage'],
                ],
            ],
            'optional'    => true,
            'title'       => 'Invoices',
            'description' => 'Invoice register and approval workflow: routes, teams, delegations, reports.',
            // Sidebar: this module's user-facing entries sit under one collapsible heading; its admin entries join 'admin'.
            'group'       => ['label' => 'Invoices', 'icon' => 'receipt', 'order' => 69],
            'dashboard'   => ['label' => 'Invoices', 'icon' => 'receipt', 'permission' => 'invoices.view', 'order' => 10, 'provider' => \App\Core\Invoices\DashboardTab::class],
            'companyAccess' => [
                'submit'  => ['label' => 'Can submit', 'help' => 'Create and submit invoices for the company.', 'column' => 'can_submit'],
                'approve' => ['label' => 'Can approve', 'help' => 'Approve invoice steps for the company.', 'column' => 'can_approve'],
            ],
            'permissions' => [
                'invoices.view'     => 'Open the invoice register, My tasks and the dashboard figures for companies you belong to',
                'invoices.reports'  => 'Run the outstanding and supplier reports',
                'invoices.manage'   => 'Manage teams, delegations for others and transfer invoice work when someone leaves',
                'invoices.workflow' => 'Maintain approval routes, route variants and invoice types',
            ],
            'menu' => [
                ['label' => 'Invoice overview', 'icon' => 'speedometer', 'url' => 'invoices/overview', 'permission' => 'invoices.view', 'order' => 69],
                ['label' => 'My tasks', 'icon' => 'check2-square', 'url' => 'approvals', 'permission' => 'invoices.view', 'order' => 70, 'badge' => 'invoices.waiting'],
                ['label' => 'All invoices', 'icon' => 'file-earmark-text', 'url' => 'invoices', 'permission' => 'invoices.view', 'order' => 71, 'unless' => 'status'],
                ['label' => 'Drafts', 'icon' => 'pencil-square', 'url' => 'invoices?status=draft', 'permission' => 'invoices.view', 'order' => 71.1],
                ['label' => 'In approval', 'icon' => 'hourglass-split', 'url' => 'invoices?status=open', 'permission' => 'invoices.view', 'order' => 71.2],
                ['label' => 'Approved', 'icon' => 'check-circle', 'url' => 'invoices?status=approved', 'permission' => 'invoices.view', 'order' => 71.3],
                ['label' => 'Returned', 'icon' => 'arrow-return-left', 'url' => 'invoices?status=returned', 'permission' => 'invoices.view', 'order' => 71.4],
                ['label' => 'Rejected', 'icon' => 'x-circle', 'url' => 'invoices?status=rejected', 'permission' => 'invoices.view', 'order' => 71.5],
                ['label' => 'Processed', 'icon' => 'gear', 'url' => 'invoices?status=processed', 'permission' => 'invoices.view', 'order' => 71.6],
                ['label' => 'Paid', 'icon' => 'cash-coin', 'url' => 'invoices?status=paid', 'permission' => 'invoices.view', 'order' => 71.7],
                ['label' => 'Invoice reports', 'icon' => 'bar-chart-line', 'url' => 'reports', 'permission' => 'invoices.reports', 'order' => 72],
                ['label' => 'Approval routes', 'icon' => 'diagram-2', 'url' => 'invoices-admin/routes', 'permission' => 'invoices.workflow', 'order' => 73, 'group' => 'admin'],
                ['label' => 'Teams', 'icon' => 'people-fill', 'url' => 'invoices-admin/teams', 'permission' => 'invoices.manage', 'order' => 74, 'group' => 'admin'],
                ['label' => 'Invoice types', 'icon' => 'tags', 'url' => 'invoices-admin/default-invoice-types', 'permission' => 'invoices.workflow', 'order' => 76, 'group' => 'admin'],
                ['label' => 'Route variants', 'icon' => 'signpost-split', 'url' => 'invoices-admin/route-variants', 'permission' => 'invoices.workflow', 'order' => 77, 'group' => 'admin'],
            ],
        ],
        'modules' => [
            'version'     => '1.1.0',
            'roles'       => [
                'manager' => [
                    'name'        => 'Modules Manager',
                    'description' => 'Switch optional modules on or off.',
                    'permissions' => ['modules.manage'],
                ],
            ],
            'permissions' => [
                'modules.manage' => 'Switch optional modules on or off',
            ],
            'menu' => [
                ['label' => 'Modules', 'icon' => 'puzzle', 'url' => 'admin/modules', 'permission' => 'modules.manage', 'order' => 65, 'group' => 'admin'],
            ],
        ],
        'suppliers' => [
            'version'     => '1.1.0',
            'roles'       => [
                'user' => [
                    'name'        => 'Suppliers User',
                    'description' => 'Pick from and search the shared supplier list.',
                    'permissions' => ['suppliers.view'],
                ],
                'manager' => [
                    'name'        => 'Suppliers Manager',
                    'description' => 'Everything a Suppliers User can do, plus add, edit, switch off and bulk upload suppliers.',
                    'permissions' => ['suppliers.manage'],
                ],
            ],
            'permissions' => [
                'suppliers.view'   => 'Pick from and search the shared supplier list (modules that use suppliers need this)',
                'suppliers.manage' => 'Add, edit, switch off and bulk upload suppliers',
            ],
            'menu' => [
                ['label' => 'Suppliers', 'icon' => 'shop', 'url' => 'admin/suppliers', 'permission' => 'suppliers.manage', 'order' => 66, 'group' => 'admin'],
            ],
        ],
        'audit' => [
            'version'     => '1.1.0',
            'roles'       => [
                'manager' => [
                    'name'        => 'Audit Manager',
                    'description' => 'View the audit log.',
                    'permissions' => ['audit.view'],
                ],
            ],
            'permissions' => [
                'audit.view' => 'View the audit log',
            ],
            'menu' => [
                ['label' => 'Audit log', 'icon' => 'journal-text', 'url' => 'admin/audit', 'permission' => 'audit.view', 'order' => 50, 'group' => 'admin'],
            ],
        ],
        'mfa' => [
            'version'     => '1.1.0',
            'roles'       => [
                'manager' => [
                    'name'        => 'MFA Manager',
                    'description' => 'Turn multi-factor authentication on or off and choose who must use it.',
                    'permissions' => ['mfa.manage'],
                ],
            ],
            'permissions' => [
                'mfa.manage' => 'Turn multi-factor authentication on or off and choose who must use it',
            ],
            'menu' => [
                ['label' => 'Multi-factor auth', 'icon' => 'shield-check', 'url' => 'admin/mfa', 'permission' => 'mfa.manage', 'order' => 55, 'group' => 'admin'],
            ],
        ],
        'entra' => [
            'version'     => '1.1.0',
            'roles'       => [
                'manager' => [
                    'name'        => 'Entra Manager',
                    'description' => 'Configure Microsoft Entra and run directory sync.',
                    'permissions' => ['entra.manage'],
                ],
            ],
            'permissions' => [
                'entra.manage' => 'Configure Microsoft Entra and run directory sync',
            ],
            'menu' => [
                ['label' => 'Microsoft Entra', 'icon' => 'microsoft', 'url' => 'admin/entra', 'permission' => 'entra.manage', 'order' => 60, 'group' => 'admin'],
            ],
        ],
    ];

    /**
     * Broad system roles and the permissions they receive on seeding. '*' = all.
     * Per-module User and Manager roles are declared in each module's 'roles' entry instead;
     * use roleDefinitions() to get both together.
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
            'permissions' => ['directory.view', 'departments.manage', 'users.view', 'users.create', 'users.edit', 'users.delete', 'users.assign_roles', 'audit.view', 'companies.manage', 'tenancy.memberships', 'suppliers.view', 'suppliers.manage', 'invoices.view', 'invoices.reports', 'invoices.manage', 'invoices.workflow'],
        ],
        'manager' => [
            'name'        => 'Manager',
            'description' => 'Can view users and the staff directory.',
            'permissions' => ['directory.view', 'users.view', 'suppliers.view', 'invoices.view', 'invoices.reports', 'invoices.manage', 'invoices.workflow'],
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
        'invoice.submitted'      => ['label' => 'An invoice is waiting for my approval', 'in_app' => true, 'email' => true],
        'invoice.received'       => ['label' => 'My invoice was submitted', 'in_app' => true, 'email' => true],
        'invoice.step_approved'  => ['label' => 'My invoice moved to the next step', 'in_app' => true, 'email' => true],
        'invoice.processed'      => ['label' => 'My invoice was processed', 'in_app' => true, 'email' => true],
        'invoice.paid'           => ['label' => 'My invoice was paid', 'in_app' => true, 'email' => true],
        'invoice.rejected'       => ['label' => 'My invoice was rejected', 'in_app' => true, 'email' => true],
        'invoice.returned'       => ['label' => 'My invoice was returned for changes', 'in_app' => true, 'email' => true],
        'invoice.resubmitted'    => ['label' => 'An invoice I approve was resubmitted', 'in_app' => true, 'email' => true],
        'invoice.returned_step'  => ['label' => 'An invoice was returned to me by the next approver', 'in_app' => true, 'email' => true],
        'invoice.override'       => ['label' => 'A group-level user acted on my step or invoice', 'in_app' => true, 'email' => true],
        'invoice.notify_step'    => ['label' => 'I am copied on an invoice', 'in_app' => true, 'email' => true],
        'invoice.claimed'        => ['label' => 'A team member accepted an invoice', 'in_app' => true, 'email' => true],
        'invoice.released'       => ['label' => 'An invoice was released back to my team', 'in_app' => true, 'email' => true],
        'invoice.document_added' => ['label' => 'A supporting document was added to my invoice', 'in_app' => true, 'email' => true],
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
     * Every system role: the broad ones plus the User / Manager roles each module declares in its
     * 'roles' entry. A module role key defaults to <module>_user / <module>_manager (or its 'key');
     * a manager holds the user permissions plus its own, so one role per module is enough.
     *
     * @return array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public function roleDefinitions(): array
    {
        $roles = $this->systemRoles;

        foreach ($this->modules as $module => $def) {
            $user = $def['roles']['user']['permissions'] ?? [];

            foreach (['user', 'manager'] as $tier) {
                if (! isset($def['roles'][$tier])) {
                    continue;
                }

                $role        = $def['roles'][$tier];
                $permissions = $tier === 'manager' ? array_merge($user, $role['permissions']) : $role['permissions'];

                $roles[$role['key'] ?? "{$module}_{$tier}"] = [
                    'name'        => $role['name'],
                    'description' => $role['description'],
                    'permissions' => array_values(array_unique($permissions)),
                ];
            }
        }

        return $roles;
    }

    /**
     * Every per-company capability the modules declare under 'companyAccess', keyed '<module>.<key>'.
     * These become the checkbox columns on Manage company access. 'column' maps a capability to a
     * legacy flag on user_companies; without it the capability lives in user_company_capabilities.
     * Does not filter out switched-off modules; Memberships::capabilities() does.
     *
     * @return array<string, array{module: string, label: string, help: string, column: string|null}>
     */
    public function companyCapabilities(): array
    {
        $all = [];

        foreach ($this->modules as $module => $def) {
            foreach ($def['companyAccess'] ?? [] as $key => $cap) {
                $all["{$module}.{$key}"] = [
                    'module' => (string) $module,
                    'label'  => $cap['label'],
                    'help'   => $cap['help'] ?? '',
                    'column' => $cap['column'] ?? null,
                ];
            }
        }

        return $all;
    }

    /**
     * Dashboard tabs declared by modules, keyed by module. A tab is a label, icon, the permission that
     * opens it, an order and a provider class implementing App\Core\Dashboard\DashboardTab.
     *
     * @return array<string, array{label: string, icon: string, permission: string, order: int, provider: class-string}>
     */
    public function dashboardTabs(): array
    {
        $tabs = [];

        foreach ($this->modules as $key => $module) {
            if (isset($module['dashboard'])) {
                $tabs[$key] = $module['dashboard'];
            }
        }

        return $tabs;
    }

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

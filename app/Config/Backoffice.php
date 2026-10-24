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
     * @var array<string, array{label: string, icon: string, order: int, sidebar?: bool}>
     */
    public array $navGroups = [
        'admin' => ['label' => 'Administration', 'icon' => 'gear', 'order' => 80, 'sidebar' => false],
    ];

    /**
     * Headings in the sidebar that sit above a run of module groups, with a line separator. A module joins one with
     * 'section' => '<key>' inside its module-level 'group' array; sections are placed by the groups' 'order'.
     *
     * @var array<string, array{label: string}>
     */
    public array $navSections = [
        'accounts' => ['label' => 'Accounts'],
    ];

    /**
     * Headings on the Admin dashboard (account menu > Administration). An admin menu item joins one with
     * 'section' => '<key>' and may add a one-line 'summary'; an item without a known section is listed under Other.
     *
     * @var array<string, array{label: string, icon: string, order: int}>
     */
    public array $adminSections = [
        'people'      => ['label' => 'People and access', 'icon' => 'people', 'order' => 10],
        'payables'    => ['label' => 'Payables', 'icon' => 'receipt', 'order' => 20],
        'receivables' => ['label' => 'Customer invoices', 'icon' => 'cash-coin', 'order' => 30],
        'data'        => ['label' => 'Shared data', 'icon' => 'database', 'order' => 40],
        'system'      => ['label' => 'System', 'icon' => 'gear', 'order' => 50],
    ];

    /**
     * Role key that implicitly holds every permission.
     */
    public string $superRole = 'super_admin';

    /**
     * Platform version (major.minor.bug). Bump with `php spark version:bump platform <major|minor|bug>`.
     * Each module below carries its own 'version' in the same format.
     */
    public string $platformVersion = '1.13.0';

    /**
     * A module that needs per-user, per-company rights declares them in 'companyAccess'
     * (key => ['label', 'help', optional legacy 'column']); they appear on Manage company access
     * and are checked with service('scope')->can($userId, $companyId, '<module>.<key>').
     *
     * A module that references customers may declare 'customerUsage' (alias => SQL counting uses of one customer,
     * with {id} for the customer id); Admin > Customers shows it while the module is on.
     *
     * @var array<string, array{version: string, roles?: array<string, array{name: string, description: string, permissions: list<string>, key?: string}>, permissions: array<string, string>, menu: list<array<string, mixed>>, companyAccess?: array<string, array{label: string, help?: string, column?: string}>, customerUsage?: array<string, string>, optional?: bool, title?: string, description?: string, dashboard?: array{label: string, icon: string, permission: string, order: int, provider: class-string}}>
     */
    public array $modules = [
        'core' => [
            'version'     => '1.4.0',
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
                ['label' => 'Departments', 'icon' => 'diagram-3', 'url' => 'admin/departments', 'permission' => 'departments.manage', 'order' => 35, 'group' => 'admin', 'section' => 'people', 'summary' => 'Maintain the departments people belong to.'],
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
                ['label' => 'Users', 'icon' => 'people', 'url' => 'admin/users', 'permission' => 'users.view', 'order' => 30, 'group' => 'admin', 'section' => 'people', 'summary' => 'Add people, change their details and roles, and switch accounts on or off.'],
                ['label' => 'Change requests', 'icon' => 'inbox', 'url' => 'admin/requests', 'permission' => 'users.edit', 'order' => 32, 'group' => 'admin', 'section' => 'people', 'summary' => 'Approve or decline changes people have asked to make to their profile.'],
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
                ['label' => 'Roles', 'icon' => 'shield-lock', 'url' => 'admin/roles', 'permission' => 'roles.manage', 'order' => 40, 'group' => 'admin', 'section' => 'people', 'summary' => 'Create roles and choose what each role can do.'],
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
                ['label' => 'Companies', 'icon' => 'buildings', 'url' => 'admin/companies', 'permission' => 'companies.manage', 'order' => 36, 'group' => 'admin', 'section' => 'people', 'summary' => 'Set up companies and who belongs to each one.'],
                ['label' => 'Group-level users', 'icon' => 'globe2', 'url' => 'admin/group-level', 'permission' => 'tenancy.group_level', 'order' => 37, 'group' => 'admin', 'section' => 'people', 'summary' => 'Choose who can see across every company.'],
            ],
        ],
        'payables' => [
            'version'     => '2.1.0',
            'roles'       => [
                'user' => [
                    'key'         => 'payables_user',
                    'name'        => 'Payables User',
                    'description' => 'Opens the invoice register and My tasks, creates and submits invoices, approves for companies they belong to, runs reports. Company rights decide what they see.',
                    'permissions' => ['payables.view', 'payables.reports', 'suppliers.view'],
                ],
                'manager' => [
                    'key'         => 'payables_workflow',
                    'name'        => 'Payables Manager',
                    'description' => 'Everything a Payables User can do, plus approval routes, route variants, invoice types, teams, delegations for others and the supplier list.',
                    'permissions' => ['payables.manage', 'payables.workflow', 'suppliers.manage'],
                ],
            ],
            'optional'    => true,
            'title'       => 'Payables',
            'description' => 'Invoice register and approval workflow: routes, teams, delegations, reports.',
            // Sidebar: this module's user-facing entries sit under one collapsible heading; its admin entries join 'admin'.
            'group'       => ['label' => 'Payables', 'icon' => 'receipt', 'order' => 69, 'section' => 'accounts'],
            'dashboard'   => ['label' => 'Payables', 'icon' => 'receipt', 'permission' => 'payables.view', 'order' => 10, 'provider' => \App\Core\Payables\DashboardTab::class],
            'companyAccess' => [
                'submit'  => ['label' => 'Can submit', 'help' => 'Create and submit invoices for the company.', 'column' => 'can_submit'],
                'approve' => ['label' => 'Can approve', 'help' => 'Approve invoice steps for the company.', 'column' => 'can_approve'],
            ],
            'permissions' => [
                'payables.view'     => 'Open the invoice register, My tasks and the dashboard figures for companies you belong to',
                'payables.reports'  => 'Run the outstanding and supplier reports',
                'payables.manage'   => 'Manage teams, delegations for others and transfer invoice work when someone leaves',
                'payables.workflow' => 'Maintain approval routes, route variants and invoice types',
            ],
            'menu' => [
                ['label' => 'Invoice overview', 'icon' => 'speedometer', 'url' => 'payables/overview', 'permission' => 'payables.view', 'order' => 69],
                ['label' => 'My tasks', 'icon' => 'check2-square', 'url' => 'approvals', 'permission' => 'payables.view', 'order' => 70, 'badge' => 'payables.waiting'],
                ['label' => 'All invoices', 'icon' => 'file-earmark-text', 'url' => 'payables', 'permission' => 'payables.view', 'order' => 71, 'unless' => 'status'],
                ['label' => 'Drafts', 'icon' => 'pencil-square', 'url' => 'payables?status=draft', 'permission' => 'payables.view', 'order' => 71.1],
                ['label' => 'In approval', 'icon' => 'hourglass-split', 'url' => 'payables?status=open', 'permission' => 'payables.view', 'order' => 71.2],
                ['label' => 'Approved', 'icon' => 'check-circle', 'url' => 'payables?status=approved', 'permission' => 'payables.view', 'order' => 71.3],
                ['label' => 'Returned', 'icon' => 'arrow-return-left', 'url' => 'payables?status=returned', 'permission' => 'payables.view', 'order' => 71.4],
                ['label' => 'Rejected', 'icon' => 'x-circle', 'url' => 'payables?status=rejected', 'permission' => 'payables.view', 'order' => 71.5],
                ['label' => 'Processed', 'icon' => 'gear', 'url' => 'payables?status=processed', 'permission' => 'payables.view', 'order' => 71.6],
                ['label' => 'Paid', 'icon' => 'cash-coin', 'url' => 'payables?status=paid', 'permission' => 'payables.view', 'order' => 71.7],
                ['label' => 'Invoice reports', 'icon' => 'bar-chart-line', 'url' => 'reports', 'permission' => 'payables.reports', 'order' => 72],
                ['label' => 'Approval routes', 'icon' => 'diagram-2', 'url' => 'payables-admin/routes', 'permission' => 'payables.workflow', 'order' => 73, 'group' => 'admin', 'section' => 'payables', 'summary' => 'Build the approval steps supplier invoices follow.'],
                ['label' => 'Teams', 'icon' => 'people-fill', 'url' => 'payables-admin/teams', 'permission' => 'payables.manage', 'order' => 74, 'group' => 'admin', 'section' => 'payables', 'summary' => 'Group people so a team can share invoice work.'],
                ['label' => 'Invoice types', 'icon' => 'tags', 'url' => 'payables-admin/default-invoice-types', 'permission' => 'payables.workflow', 'order' => 76, 'group' => 'admin', 'section' => 'payables', 'summary' => 'Maintain the kinds of supplier invoice.'],
                ['label' => 'Route variants', 'icon' => 'signpost-split', 'url' => 'payables-admin/route-variants', 'permission' => 'payables.workflow', 'order' => 77, 'group' => 'admin', 'section' => 'payables', 'summary' => 'Define variations of an approval route.'],
            ],
        ],
        'receivables' => [
            'version'     => '1.9.0',
            'cron'        => [
                ['command' => 'receivables:remind', 'label' => 'Overdue customer invoice reminders', 'every' => 1440, 'example' => '30 6 * * *'],
                ['command' => 'receivables:subscriptions', 'label' => 'Subscription billing and upcoming-due notices', 'every' => 1440, 'example' => '45 6 * * *'],
            ],
            'roles'       => [
                'user' => [
                    'key'         => 'receivables_user',
                    'name'        => 'Receivables User',
                    'description' => 'Opens the customer invoice register and My tasks, raises and submits invoices, approves and issues for companies they belong to, records payments received, runs reports. Company rights decide what they see and do.',
                    'permissions' => ['receivables.view', 'receivables.reports', 'receivables.collect', 'customers.view'],
                ],
                'manager' => [
                    'key'         => 'receivables_workflow',
                    'name'        => 'Receivables Manager',
                    'description' => 'Everything a Receivables User can do, plus approval routes, route variants, invoice types, teams, delegations for others and the customer list.',
                    'permissions' => ['receivables.manage', 'receivables.workflow', 'customers.manage'],
                ],
            ],
            'optional'    => true,
            'title'       => 'Receivables',
            'description' => 'Customer invoices: approval and issue workflow, payments received, overdue reminders, reports.',
            'dashboard'   => ['label' => 'Receivables', 'icon' => 'cash-coin', 'permission' => 'receivables.view', 'order' => 11, 'provider' => \App\Core\Receivables\ArDashboardTab::class],
            'companyAccess' => [
                'submit'  => ['label' => 'Can raise', 'help' => 'Create and submit customer invoices for the company.'],
                'approve' => ['label' => 'Can approve and issue', 'help' => 'Approve and issue customer invoice steps for the company.'],
                'collect' => ['label' => 'Can record payments', 'help' => 'Record payments received against issued customer invoices for the company.'],
            ],
            'customerUsage' => [
                'receivables_invoices' => 'SELECT COUNT(*) FROM ar_invoices WHERE customer_id = {id}',
                'receivables_subscriptions' => 'SELECT COUNT(*) FROM ar_subscriptions WHERE customer_id = {id}',
            ],
            'permissions' => [
                'receivables.view'     => 'Open the customer invoice register, My tasks and the dashboard figures for companies you belong to',
                'receivables.reports'  => 'Run the outstanding, ageing and collections reports',
                'receivables.collect'  => 'Record and reverse payments received (also needs the per-company right)',
                'receivables.manage'   => 'Manage teams, delegations for others and transfer customer invoice work when someone leaves',
                'receivables.workflow' => 'Maintain approval routes, route variants and invoice types for customer invoices',
            ],
            // Sidebar: one collapsible heading, like Payables; its admin entries (routes, types, variants, teams) join 'admin'.
            'group'       => ['label' => 'Receivables', 'icon' => 'cash-coin', 'order' => 70, 'section' => 'accounts'],
            'menu' => [
                ['label' => 'My customer invoice tasks', 'icon' => 'check2-square', 'url' => 'receivables/tasks', 'permission' => 'receivables.view', 'order' => 69.9, 'badge' => 'receivables.waiting'],
                ['label' => 'All customer invoices', 'icon' => 'file-earmark-text', 'url' => 'receivables', 'permission' => 'receivables.view', 'order' => 70, 'unless' => 'status'],
                ['label' => 'Drafts', 'icon' => 'pencil-square', 'url' => 'receivables?status=draft', 'permission' => 'receivables.view', 'order' => 70.1],
                ['label' => 'In approval', 'icon' => 'hourglass-split', 'url' => 'receivables?status=open', 'permission' => 'receivables.view', 'order' => 70.2],
                ['label' => 'To be issued', 'icon' => 'send', 'url' => 'receivables?status=approved', 'permission' => 'receivables.view', 'order' => 70.3],
                ['label' => 'Outstanding', 'icon' => 'cash-stack', 'url' => 'receivables?status=collecting', 'permission' => 'receivables.view', 'order' => 70.4],
                ['label' => 'Overdue', 'icon' => 'exclamation-triangle', 'url' => 'receivables?status=overdue', 'permission' => 'receivables.view', 'order' => 70.5],
                ['label' => 'Paid', 'icon' => 'check2-circle', 'url' => 'receivables?status=paid', 'permission' => 'receivables.view', 'order' => 70.6],
                ['label' => 'Returned', 'icon' => 'arrow-return-left', 'url' => 'receivables?status=returned', 'permission' => 'receivables.view', 'order' => 70.7],
                ['label' => 'Customer invoice reports', 'icon' => 'bar-chart-line', 'url' => 'receivables/reports', 'permission' => 'receivables.reports', 'order' => 70.75],
                ['label' => 'Subscriptions', 'icon' => 'arrow-repeat', 'url' => 'receivables/subscriptions', 'permission' => 'receivables.view', 'order' => 70.77],
                ['label' => 'My delegations', 'icon' => 'person-check', 'url' => 'receivables/delegations', 'permission' => 'receivables.view', 'order' => 70.8],
                ['label' => 'Customer invoice routes', 'icon' => 'diagram-2', 'url' => 'receivables-admin/routes', 'permission' => 'receivables.workflow', 'order' => 78, 'group' => 'admin', 'section' => 'receivables', 'summary' => 'Build the approval steps customer invoices follow.'],
                ['label' => 'Customer invoice teams', 'icon' => 'people-fill', 'url' => 'receivables-admin/teams', 'permission' => 'receivables.manage', 'order' => 79, 'group' => 'admin', 'section' => 'receivables', 'summary' => 'Group people so a team can share customer invoice work.'],
                ['label' => 'Hand over customer invoice work', 'icon' => 'person-dash', 'url' => 'receivables-admin/handover', 'permission' => 'receivables.manage', 'order' => 79.05, 'group' => 'admin', 'section' => 'receivables', 'summary' => 'Pass customer invoice work to someone else, for example when a person leaves.'],
                ['label' => 'Customer invoice types', 'icon' => 'tags', 'url' => 'receivables-admin/invoice-types', 'permission' => 'receivables.workflow', 'order' => 79.1, 'group' => 'admin', 'section' => 'receivables', 'summary' => 'Maintain the kinds of customer invoice.'],
                ['label' => 'Customer route variants', 'icon' => 'signpost-split', 'url' => 'receivables-admin/route-variants', 'permission' => 'receivables.workflow', 'order' => 79.2, 'group' => 'admin', 'section' => 'receivables', 'summary' => 'Define variations of a customer invoice route.'],
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
                ['label' => 'Modules', 'icon' => 'puzzle', 'url' => 'admin/modules', 'permission' => 'modules.manage', 'order' => 65, 'group' => 'admin', 'section' => 'system', 'summary' => 'Switch optional modules on or off.'],
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
                ['label' => 'Suppliers', 'icon' => 'shop', 'url' => 'admin/suppliers', 'permission' => 'suppliers.manage', 'order' => 66, 'group' => 'admin', 'section' => 'data', 'summary' => 'The shared supplier list used across the platform.'],
            ],
        ],
        'customers' => [
            'version'     => '1.1.2',
            'roles'       => [
                'user' => [
                    'name'        => 'Customers User',
                    'description' => 'Pick from and search the shared customer list.',
                    'permissions' => ['customers.view'],
                ],
                'manager' => [
                    'name'        => 'Customers Manager',
                    'description' => 'Everything a Customers User can do, plus add, edit, switch off and bulk upload customers and keep their contacts.',
                    'permissions' => ['customers.manage'],
                ],
            ],
            'permissions' => [
                'customers.view'   => 'Pick from and search the shared customer list (modules that use customers need this)',
                'customers.manage' => 'Add, edit, switch off and bulk upload customers, and keep their contacts',
            ],
            'menu' => [
                ['label' => 'Customers', 'icon' => 'people', 'url' => 'admin/customers', 'permission' => 'customers.manage', 'order' => 67, 'group' => 'admin', 'section' => 'data', 'summary' => 'The shared customer list and its contacts.'],
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
                ['label' => 'Audit log', 'icon' => 'journal-text', 'url' => 'admin/audit', 'permission' => 'audit.view', 'order' => 50, 'group' => 'admin', 'section' => 'system', 'summary' => 'See who did what and when.'],
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
                ['label' => 'Multi-factor auth', 'icon' => 'shield-check', 'url' => 'admin/mfa', 'permission' => 'mfa.manage', 'order' => 55, 'group' => 'admin', 'section' => 'system', 'summary' => 'Turn two-step verification on and choose who must use it.'],
            ],
        ],
        'entra' => [
            'version'     => '1.1.1',
            'cron'        => [
                ['command' => 'entra:sync', 'label' => 'Microsoft Entra directory sync', 'every' => 240, 'example' => '0 */4 * * *'],
            ],
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
                ['label' => 'Microsoft Entra', 'icon' => 'microsoft', 'url' => 'admin/entra', 'permission' => 'entra.manage', 'order' => 60, 'group' => 'admin', 'section' => 'system', 'summary' => 'Connect Microsoft Entra and sync your directory.'],
            ],
        ],
        'backups' => [
            'version'     => '1.0.1',
            'cron'        => [
                ['command' => 'backup:run', 'label' => 'Database backup', 'every' => 1440, 'example' => '0 2 * * *'],
                ['command' => 'backup:check', 'label' => 'Backup freshness check', 'every' => 60, 'example' => '15 * * * *'],
            ],
            'roles'       => [
                'manager' => [
                    'name'        => 'Backup Manager',
                    'description' => 'See backup status, set up destinations and run backups.',
                    'permissions' => ['backups.view', 'backups.manage'],
                ],
            ],
            'permissions' => [
                'backups.view'   => 'See the status of database backups',
                'backups.manage' => 'Set up backup destinations and run backups',
            ],
            'menu' => [
                ['label' => 'Backups', 'icon' => 'cloud-arrow-up', 'url' => 'admin/backups', 'permission' => 'backups.view', 'order' => 70, 'group' => 'admin', 'section' => 'system', 'summary' => 'Check that database backups are running and where they are sent.'],
            ],
        ],
        'cron' => [
            'version'     => '1.0.0',
            'cron'        => [
                ['command' => 'cron:check', 'label' => 'Cron job check and alerts', 'every' => 60, 'example' => '5 * * * *'],
            ],
            'roles'       => [
                'manager' => [
                    'name'        => 'Cron Monitor',
                    'description' => 'See whether scheduled jobs are running.',
                    'permissions' => ['cron.view'],
                ],
            ],
            'permissions' => [
                'cron.view' => 'See the status of scheduled (cron) jobs',
            ],
            'menu' => [
                ['label' => 'Cron jobs', 'icon' => 'clock-history', 'url' => 'admin/cron', 'permission' => 'cron.view', 'order' => 75, 'group' => 'admin', 'section' => 'system', 'summary' => 'See whether scheduled jobs are running and when they last ran.'],
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
            'permissions' => ['directory.view', 'departments.manage', 'users.view', 'users.create', 'users.edit', 'users.delete', 'users.assign_roles', 'audit.view', 'companies.manage', 'tenancy.memberships', 'suppliers.view', 'suppliers.manage', 'customers.view', 'customers.manage', 'payables.view', 'payables.reports', 'payables.manage', 'payables.workflow', 'receivables.view', 'receivables.reports', 'receivables.collect', 'receivables.manage', 'receivables.workflow', 'backups.view', 'backups.manage', 'cron.view'],
        ],
        'manager' => [
            'name'        => 'Manager',
            'description' => 'Can view users and the staff directory.',
            'permissions' => ['directory.view', 'users.view', 'suppliers.view', 'customers.view', 'customers.manage', 'payables.view', 'payables.reports', 'payables.manage', 'payables.workflow', 'receivables.view', 'receivables.reports', 'receivables.collect', 'receivables.manage', 'receivables.workflow', 'backups.view'],
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
        'payables.submitted'      => ['label' => 'An invoice is waiting for my approval', 'in_app' => true, 'email' => true],
        'payables.received'       => ['label' => 'My invoice was submitted', 'in_app' => true, 'email' => true],
        'payables.step_approved'  => ['label' => 'My invoice moved to the next step', 'in_app' => true, 'email' => true],
        'payables.processed'      => ['label' => 'My invoice was processed', 'in_app' => true, 'email' => true],
        'payables.paid'           => ['label' => 'My invoice was paid', 'in_app' => true, 'email' => true],
        'payables.rejected'       => ['label' => 'My invoice was rejected', 'in_app' => true, 'email' => true],
        'payables.returned'       => ['label' => 'My invoice was returned for changes', 'in_app' => true, 'email' => true],
        'payables.resubmitted'    => ['label' => 'An invoice I approve was resubmitted', 'in_app' => true, 'email' => true],
        'payables.returned_step'  => ['label' => 'An invoice was returned to me by the next approver', 'in_app' => true, 'email' => true],
        'payables.override'       => ['label' => 'A group-level user acted on my step or invoice', 'in_app' => true, 'email' => true],
        'payables.notify_step'    => ['label' => 'I am copied on an invoice', 'in_app' => true, 'email' => true],
        'payables.claimed'        => ['label' => 'A team member accepted an invoice', 'in_app' => true, 'email' => true],
        'payables.released'       => ['label' => 'An invoice was released back to my team', 'in_app' => true, 'email' => true],
        'payables.document_added' => ['label' => 'A supporting document was added to my invoice', 'in_app' => true, 'email' => true],
        'ar.submitted'           => ['label' => 'A customer invoice is waiting for my approval', 'in_app' => true, 'email' => true],
        'ar.received'            => ['label' => 'My customer invoice was submitted', 'in_app' => true, 'email' => true],
        'ar.step_approved'       => ['label' => 'My customer invoice moved to the next step', 'in_app' => true, 'email' => true],
        'ar.returned'            => ['label' => 'My customer invoice was returned for changes', 'in_app' => true, 'email' => true],
        'ar.rejected'            => ['label' => 'My customer invoice was rejected', 'in_app' => true, 'email' => true],
        'ar.resubmitted'         => ['label' => 'A customer invoice I approve was resubmitted', 'in_app' => true, 'email' => true],
        'ar.returned_step'       => ['label' => 'A customer invoice was returned to me by the next approver', 'in_app' => true, 'email' => true],
        'ar.override'            => ['label' => 'A group-level user acted on my customer invoice step or invoice', 'in_app' => true, 'email' => true],
        'ar.notify_step'         => ['label' => 'I am copied on a customer invoice', 'in_app' => true, 'email' => true],
        'ar.claimed'             => ['label' => 'A team member accepted a customer invoice', 'in_app' => true, 'email' => true],
        'ar.released'            => ['label' => 'A customer invoice was released back to my team', 'in_app' => true, 'email' => true],
        'ar.document_added'      => ['label' => 'A supporting document was added to my customer invoice', 'in_app' => true, 'email' => true],
        'ar.issued'              => ['label' => 'My customer invoice was issued to the customer', 'in_app' => true, 'email' => true],
        'ar.payment_received'    => ['label' => 'A payment was recorded on my customer invoice', 'in_app' => true, 'email' => true],
        'ar.paid'                => ['label' => 'My customer invoice was paid in full', 'in_app' => true, 'email' => true],
        'ar.payment_reversed'    => ['label' => 'A payment on my customer invoice was reversed', 'in_app' => true, 'email' => true],
        'ar.overdue'             => ['label' => 'A customer invoice of mine is overdue', 'in_app' => true, 'email' => true],
        'ar.subscription_due'    => ['label' => 'A customer subscription I look after is about to be billed', 'in_app' => true, 'email' => true],
        'ar.subscription_billed' => ['label' => 'A draft invoice was created for a customer subscription', 'in_app' => true, 'email' => true],
        'ar.subscription_failed' => ['label' => 'A customer subscription could not be billed', 'in_app' => true, 'email' => true],
        'entra.sync_failed'       => ['label' => 'Entra directory sync failed (admins)', 'in_app' => true, 'email' => true],
        'backups.failed'          => ['label' => 'A database backup failed (backup managers)', 'in_app' => true, 'email' => true],
        'backups.stale'           => ['label' => 'No recent database backup (backup managers)', 'in_app' => true, 'email' => true],
        'cron.attention'          => ['label' => 'A scheduled job failed or is late (administrators)', 'in_app' => true, 'email' => true],
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

# Evolution log

Newest first. One entry per change that touches modules, routes, permissions, notification types, migrations, shared components, design tokens or conventions. See the rules in [CLAUDE.md](../CLAUDE.md).

Entry format:

```
## YYYY-MM-DD: Short title
- What changed
- Why
- Files / areas: ...
```

## 2026-10-23: Invoice status shortcuts in the sidebar (invoices 1.5.0)
- The Invoices sidebar group gains links for Drafts, In approval, Approved, Returned, Rejected, Processed and Paid (the register filtered by `?status=`). My tasks is the inbox and now comes first; "Invoices" is renamed All invoices.
- `nav_active()` now matches menu urls that carry a query string, and a menu item may set `'unless' => '<param>'` so a plain entry is not active while that GET param is present.
- Why: reach a status list in one click instead of using the register's dropdown.
- Files / areas: `app/Config/Backoffice.php`, `app/Helpers/app_helper.php`, `app/Views/partials/sidebar.php`, `app/Config/Help.php`, `tests/unit/NavActiveTest.php`.

## 2026-10-22: Timezones (platform 1.3.0, core 1.3.0, directory 1.2.0, invoices 1.4.0)
- Stored timestamps stay UTC. New nullable `companies.timezone` and `staff_profiles.timezone` (migration `2026-10-22-210000_AddTimezones`). A user shows times in their own zone, else the active company's, else their first company's, else the app default.
- New shared service `service('timezone')` (`App\Core\Timezone\TimezoneService`): `forUser()`, `forCompany()`, `toLocal()`, `toUtc()`, `todayFor()`. New helpers `local_time()`, `days_overdue()`, `tz_label()`, and partial `partials/timezone_select`.
- Company form and profile/admin user forms gain a Timezone field. Delegation start/end entered in the user's zone are saved as UTC.
- Datetimes shown in notifications, audit, security log, MFA, Entra, dashboard and invoice views are converted. Due dates stay calendar dates; invoice past-due counts follow the invoice's company. Report-wide "today" uses the viewer's zone.
- Files / areas: `app/Core/Timezone/`, `app/Helpers/app_helper.php`, `app/Config/Services.php`, `app/Controllers/{Profile,Admin/Companies,Admin/Users,InvoiceDelegations}.php`, `app/Core/Invoices/{ReportService,ApprovalQueue}.php`, views, `app/Config/Help.php`, `tests/Core/TimezoneTest.php`.

## 2026-10-22: Help moved to the user menu (core 1.2.0)
- The Help link is no longer a sidebar entry; it now sits in the user dropdown in the top bar, above Sign out. The `help` route and its access (open to everyone signed in) are unchanged.
- Why: Help is a utility rather than a place to work, and the sidebar is kept for module navigation.
- Files / areas: `app/Config/Backoffice.php` (core menu), `app/Views/partials/topbar.php`.

## 2026-10-22: Dashboard focused on the day's work (platform 1.2.0, core 1.1.0, invoices 1.3.0, users 1.2.0)
- The dashboard is now a Today tab plus one tab per enabled module the user can use, opened with `/?tab=<module>`. Today shows an attention count per module tab and unread notifications. Replaces the active-staff tile and the single invoices tile.
- New registry hook: a module declares `'dashboard' => [label, icon, permission, order, provider]` in `Config\Backoffice::$modules`. The provider implements `App\Core\Dashboard\DashboardTab` (`badge()`, `data()`); the view is `app/Views/dashboard/tabs/<module>.php`. `App\Core\Dashboard\DashboardService` lists the tabs the user may see (module enabled and permission held), so switching a module off removes its tab.
- Tabs added: Invoices (waiting on me, sent back, overdue, due in 7 days, drafts, recent activity; reuses `ApprovalQueue`, `ReportService`, `ActivityFeed`) and People (pending profile change requests, `users.edit`).
- The dashboard reuses the `stat_card` component from `invoices/components/` and the existing `.nav-tabs-itm` style; no new tokens or JS.
- Files / areas: `app/Config/Backoffice.php`, `app/Core/Dashboard/`, `app/Core/Invoices/DashboardTab.php`, `app/Core/Users/DashboardTab.php`, `app/Controllers/Dashboard.php`, `app/Views/dashboard/`, `app/Config/Help.php`, `tests/Dashboard/`.

## 2026-10-22: Fix saving a route (invoices 1.2.2)
- Saving a route failed with `Unknown column 'last_active'`: the "never signed in" warning queries `inv_users`, whose view had no `last_active`. New migration `2026-10-22-200000_AddLastActiveToInvUsers` recreates the view with Shield's `users.last_active`.
- Files / areas: `app/Database/Migrations/2026-10-22-200000_AddLastActiveToInvUsers.php`.

## 2026-10-22: Fix high priority form URLs (invoices 1.2.1)
- The "Save priority" forms on the route edit screen and on Invoice types posted to `admin/invoice-types/<id>/priority`, which does not exist (the route is `invoices-admin/invoice-types/<id>/priority`). Both now use the right URL.
- Files / areas: `app/Views/invoices/admin/routes/edit.php`, `app/Views/invoices/admin/default_types/index.php`.

## 2026-10-22: Modules declare company access (tenancy 1.2.0, invoices 1.2.0)
- Manage company access is now driven by the registry. A module lists per-user, per-company rights under `'companyAccess'` in `Config\Backoffice::$modules` (`key => label, help, optional column`); each becomes a checkbox column on the screen, named `<module>.<key>`. Switching a module off hides its columns without removing the rights.
- New table `user_company_capabilities` (migration `2026-10-22-190000`), cascading from `user_companies`. Invoices keeps its `can_submit` / `can_approve` columns, mapped through `'column'`, so no invoice code changed.
- New `service('scope')->can($userId, $companyId, '<module>.<key>')`; `canSubmit()` / `canApprove()` now call it. `Memberships::sync()` takes `'capabilities'` per company (the old boolean keys still work), `Memberships::capabilities()` lists what is offered. The audit entry now records the rights held per company.
- Convention (see CLAUDE.md): no module-specific screens or columns for per-company user rights.
- Files / areas: `app/Config/Backoffice.php`, `app/Core/Tenancy/`, `app/Controllers/Admin/Memberships.php`, `app/Views/admin/companies/memberships.php`, `app/Config/Help.php`, `tests/Tenancy/TenancyTest.php`.

## 2026-10-22: Fix route edit screen user query (invoices 1.1.2)
- The route edit screen selected `u.email_missing` from the `inv_users` view, which has no such column (it exposes `email`), so the page failed with a database error. The query now derives `email_missing` from `email`.
- Files / areas: `app/Controllers/InvoiceAdmin/Routes.php`.

## 2026-10-22: Fix redirects after creating routes and teams (invoices 1.1.1)
- Creating or saving an approval route or team redirected to `invoices/admin/...`, which does not exist (the routes are under `invoices-admin/`), giving a 404. Redirects now use `invoices-admin/`; the high-priority fallback also pointed at the old `admin/default-invoice-types` and now goes to `invoices-admin/default-invoice-types`.
- Files / areas: `app/Controllers/InvoiceAdmin/Routes.php`, `Teams.php`, `DefaultInvoiceTypes.php`.

## 2026-10-22: Faster test suite
- The full suite went from over 10 minutes to under a minute. Every database test used to roll back and re-run all migrations before each test, and users were hashed with bcrypt cost 12.
- New `Tests\Support\DatabaseTestCase`: the schema is built once per run (reused when every migration is recorded and the table count matches, rebuilt from an emptied `*_test` database otherwise, or when `TESTS_REBUILD=1`); each test starts from empty tables, and the system role tables are re-seeded only when a test changed them (truncated so role ids stay stable, because `UserService` caches the Super Admin role id). `MfaTestCase`, `EntraTestCase` and the previously separate unit tests now extend it. The sample CodeIgniter database test was moved onto it too, because its rollback was tearing the schema down.
- `phpunit.dist.xml` sets `auth.hashCost` to 4 for tests. `MfaTestCase` clears `$_SESSION` and the `auth` service before each test, which fixes a leak of a signed-in user between test classes.
- Convention (see CLAUDE.md): database tests extend `DatabaseTestCase`, do not change the schema and do not rely on ids restarting at 1. No app code, migration or module changed, so no version bump.
- Files / areas: `tests/_support/DatabaseTestCase.php`, `tests/_support/Mfa/MfaTestCase.php`, `tests/_support/Entra/EntraTestCase.php`, `tests/unit/`, `tests/database/ExampleDatabaseTest.php`, `phpunit.dist.xml`.

## 2026-10-22: User and Manager roles for every module
- Each module declares `'roles'` (`user`, `manager`) in `Config\Backoffice::$modules`. `Backoffice::roleDefinitions()` merges them with the broad `$systemRoles` and `RbacSeeder` seeds the result. Keys default to `<module>_user` / `<module>_manager`; a manager holds the user permissions plus its own. Invoices keeps `invoice_user` and `invoice_workflow` as its User and Manager roles (renamed Invoices User / Invoices Manager); they were removed from `$systemRoles`.
- New roles: Directory, Users and Suppliers (User + Manager); Companies, Roles, Modules, Audit, MFA and Entra (Manager only, nothing to split). Invoices Manager now also holds `invoices.reports`. `tenancy.group_level` is in no role (Super Admin only). Roles Manager is effectively full access.
- No migration: run `php spark db:seed RbacSeeder` (additive, never strips grants). Help gains a "User and Manager roles" section. Modules bumped to 1.1.0, platform 1.1.0.
- Also fixed `version:bump platform`, which failed on an unescaped `$` in its pattern.
- Files / areas: `app/Config/Backoffice.php`, `app/Database/Seeds/RbacSeeder.php`, `app/Config/Help.php`, `app/Commands/VersionBump.php`, `tests/Core/ModuleRolesTest.php`.

## 2026-10-22: Version and build control (major.minor.bug)
- The platform and every module now carry a `major.minor.bug` version: `'version'` on each entry in `Config\Backoffice::$modules`, and `$platformVersion`. major = breaking change, minor = new feature, bug = fix. All start at 1.0.0.
- `php spark version:bump <module|platform> <major|minor|bug>` raises a version in `Backoffice.php` (lower parts reset). `php spark app:build` stamps a build (number, git commit, dirty flag, date, all versions) into `build.json` (git-ignored); run it on every release or deploy.
- New `App\Core\VersionService` (`service('versions')`). The sidebar footer shows `v<platform> · build <n> (<commit>)`; Admin > Modules has a Version column. Without `build.json` the footer shows `dev <commit>`.
- Convention: a change to a module bumps that module's version in the same change, and the EVOLUTION entry names the new version (see CLAUDE.md). New modules must declare `'version'`; a test fails if one is missing or malformed.
- Files / areas: `app/Core/VersionService.php`, `app/Commands/VersionBump.php`, `app/Commands/AppBuild.php`, `app/Config/Backoffice.php`, `app/Views/partials/sidebar.php`, `app/Views/admin/modules/`, `tests/Core/VersionServiceTest.php`.

## 2026-10-22: Help: user manual and FAQ, shown by access level
- New always-on screens `help` (topics and search), `help/{topic}` (manual sections plus that topic's FAQ) and `help/faq` (all FAQs, grouped, searchable). Sidebar entry "Help" (core, no permission, order 90). No new permission, table or migration.
- Content lives in `Config\Help::$topics`. A topic, a section and a single FAQ can each carry a `permission` (key, list of keys where any one is enough, or null). `App\Core\HelpService` (`service('help')`) filters by `PermissionService::canAny()`, so users only see help for areas they can use, a switched-off module's help disappears with it (its permissions fail), and a topic the user may not see is a 404. Search covers only what the user may see.
- Convention: a new module or screen adds or extends a topic in `Config\Help` with the module's permission (see CLAUDE.md).
- Files / areas: `app/Config/Help.php`, `app/Core/HelpService.php`, `app/Controllers/Help.php`, `app/Views/help/`, `public/assets/js/help.js`, `tests/Help/`.

## 2026-10-22: Suppliers are a core service
- Suppliers moved out of Invoices into core. Migration `2026-10-22-170000_MoveSuppliersToCore` creates `suppliers` (ids kept from `inv_suppliers`), adds `inv_invoices.supplier_id` (FK, backfilled by name) and drops `inv_suppliers`; the invoice still stores the supplier name as a snapshot.
- New `App\Core\SupplierService` (`service('suppliers')`): `options()`, `search()`, `find()`, `findByName()`, `findByCode()`, `isActive()`, `all()`, `create()`, `update()` (validation and audit inside). `SupplierImporter` moved to `App\Core`.
- New always-on module `suppliers` with permissions `suppliers.view` and `suppliers.manage`; screen Administration > Suppliers at `admin/suppliers` (old `invoices-admin/suppliers` URLs redirect). Roles: admin and Invoice Workflow Manager get both, manager and Invoice User get view. Run `php spark db:seed RbacSeeder` on existing databases. Suppliers keep working when the Invoices module is switched off.
- Files / areas: `app/Core/SupplierService.php`, `app/Core/SupplierImporter.php`, `app/Controllers/Admin/Suppliers.php`, `app/Views/admin/suppliers/`, `tests/Suppliers/`.

## 2026-10-22: Invoices: workflow menu links and supplier CSV upload
- Administration now links to Suppliers, Invoice types and Route variants (permission `invoices.workflow`).
- Supplier bulk upload ported: template download, preview, atomic commit (`SupplierImporter`, `Suppliers::upload*`, routes `invoices-admin/suppliers/upload*`). The Invoices module entry's "not ported" list no longer includes supplier upload; invoice-type and user bulk uploads are still phase 2.
- Files / areas: `app/Core/Invoices/SupplierImporter.php`, `app/Controllers/InvoiceAdmin/Suppliers.php`, `app/Views/invoices/admin/suppliers/`, `tests/Invoices/SupplierImporterTest.php`.

## 2026-10-22: Module management (Admin > Modules)
- New permission `modules.manage` (Super Admin only by default) and screen Admin > Modules. Modules marked `'optional' => true` in `Config\Backoffice` (Invoices, Staff directory) can be switched on or off; every other module is core and always on. State is stored in settings (`ModuleState.disabled`) and each change is audited (`module.enabled` / `module.disabled`).
- A disabled module owns no permissions: `PermissionService::can()` returns false for them for everyone, Super Admins included, so its menu items vanish and its `can:` routes are refused. Data is kept; re-enabling restores everything. New service `service('modules')` (`App\Core\ModuleService`).
- Convention: a new module that should be switchable declares `'optional' => true`, `'title'` and `'description'` in its registry entry, and gates every route/view with its own permissions (never with a hard-coded check) so the switch covers it.
- Files / areas: `app/Core/ModuleService.php`, `app/Config/ModuleState.php`, `app/Controllers/Admin/Modules.php`, `app/Views/admin/modules/`, `tests/Tenancy/ModuleServiceTest.php`.

## 2026-10-22: Administration sidebar group
- `Config\Backoffice::$navGroups` defines named sidebar groups shared across modules; a menu item joins one with `'group' => 'admin'`. All admin entries (Users, Change requests, Departments, Companies, Group-level users, Roles, Audit log, Multi-factor auth, Microsoft Entra, Approval routes, Teams) now sit under one collapsible Administration heading. The Invoices heading keeps only the user-facing entries (overview, invoices, My tasks, reports). Dashboard and Directory stay top-level.
- Convention: new admin screens declare `'group' => 'admin'`; user-facing module screens go under the module's own group.

## 2026-10-22: Collapsible sidebar groups
- A module can declare `'group' => ['label', 'icon', 'order']` in `Config\Backoffice`; its menu items then sit under one collapsible heading (`nav_tree()` helper, `partials/sidebar.php`). The Invoices module uses it. The group opens automatically when the current page is inside it, shows the total badge while collapsed, and remembers open/closed per group in localStorage (`app.js`).
- Why: keeps the sidebar short as modules are added.

## 2026-10-22: Invoices module (phase 1, ported from invoice-tracking)
- New module `invoices`: invoice register, create/edit with PDF (or phone photos), submit, approve / return / reject / cancel, return to previous approver, notes and supporting documents, team steps (accept / release), delegations, group-level override, My tasks, overview dashboard, outstanding and supplier reports (+CSV), approval routes with variants and pay/process/notify steps, invoice types, suppliers, teams.
- Schema (migration `2026-10-22-160000_CreateInvoiceModuleTables`): `inv_*` tables with real foreign keys, plus the read-only view `inv_users` (name, active flag and email from staff profiles) so ported queries do not depend on the Shield `users` columns.
- Services in `App\Core\Invoices` (`WorkflowEngine` is the only thing that changes invoice status). Thin adapters: `InvoiceAudit` -> `AuditService`, `InvoiceNotifier` -> `NotificationService`, `AdminAccess` -> `invoices.manage` + `UserService`. Tenancy comes from core `AccessScope`.
- Permissions `invoices.view`, `invoices.reports`, `invoices.manage`, `invoices.workflow` replace invoice-tracking's is_admin / is_manager / is_workflow flags; roles `Invoice User` and `Invoice Workflow Manager` added; admin and manager roles get all four. Per-company submit/approve still come from `user_companies`.
- Notifications: `invoice.*` types registered in `Config\Backoffice`; `NotificationService::send()` gained `$forceEmail` (high-priority invoices always email).
- Sidebar: menu items can carry a `badge` key (`nav_badge()`); My tasks shows the waiting count. Home dashboard shows "Invoices waiting on me".
- Platform-wide: `numberNative` is now on for MySQL (ints come back as ints; the engine compares ids strictly). Helpers `invoice` and `route` are autoloaded. `BaseController::scopedCompany()` added. Views are CSP-clean: `data-confirm`, `data-confirm-click`, `data-autosubmit`, `data-width` handled in `app.js`; inline scripts use `{csp-script-nonce}`.
- Not ported (phase 2, see plan): SharePoint filing, REST API + OpenAPI, analytics database, reminders / escalation / daily digest / email retry queue, routing import and CSV bulk uploads (suppliers, types, users), S3 file store and purge job, API tokens, company logos, leaver work-transfer screen (service `UserOffboarding` is ported but has no screen), help manual.
- Files / areas: `app/Core/Invoices/`, `app/Controllers/{Invoices,Approvals,Reports,InvoiceOverview,InvoiceDelegations}.php`, `app/Controllers/InvoiceAdmin/`, `app/Views/invoices/`, `public/assets/css/itm/invoices.css`, `public/assets/js/pdf-viewer.js`, `tests/Invoices/`.

## 2026-10-22: Tenancy promoted to core (companies, memberships, group-level)
- New tables `company_groups`, `companies`, `user_companies` (can_submit, can_approve, job_title), `group_level_users` (migration `2026-10-22-150000_CreateTenancyTables`), with real foreign keys.
- New services `App\Core\Tenancy\{AccessScope,ActiveCompany,Memberships}`, registered as `service('scope')`, `service('activeCompany')`, `service('memberships')`. `AccessScope::restrict()` is the single rule for which companies a user sees; group-level users see all.
- New permissions `companies.manage`, `tenancy.memberships`, `tenancy.group_level` (admin role gets the first two). Screens: Admin > Companies (+ groups), Admin > Users > Manage company access, Admin > Group-level users. Company switcher in the top bar (`partials/company_context.php`, `POST company-context`), hidden for users with no companies.
- Why: the invoices module (ported from invoice-tracking) and later modules need a shared company model; see the plan to port invoice-tracking as a module.
- Files / areas: `app/Core/Tenancy/`, `app/Controllers/Admin/{Companies,Memberships,GroupLevel}.php`, `app/Controllers/CompanyContext.php`, `app/Views/admin/companies/`, `tests/Tenancy/`.
- Not yet done: company logos, running the migration on the dev database.

## 2026-10-22: ITM theme applied to the platform shell
- Replaced the indigo `app.css` with the ITM theme (`public/assets/css/itm.css` + `itm/` partials), derived from the invoice-tracking design system: gradient sidebar, sticky top bar, `.page-header`, stat cards, status chips, `itm-table`, auth screen with photo background and "Powered by ITM" badge.
- Colour tints, ink and surface greys are tokens; `itm/dark.css` overrides them for dark mode (existing theme toggle kept).
- Sidebar still builds from `nav_items()` / `Config\Backoffice::$modules`; mobile off-canvas replaced by a drawer toggled by `#sidebar-toggle` (`itm-collapsed` is remembered in localStorage).
- Why: one standard look and layout for every module added later.
- Files / areas: `app/Views/layouts/*`, `app/Views/partials/{sidebar,topbar}.php`, `public/assets/css/itm*`, `public/assets/js/app.js`, `public/assets/img/`.
- Open items: real ITM brand mark and login photos (placeholders in use); error pages and email templates not yet themed.

## 2026-10-22: Initial platform core
- Users, roles/permissions, staff directory, departments, Entra SSO and sync, MFA, notifications, audit log, profile self-service, password reset.
- Why: foundation modules plug into via `Config\Backoffice`.
- Files / areas: see the initial commit and `app/Database/Migrations/`.

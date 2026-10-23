# ITM Back Office Platform: development rules

Modular back-office app. Stack: CodeIgniter 4, PHP 8.2+, MySQL 8, Bootstrap 5.3 + Bootstrap Icons, Shield auth, strict CSP.
Setup, commands and the module checklist are in [README.md](README.md). Deployment is in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Keeping track of the system's evolution (do this every time)

- Any change that adds, removes or alters a module, route, permission, notification type, migration, shared component, design token or convention gets one dated entry in [docs/EVOLUTION.md](docs/EVOLUTION.md), newest first. Say what changed and why, in a few lines.
- Versions are `major.minor.bug` (breaking / feature / fix) and live in `app/Config/Backoffice.php`: `platformVersion` and each module's `'version'`. Any change to a module bumps that module's version in the same change with `php spark version:bump <module> <major|minor|bug>`, and the EVOLUTION entry names the new version. Run `php spark app:build` on each release or deploy to stamp the build.
- If a change alters a rule in this file, update this file in the same change.
- Do not edit or reorder past EVOLUTION entries; add a new one that supersedes them.

## Architecture

- `app/Config/Backoffice.php` is the module registry. A module declares its `version`, permissions, sidebar entries (label, Bootstrap Icons name, url, permission, order) and notification types there. Never hard-code navigation in a view.
- Every module with permissions declares `'roles'` in its registry entry: a `user` role (use the module) and a `manager` role (the user permissions plus management; list only the extras). A module with nothing to use day to day declares `manager` only. Never put `tenancy.group_level` in a role. Run `php spark db:seed RbacSeeder` after changing roles.
- Company data: any table owned by a company has a `company_id`, and every read of it goes through `service('scope')->restrict($builder, $userId, 'table.company_id')`. Never filter companies by hand, never use `ActiveCompany` (a UI-only narrowing) as an access check, and check per-company rights with `service('scope')->canSubmit()/canApprove()`.
- Per-user, per-company rights (e.g. "can submit", "can approve", "can review") are declared in the module's `'companyAccess'` registry entry, which adds them to Admin > Users > Manage company access, and are checked with `service('scope')->can($userId, $companyId, '<module>.<key>')`. Never build a module-specific screen, table or column for assigning users to companies.
- Module tables carry a module prefix (`inv_*`) and real foreign keys; people are referenced by user id. To show a person's name or active flag in SQL, join the module's read-only user view (`inv_users`) rather than Shield's `users`.
- The `invoices` module: all invoice status changes go through `App\Core\Invoices\WorkflowEngine`; never update `inv_invoices.status` anywhere else. Module permissions only open the screens; what a user sees inside is decided per company by `scope`.
- MySQL integers come back as PHP ints (`numberNative` is on); compare ids strictly and cast user input.
- Dashboard: the dashboard is the user's day (a Today tab plus one tab per enabled module). A module with daily tasks declares `'dashboard'` (label, icon, permission, order, provider) in its registry entry; the provider implements `App\Core\Dashboard\DashboardTab` and the view is `app/Views/dashboard/tabs/<module>.php`. Never add module content to `dashboard/index.php`.
- Optional modules declare `'optional' => true`, `'title'` and `'description'` in the registry so Admin > Modules can switch them off. Gate every route and view with the module's own permissions (`can:`/`can()`), never a hard-coded role check, otherwise the switch will not cover it.
- Suppliers are global: use `service('suppliers')` (`options()` for pick-lists, `search()` for autocomplete, `findByName()` / `find()`). Reference a supplier by `supplier_id` (FK to `suppliers`) and keep a name snapshot where history must not change. Never read or write the `suppliers` table directly; maintain the list only through Admin > Suppliers.
- Layers: `Controllers` (thin, HTTP only) -> `Core/` services (business logic, e.g. `UserService`, `NotificationService`, `AuditService`) -> `Models`. Do not put business rules in controllers or views.
- Module controllers go in `app/Controllers/<Module>/` (admin screens under `Admin/`), views in `app/Views/<module>/`, routes grouped per module in `app/Config/Routes.php`.
- Every route is protected with the `can:<permission>` filter. Use `can('x.y')` in views to hide controls. Permission names are `<module>.<action>` (e.g. `invoices.view`).
- Record every state change with `service('audit')->log(...)`. Tell affected users with `service('notifier')->send(...)`, and register the type in `$notificationTypes`.
- Schema changes are new migrations in `app/Database/Migrations/` named `YYYY-MM-DD-HHMMSS_Description.php`. Never edit a migration that has been committed. After registry changes run `php spark db:seed RbacSeeder`.
- Help: every module or user-facing screen is documented in `app/Config/Help.php` (manual sections and FAQs), with the same permission that opens the screen, so people only read about what they can use. Update it in the same change as the feature.
- Secrets and environment settings live in `.env`, never in code or git.

## UI standards (ITM theme)

The theme is Bootstrap 5.3 overridden by `public/assets/css/itm.css`, which imports partials from `public/assets/css/itm/`. Modules must not ship their own look.

- **Layouts:** signed-in pages `extend('layouts/app')`; sign-in style pages `extend('layouts/auth')`. Pass `title` (and optional `subtitle`) in the view data; the layout renders the page header. Put header buttons in the `actions` section and page JS in the `scripts` section. Never write your own `<html>` shell.
- **Tokens:** use `--itm-*` variables and Bootstrap utilities. No hard-coded hex colours in views or new CSS. Add new colours as tokens in `itm/variables.css` and give them a dark value in `itm/dark.css`. Every page must work in light and dark mode.
- **Class prefix:** theme classes are `.itm-*`. Shared, module-neutral components go in `itm/app.css`. Module-specific CSS stays tiny, is prefixed with the module name, and lives in its own file linked from that module's views.
- **Components:** status chips `badge-status st-{info|success|warning|danger|draft|dark}`; KPI cards `stat-card tone-*`; tables `table itm-table` inside `table-responsive`, with `table-cards` (or `table-stack`) plus `data-label` on every `td` for phone layout; filters `filter-bar`; pagination via `partials/pager`; flash messages via `partials/flash`; icons are Bootstrap Icons `bi bi-*`. Reuse these before inventing markup.
- **Forms:** `form-label` + `form-control`/`form-select`, `mb-3` spacing, help in `form-text`, CSRF via `csrf_field()`. Primary action is `btn btn-primary`, secondary is `btn btn-outline-secondary`, destructive is `btn-danger`/`btn-outline-danger`. Confirm destructive posts with `data-confirm` on the form.
- **CSP is strict:** no `onclick=`/`onsubmit=`/`onchange=` handlers and no inline `style=` attributes. Use the data attributes handled in `app.js` (`data-confirm` on a form, `data-confirm-click` on a button, `data-autosubmit` on a field, `data-width` for bar widths) and CSS classes. Inline `<script>` blocks are allowed only as `<script {csp-script-nonce}>`; prefer a file in `public/assets/js/`.
- **Module CSS:** a module's styles live in `public/assets/css/itm/<module>.css`, built only on `--itm-*` tokens, and are imported at the end of `itm.css`.
- **Responsive and accessible:** works down to 390px; icon-only buttons need `aria-label`; do not rely on colour alone to convey status.
- **Output escaping:** always `esc()` user data in views.
- **Adding a shared component or token:** add it to the theme partials, show it in use on a real page, and log it in EVOLUTION.md.

## Coding conventions

- PSR-12, return types, typed properties, `final`/`private` by default. Match the surrounding file's style and comment density.
- Validate input in the controller with CI4 validation rules; never trust `$_POST` directly; use query builders or models (no raw string-built SQL).
- Soft-delete (`deleted_at`) is the norm for people/records; follow existing models.
- Language strings or labels shown to users use plain, short British/neutral English and sentence case.

## Testing and verification

- Add PHPUnit tests under `tests/<Area>/` for services and permission-gated routes. UI changes need a manual check of: login page, dashboard, one table page, light and dark mode, 390px and 1440px widths.
- Database tests extend `Tests\Support\DatabaseTestCase` (via `MfaTestCase` / `EntraTestCase` / `InvoiceTestCase`), never `DatabaseTestTrait` with `$refresh = true`. The schema is built once per run and each test starts from empty tables with the system roles seeded; tests must not change the schema or rely on ids restarting at 1. Set `TESTS_REBUILD=1` to force a rebuild after editing a migration.
- Run `vendor/bin/phpunit` before finishing. The test database group is wiped on each run: never point `database.tests.*` at real data.
- Do not mark work done without saying what was and was not verified.

## Git

- Small, focused commits with imperative messages. Do not commit `.env`, `writable/`, `vendor/` or uploaded files.
- `invoice-tracking/` is reference material for the design system and is not part of the platform; do not import code from it at runtime.

# ITM Back Office Platform

A modular back-office application: one interface for the day-to-day administration of a
business. The core and first module is **user management**.

**Stack:** CodeIgniter 4 · PHP 8.2+ · MySQL 8 · Bootstrap 5.3 · Shield (authentication) · Redis (production cache/sessions)

## What is in the core

- Staff users, roles with granular permissions (several roles per user), a staff directory
  with search and org links, departments
- Local sign-in plus optional **Microsoft Entra ID** SSO and directory sync
- Admin-controlled **multi-factor authentication**: authenticator app, email code and/or Duo, each
  optional; required per role; recovery codes; remember-this-device; admin reset
- Notifications (in-app bell and optional email) and user self-service: profile, photo,
  password, notification preferences, forgot-password by emailed link, change requests that an approver decides
- Audit log, responsive light/dark UI, strict Content-Security-Policy

## Local development

```bash
composer install
cp env .env                      # then set database.default.* and database.tests.*
php spark migrate --all
php spark app:install --email you@example.com --first You --last Admin --password 'a-long-password'
php spark serve                  # http://localhost:8080
```

Create two databases (for example `itm_backoffice` and `itm_backoffice_test`) with
`utf8mb4`. Tests use the `database.tests.*` group and **wipe it**; never point it at real data.

```bash
vendor/bin/phpunit               # full suite (about a minute)
vendor/bin/phpunit tests/Invoices   # one folder; add --filter <name> for one test
```

## Modules

| Module | What it does | Permissions |
|---|---|---|
| Core | Users, roles, departments, directory, companies and company access, audit, MFA, Entra, notifications | `users.*`, `roles.manage`, `companies.manage`, `tenancy.*`, `audit.view`, ... |
| Suppliers (core) | Shared supplier list used by any module; bulk CSV upload | `suppliers.view`, `suppliers.manage` |
| Invoices | Invoice register, approval workflow (routes, variants, teams, delegations), reports | `invoices.view`, `invoices.reports`, `invoices.manage`, `invoices.workflow` |

After pulling, run `php spark migrate --all` then `php spark db:seed RbacSeeder` so new tables, permissions and roles exist.
Give someone access to invoices by adding them to companies (Admin > Users > Manage company access) with the
submit / approve boxes, and assigning the Invoice User role.

## Versions and builds

The platform and each module have a `major.minor.bug` version in `app/Config/Backoffice.php` (shown in Admin > Modules and the sidebar footer).

```bash
php spark version:bump invoices minor   # module or "platform"; major | minor | bug
php spark app:build                     # stamp build number, commit and date into build.json (run on each deploy)
```

## Adding a module

1. Create the controllers/models/views.
2. Declare its `'version' => '1.0.0'`, permissions and menu entries in `app/Config/Backoffice.php`
   (`$modules`), then run `php spark db:seed RbacSeeder`.
   Also declare its `'roles'` (`user` and `manager`); see the registry for examples.
   If users hold rights per company, declare them under `'companyAccess'`; they appear on
   Manage company access and are checked with `service('scope')->can($userId, $companyId, 'module.key')`.
3. Protect routes with the `can:<permission>` filter, for example
   `['filter' => 'can:invoices.view']`; use `can('invoices.view')` in views.
4. Notify users through `service('notifier')->send(...)`, and record changes with
   `service('audit')->log(...)`. Register new notification types in
   `Config\Backoffice::$notificationTypes`.

## Documentation

- [Deployment (Apache, MySQL, Redis, cron)](docs/DEPLOYMENT.md)
- [`.env.production.example`](.env.production.example)

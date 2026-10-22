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
vendor/bin/phpunit               # full suite
```

## Adding a module

1. Create the controllers/models/views.
2. Declare its permissions and menu entries in `app/Config/Backoffice.php`
   (`$modules`), then run `php spark db:seed RbacSeeder`.
3. Protect routes with the `can:<permission>` filter, for example
   `['filter' => 'can:invoices.view']`; use `can('invoices.view')` in views.
4. Notify users through `service('notifier')->send(...)`, and record changes with
   `service('audit')->log(...)`. Register new notification types in
   `Config\Backoffice::$notificationTypes`.

## Documentation

- [Deployment (Apache, MySQL, Redis, cron)](docs/DEPLOYMENT.md)
- [`.env.production.example`](.env.production.example)

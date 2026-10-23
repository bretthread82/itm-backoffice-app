# Maloney Group Invoice Approval & Tracking

CodeIgniter 4.7 (PHP 8.2+, local 8.5) + MySQL 8 + Bootstrap 5.3. Built and hosted by ITM for Maloney Group.
Invoices are uploaded by an internal submitter, routed through approval steps, then saved to SharePoint.
Windows dev machine (Git Bash and PowerShell); production is Linux. Read `README.md` and `docs/DEPLOYMENT.md` for setup and ops.

## Commands

```
php spark serve                              # http://localhost:8080
php spark migrate --all                      # ALWAYS --all: plain `migrate` skips Shield's tables
php spark analytics:migrate                  # analytics database tables; deliberately NOT part of migrate --all
php spark db:seed DatabaseSeeder             # top-level group + default admin from .env
vendor\bin\phpunit [path]                    # full suite ~14 min on MySQL; run only the files you touched
bash tests/e2e/smoke.sh http://localhost:8092   # real-HTTP check; WIPES the dev database (see header of the script)
php spark files:purge --dry-run | sharepoint:sync | notifications:digest | notifications:escalate | notifications:send
php spark analytics:load [--dry-run] | analytics:backfill [--dry-run]      # long-term analytics ETL (nightly / one-off rebuild)
```

* **Version:** `version.json` (major.minor.bugfix + build) is read by `App\Services\AppVersion` and shown in the sidebar. Change it only with `php spark version:bump`.

## Databases

* MySQL only. `invoicing_dev` (the app) and `invoicing_test` (PHPUnit wipes its tables). Long-term analytics has its own pair: `invoicing_analytics` and `invoicing_test_analytics` (connection group `analytics`; under PHPUnit it is swapped for `analyticsTests`). Credentials live in `.env`, never in chat or code.
* `numberNative` is on in `app/Config/Database.php` so ids and counts are ints. Do not turn it off: strict `in_array(..., true)` / `===` on ids relies on it.
* MySQL DDL is not transactional: a migration that fails halfway leaves tables behind. Drop them and rerun, do not edit around it.
* Never run destructive DB commands (`migrate:refresh`, seeders, smoke.sh) without saying which database they hit.

## Architecture rules (do not break these)

* **Visibility is decided in one place: `AccessScope`.** Member companies, or everything for group-level users. Sub-groups are organisational only and never affect access.
* **Who may submit / approve** is per company: `user_companies.can_submit` and `can_approve`. `WorkflowEngine::roleFor` requires `can_approve` for the named approver, delegates and team members (group-level users stay exempt, via the audited override); the route editor refuses assignees without it.
* **Roles: user < manager < administrator** (`users.is_manager`, `users.is_admin`), plus a standalone **Workflow Manager** flag (`users.is_workflow`) that is not a rung on that ladder: it opens only Invoice Types, Suppliers, Approval Routes and Route Variants (the `workflow` filter group in `Routes.php`; managers and administrators pass it too) and nothing else. `AdminAccess::canManage()` deliberately excludes it (delegation overrides, routing import and the user importer stay manager-only); use `canManageWorkflow()` only for the four workflow screens, and only administrators can grant it (`setWorkflowManager`). A user is never both Manager and Workflow Manager: `setManager` / `setWorkflowManager` refuse the combination (`AdminAccess::MANAGER_OR_WORKFLOW`), the user form saves nothing when both are ticked, and switching from one to the other in a single save is supported. The `manager` filter guards day-to-day admin screens (users, teams, companies, delegations, imports, audit, analytics); the stricter `admin` filter guards settings, SharePoint, API tokens and group-level users. Only administrators grant admin/manager/group-level; a manager can never edit, disable or reset an administrator (`AdminAccess::requireCanAdminister`). Add new admin routes to the right group in `Routes.php`.
* **Statuses:** draft, in_approval, approved (at the process step), processed, paid, returned, rejected, cancelled (`completed` is retired; the API and register still accept it as "processed or paid"). A process step marks the invoice processed and queues the SharePoint filing; a separate `pay` step (after every approve/process step, at most one per route) records the payment reference and date and marks it paid. A route with no pay step ends at processed.
* **`WorkflowEngine` owns every status change.** Route steps are snapshotted as JSON at submission, so editing a route never changes invoices in flight. `invoice_actions` and `invoice_notes` are append-only; never add edit or delete paths.
* **Group-level users** (single admin-only boundary) can view and approve everything, with full accountability: `acted_as = group_level`, mandatory reason when not the named approver, override badge, notification, report.
* A note is **required** to return, reject or resubmit. Enforce it server-side.
* **SharePoint:** a connection is used only when enabled and complete; it can only be enabled after a successful test of its current settings. A company with its own connection never falls back to the default. No outbound call while disabled. Secrets are encrypted (`SecretBox`, `encryption.key`) and never displayed.
* **High priority invoice types:** `invoice_types.high_priority` is set only by managers, administrators and workflow managers (Admin > Invoice Types, any default or custom type; a default's flag pushes to its copies when saved). It is copied onto the invoice at submission. For those invoices `NotificationService::dispatch` marks the alert HIGH and emails approvers whatever their preferences say (`PRIORITY_EVENTS`: arrival, resubmit, return, reminder, escalation). Never add it to user-facing forms or `Invoices::FIELDS`.
* **File purge:** only finished invoices (paid, or processed on a route with no pay step; `completed_at` set) with confirmed SharePoint receipt (30 days from the later of completion and sync), or rejected/cancelled ones (30 days after ending). Never pending, failed, not_configured or in-flight invoices.
* **Long-term analytics** (`app/Services/Analytics`, screens at `admin/analytics`, manager filter) lives in a separate database (`db_connect('analytics')`) and is a **derived, rebuildable, read-only copy**: nothing in the workflow, API or live reports may read it, and a missing or down analytics DB must never affect the app (the screens catch it and show a notice). `AnalyticsLoader` rebuilds each touched invoice from `invoices` + `invoice_actions` + `step_escalations` (delete-then-insert, so re-runs are idempotent); `InvoiceFactBuilder` is the pure replay logic. Only `analytics:load` / `analytics:backfill` write to it, under one `JobLock`. Access is `AccessScope::restrict` on the analytics query builders (do not write a second rule). Never copy note bodies, `audit_log.detail`, files or SharePoint ids into it; money stays per currency. `snap_backlog_daily` rows with `reconstructed = 1` are estimates and are the only ones a backfill may replace. Its migrations live in `app/AnalyticsDb/Migrations`, run by `AnalyticsMigrator` (`analytics:migrate`), not in `app/Database/Migrations`, so `migrate --all` and `AppTestCase` never see them. Tests extend `Tests\Support\AnalyticsTestCase` (app schema plus a freshly migrated analytics schema).
* Entra SSO is built but disabled by default and never auto-creates accounts. Local accounts are the live sign-in.
* **Supporting documents** (`invoice_documents`, `InvoiceDocuments`, keys `documents/<name>.<ext>`) are attached through `WorkflowEngine::addDocument` by whoever `canAttach` allows (anyone who can act on the invoice, or the submitter; a group-level user must give a reason). Append-only like notes; an `attach` action is recorded but is not progress (it must not reset waiting clocks). They are filed to SharePoint by `SyncService::syncDocuments` after the invoice PDF, and purged under the same rules as the PDF plus their own confirmed SharePoint copy. Separate from `invoice_files`, so `InvoiceFiles::current()` stays the one invoice PDF.
* **Files go through `service('fileStore')`** (`app/Services/Files`; keys like `invoices/<name>.pdf`), never `WRITEPATH . 'uploads'` directly: it is `local` by default and `s3` on multi-server installs. `localPath()` may be a temporary copy, so read it, never delete it. Scheduled commands run inside `JobLock` (MySQL `GET_LOCK`) so the same cron line is safe on every server. See `docs/DEPLOYMENT.md` "Running on more than one server".
* Emails go through the `mailer` service (SendGrid; `log` transport in dev). Email failures must never block a workflow action.
* **API (`/api/v1`, read-only, see `docs/API.md`):** bearer tokens only (Shield access tokens, scope `api.read`); a token sees what its owner sees via `AccessScope`. Never read the session or `ActiveCompany` in API code. Output goes through `ApiPresenter` field whitelists (never `route_snapshot`, `sharepoint_*`, `stored_name`, hashes, SSO fields). A hidden invoice is 404, not 403. `app/Docs/openapi.json` is hand-written; `OpenApiSpecTest` keeps it in step with the routes. Only administrators create tokens (Admin > API tokens, for themselves or any active user). Do not add write endpoints without the same `WorkflowEngine` rules, notes and audit trail as the screens.

## Conventions

* `declare(strict_types=1);` in every PHP file; no BOM (a BOM breaks `strict_types`). Check after writing files from PowerShell.
* Services (`mailer`, `entra`, `sharepoint`) are injectable; tests use fakes in `tests/_support` (FakeMailTransport, FakeEntraClient, FakeSharePointClient) and Guzzle mock handlers. Never call real Microsoft, SendGrid or SharePoint from tests.
* Views: build links in emails with `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')`, not `esc($x, 'url')` (it mangles full URLs). Only render SharePoint links that start with `https://`.
* Admin screens are controllers in `app/Controllers/Admin`, routed inside the `admin` group in `app/Config/Routes.php` (filters: session, force-reset, admin), with a nav entry in `app/Views/layouts/app.php`. Tables use the `table-cards` pattern so they stack on phones.
* Feature tests for screens remove the `csrf` global filter in `setUp` (CSRF is covered in `CsrfEnforcedTest`). To test Shield-session behaviour call `session()` first, then `withSession`.
* Tests extend `Tests\Support\AppTestCase` (helpers: `makeCompany`, `makeUserWithEmail`, `join`, `makeRoute`, `makeInvoice`, `pdfFile`). Call `trackStoredFiles()` in `tearDown` when a test stores uploads.
* Throttled POSTs (login, magic link, password change, Microsoft sign-in): 10 per minute per address via the `throttle` filter.

## Gotchas

* `.env` values with spaces or `!` must be quoted. The default admin in dev is in `.env` (`admin.*`); production values are set by ITM.
* Shield setup commands are interactive; do not run them from here.
* PowerShell here is 5.1: no `&&`, and `Set-Content` writes ANSI; prefer the Write/Edit tools for files.
* Windows curl does not translate absolute POSIX paths in `-F @file`; the smoke script works from a temp dir with relative names.
* `php spark serve` leaves processes behind; stop stale `php.exe` servers before restarting on the same port.

## Not yet verified (say so in reports, do not imply otherwise)

Phone/tablet layouts in a real browser, a real SendGrid send, a real Entra sign-in, any real SharePoint/Graph call, and a fresh Linux install following `docs/DEPLOYMENT.md`. Also unverified: real Redis sessions/cache, a real S3 bucket (the S3 store is tested against a mocked client only), and more than one server behind a real load balancer.

## Open customer items

Meaning of the "- Yes" route variants (imported inactive, trigger deferred); updated routing list with emails; SharePoint site/app registration and list schema; Entra tenant and licence; default admin name/email; sub-group structure and first group-level users. Assumptions to confirm: a resubmitted invoice restarts at step 1; daily digest at 07:30.

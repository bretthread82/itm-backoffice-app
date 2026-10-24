# Evolution log

Newest first. One entry per change that touches modules, routes, permissions, notification types, migrations, shared components, design tokens or conventions. See the rules in [CLAUDE.md](../CLAUDE.md).

Entry format:

```
## YYYY-MM-DD: Short title
- What changed
- Why
- Files / areas: ...
```

## 2026-10-24: Mark notifications as read from the bell (platform 1.13.0)
- The bell now has a "Mark all read" button and a tick beside each unread item that marks it read without opening it. The Notifications page gets the same per-item tick (it already had Mark all read). New route `POST notifications/{id}/read`; it and `notifications/read-all` return JSON (new unread count and a fresh CSRF token) for the bell's AJAX calls and redirect back otherwise. `notifications/feed` now also returns the CSRF token and each item's `read` URL. Both actions only touch the signed-in user's own notifications.
- Why: opening a notification was the only way to clear it, which meant following its link.
- Files / areas: `app/Controllers/Notifications.php`, `app/Config/{Routes,Help}.php`, `app/Views/partials/topbar.php`, `app/Views/notifications/index.php`, `public/assets/js/app.js`, `tests/Core/NotificationReadTest.php`.

## 2026-10-24: Customer code and name marked as required (customers 1.1.2)
- The Customer code and Customer name labels now carry the red asterisk used on other forms, on the quick-add form and the details page. They were already required by the form, the service and bulk upload; only the marking was missing.
- Files / areas: `app/Views/admin/customers/{index,edit}.php`.

## 2026-10-24: Customer currency is a drop-down (customers 1.1.1)
- The customer currency is now a drop-down of the platform currency list (`invoice_currencies()`), on both the quick-add form and the customer details page. "Platform default" leaves it blank. Server-side validation is unchanged, so bulk upload still accepts any three-letter code.
- Why: typing a code invited typos and unknown currencies.
- Files / areas: `app/Views/admin/customers/{index,edit}.php`.

## 2026-10-24: Customer notification contact (customers 1.1.0, platform 1.12.0)
- A customer can now have one contact who receives any notifications sent to that customer. It is a new `is_notify` flag on `customer_contacts`, separate from `is_primary`. Set it when adding a contact or with the bell button on an existing one; choosing another contact replaces it, and the contact needs an email address. `CustomerService` gains `notificationContact()`, `setNotificationContact()` and `clearNotificationContact()`, all audited (`customer.notification_contact_set|cleared`). Callers that notify a customer should use `notificationContact()` and decide their own fallback (for example the customer's email). Nothing sends to customers yet.
- Routes: `POST admin/customers/{id}/contacts/{contact}/notify` and `POST admin/customers/{id}/contacts/clear-notify`, both under `customers.manage`. Migration `2026-10-24-120000_AddNotifyToCustomerContacts`: run `php spark migrate`.
- Why: so customer-facing messages go to the right person rather than a general mailbox.
- Files / areas: `app/Core/CustomerService.php`, `app/Controllers/Admin/Customers.php`, `app/Views/admin/customers/edit.php`, `app/Config/{Routes,Help}.php`, `tests/Customers/CustomerServiceTest.php`.

## 2026-10-24: Customer subscriptions (receivables 1.9.0, platform 1.11.0)
- Receivables can now track customers we bill again and again. A subscription has a company, customer (from the shared list, or a typed name), service, amount and currency, billing frequency (monthly, quarterly, yearly), next bill date, payment terms, optional end date and an owner. Screens: Receivables > Subscriptions (`receivables/subscriptions`, list, new, show, edit, pause, resume, end). No new permission: `receivables.view` opens the screens, AccessScope decides what is listed, and the per-company `receivables.submit` right decides who may set up or change one.
- New command `receivables:subscriptions` (daily, declared in the receivables `'cron'` entry, a `MonitoredCommand`). `SubscriptionBilling` raises a draft customer invoice for every cycle that has come due, through `ReceivablesEngine::createDraft` as the owner (invoice number and PDF are added by a person before submitting), claims the cycle in `ar_subscription_invoices` in one transaction so it is never billed twice, keeps the same day of the month (last day in short months), catches up at most three missed cycles per run, and ends a subscription after its last cycle before the end date. If a draft cannot be raised (for example the owner lost the raise right) nothing is left behind, the owner is told and the next run retries.
- Notices: owners and users holding `receivables.collect` for the company are told 14, 7 and 1 days before a bill date (`ReceivablesSettings::$subscriptionLeadDays`, comma separated), once per window, claimed in `ar_subscription_notices`. New notification types `ar.subscription_due`, `ar.subscription_billed`, `ar.subscription_failed`. `OverdueReminder::recipients()` is now public so both reminders pick people the same way.
- Migration `2026-10-24-100000_CreateArSubscriptions` adds `ar_subscriptions`, `ar_subscription_invoices` and `ar_subscription_notices`. Run `php spark migrate`, add the cron line from docs/DEPLOYMENT.md, then `php spark app:build`. Also: Subscriptions sidebar entry, a "billing in the next 14 days" line on the Receivables dashboard tab, a `customerUsage` count on Admin > Customers, Help section and FAQ, and a rule in CLAUDE.md.
- Why: to track subscription services and bill them on time instead of remembering each renewal.
- Files / areas: `app/Core/Receivables/Subscription*.php`, `app/Commands/ReceivablesSubscriptions.php`, `app/Controllers/Receivables/Subscriptions.php`, `app/Views/receivables/subscriptions/`, `public/assets/js/receivables-subscription-form.js`, `app/Config/{Backoffice,Routes,Help,ReceivablesSettings}.php`, `tests/Receivables/Subscription*Test.php`.

## 2026-10-24: Accounts sidebar heading (payables 2.1.0, receivables 1.8.0, platform 1.10.0)
- The Payables and Receivables sidebar groups now sit under one "Accounts" heading with a line separator above it (and a line after it where other entries follow). New registry config `$navSections` in `Backoffice.php`; a module joins a section with `'section' => '<key>'` inside its module-level `'group'` array. `nav_tree()` passes the section through and `partials/sidebar.php` draws the heading. New classes `.itm-nav-section-line` and `.itm-nav-rule` in `itm/navigation.css`.
- Why: both modules are about accounts, so they read better together.
- Files / areas: `app/Config/Backoffice.php`, `app/Views/partials/sidebar.php`, `public/assets/css/itm/navigation.css`.

## 2026-10-23: Invoices module renamed to Payables (payables 2.0.0, receivables 1.7.2, platform 1.9.0)
- The `invoices` module is now `payables`, matching `receivables`. This changes stored keys, so it is a major bump. Registry key, permissions (`payables.view|reports|manage|workflow`), company rights (`payables.submit|approve`, still stored in the `can_submit`/`can_approve` columns), roles (`payables_user`, `payables_workflow`, shown as Payables User/Manager), notification types and audit actions (`payables.*`), the sidebar badge key, the dashboard tab (`/?tab=payables`), Help topics (`payables`, `payables-reports`, `payables-setup`) and the admin section all use the new name.
- URLs are now `payables` and `payables-admin`. Old `invoices/*`, `invoices-admin/*` and `/?tab=invoices` links redirect (301) through the new `LegacyRedirects` controller, so bookmarks and older notification emails keep working.
- Code moved with it: `App\Core\Payables` (was `Core\Invoices`), `App\Controllers\{Payables,PayablesOverview,PayablesDelegations}` and `PayablesAdmin\*`, views in `app/Views/payables/` (the shared components are now `payables/components/*`; receivables views include them from there), `itm/payables.css`, tests in `tests/Payables/` with `PayablesTestCase`. Classes that name the document rather than the module (`InvoiceFiles`, `InvoiceQuery`, `InvoiceSettings`, `invoice_helper.php`) keep their names, as do `inv_*` tables, the `inv_users` view and `invoice_id`-style columns, because receivables shares them.
- Migration `2026-10-23-140000_RenameInvoicesModuleToPayables` renames the stored permission and role rows in place (custom role grants and user assignments survive), the module switch in settings, company capabilities, and notification types, preferences and links. It is reversible. Old audit rows keep their `invoice.*` action names. Uploaded files keep the `invoices/` storage prefix. Run `php spark migrate` then `php spark db:seed RbacSeeder`.
- Why: one naming pair for money out and money in; `invoices` was ambiguous next to customer invoices.
- Files / areas: `app/Config/{Backoffice,Routes,Help,Services}.php`, `app/Core/Payables/`, `app/Controllers/`, `app/Views/payables/`, `app/Database/Migrations/`, `tests/Payables/`, CLAUDE.md, README.md. This supersedes the `invoices` names in earlier entries below; those are left as written.

## 2026-10-23: Cron job monitoring (cron 1.0.0, platform 1.8.0)
- New admin module `cron` at Admin > Cron jobs, for administrators only: permission `cron.view` is given to the system Admin role (and Super Admin), not to Manager. It shows each scheduled job's state (on time, running, failed, late, stuck, or no runs yet), the last run and how long it took, a filterable run history (kept 90 days), a warning when the hourly check stops reporting, and the crontab lines to install.
- Jobs report themselves: scheduled commands now extend `App\Commands\MonitoredCommand` (implement `handle()` instead of `run()`), which records start and end in the new `cron_runs` table (migration `2026-10-23-130000_CreateCronRuns`). Recording never changes a job's result, `--dry-run` is not recorded, and an exception is recorded then re-thrown. `entra:sync`, `receivables:remind`, `backup:run` and `backup:check` were converted with no change in behaviour (entra 1.1.1, receivables 1.7.1, backups 1.0.1).
- Each module declares the jobs it owns in a new optional `'cron'` registry key (command, label, `every` minutes, example schedule, optional `grace` and `maxMinutes`); jobs of a switched-off module are not listed. New command `cron:check` (hourly) notifies administrators (`cron.attention`) once a day per job and state, and prunes history. The app cannot read the server's crontab, so "late" means "has not reported within its schedule plus allowance".
- Why: nothing showed whether cron was running or a job had started failing.
- Files / areas: `app/Commands/{MonitoredCommand,CronCheck}.php`, `app/Core/Cron/CronMonitor.php`, `app/Controllers/Admin/Cron.php`, `app/Views/admin/cron/`, `app/Config/{Backoffice,Routes,Help}.php`, `docs/DEPLOYMENT.md`, `CLAUDE.md`, `tests/Cron/`.

## 2026-10-23: Admin dashboard replaces the Administration sidebar group (core 1.4.0, platform 1.7.0)
- The Administration group is gone from the sidebar. The account (user) menu now has an Administration link to `admin`, a page of tiles grouped by section (People and access, Supplier invoices, Customer invoices, Shared data, System) showing only the options the signed-in user may use. Admin pages show an "Administration" link back to it above their title.
- Still registry-driven: admin entries keep `'group' => 'admin'` and gain `'section'` (key of the new `Backoffice::$adminSections`) and `'summary'`. `navGroups['admin']` is flagged `'sidebar' => false`, so `nav_tree()` skips it; new helpers `admin_tree()` (permission and module filtered, same `can()` check as the sidebar) and `admin_entry_for()` (which admin option a page belongs to). Entries without a known section are listed under Other.
- `GET admin` (`Admin\Home`) has no `can:` filter because the options come from many permissions; it answers 404 when the user has none. New shared component `.itm-tile` (and `.itm-crumb`) in `itm/cards.css`, shown on the Admin dashboard.
- Why: the sidebar group was 22 entries long and mixed rarely used settings with daily work.
- Files / areas: `app/Config/Backoffice.php`, `app/Helpers/app_helper.php`, `app/Controllers/Admin/Home.php`, `app/Views/admin/home.php`, `app/Views/partials/topbar.php`, `app/Views/layouts/app.php`, `app/Config/{Routes,Help}.php`, `public/assets/css/itm/cards.css`, `CLAUDE.md`, `tests/Core/AdminDashboardTest.php`.

## 2026-10-23: Database backups (backups 1.0.0, platform 1.6.0)
- New admin module `backups` at Admin > Backups. `php spark backup:run` (for cron) dumps the database with `mysqldump`, gzips it, encrypts it (AES-256-GCM, chunked, key from `backups.passphrase` in `.env`, independent of `encryption.key`) and sends it to every enabled destination: SharePoint / OneDrive (Microsoft Graph), S3-compatible bucket (own SigV4 signer, multipart for large files), WebDAV, or a local/mounted folder. Retention is per destination (`keep_last`), only ever touching files named `itm-backup-*.sql.gz[.enc]`. No new Composer packages.
- Status for managers and admins: stat cards (last good backup, latest run, destinations, encryption), a paginated run history and a per-run page showing the outcome at each destination. Managers add, edit, test and remove destinations, and press Run now (starts `backup:run` in the background under the same lock as cron). Destination settings are stored encrypted with the app key (`SecretBox`); secrets are never shown again.
- Permissions `backups.view` (status) and `backups.manage`; role `backups_manager`. System Admin gets both, system Manager gets `backups.view`. Notification types `backups.failed` and `backups.stale`. Commands `backup:run`, `backup:check` (hourly staleness check, notifies once a day), `backup:decrypt` (restore). Migration `2026-10-23-120000_CreateBackupTables` adds `bk_destinations`, `bk_runs`, `bk_run_destinations`.
- Why: there was no way to recover the database, and no visibility of whether copies existed off the server.
- Files / areas: `app/Core/Backups/`, `app/Commands/Backup*.php`, `app/Config/Backups.php`, `app/Controllers/Admin/Backup*.php`, `app/Views/admin/backups/`, `app/Config/{Backoffice,Routes,Help}.php`, `env`, `docs/DEPLOYMENT.md` (new section 8; later sections renumbered), `tests/Backups/`.

## 2026-10-23: Receivables reports (receivables 1.7.0)
- Customer invoice reports under `receivables/reports`, behind `receivables.reports` (in the sidebar as Customer invoice reports). Three tabs sharing a company and sub-group filter: Outstanding and ageing (total outstanding, overdue, critical 61+ days, paid on time and average days to collect; ageing bands; by company; by sub-group; most overdue), By customer (who owes what, most behind first), and Collections (invoiced and received month by month for the last 12 months, with a chart). Each has a CSV download (`outstanding.csv`, `customers.csv`, `collections.csv`), audited as `ar.report_exported`.
- New `ArReportService`, controller `Receivables\Reports`, views in `app/Views/receivables/reports/`, script `public/assets/js/receivables-reports.js`. Outstanding means the balance after payments, not the invoice amount; ageing counts from the day after the due date on each invoice's own company calendar, in the same bands as the payables reports (reusing `ReportService::BANDS`, `bandFor` and `isCritical`). Money is never added across currencies, so the collections chart draws one currency at a time (`?currency=`) and the table shows them all. A customer linked to the shared list is reported under its current name, so a rename does not split it; invoices for a customer not on the list are grouped by the typed name, ignoring case.
- Received is by the date received and net of reversals; "paid on time" is judged on the company's calendar. Everything is limited through `AccessScope`; a company filter for a company the user cannot see is ignored.
- The chart reads its colours from the `--itm-*` tokens at load time rather than hard-coding them (the payables chart hard-codes hex values in its view). Colours are not redrawn if the theme is switched without reloading the page.
- Help: a Reports section in "Customer invoices". Not built: a long-term analysis tab (volume, cycle time, backlog history) like payables has.
- Why: last part of phase 4 of the receivables plan.
- Files / areas: `app/Core/Receivables/ArReportService.php`, `app/Controllers/Receivables/Reports.php`, `app/Views/receivables/reports/`, `public/assets/js/receivables-reports.js`, `app/Config/{Routes,Backoffice,Help}.php`, `tests/Receivables/ReceivablesReportsTest.php`.

## 2026-10-23: Receivables hand-over when someone leaves (receivables 1.6.0)
- New Administration > Hand over customer invoice work (`receivables-admin/handover`, needs `receivables.manage`): choose the person leaving and a replacement, see what would move, then hand it over. New service `ArUserOffboarding` (`preview()`, `missingFor()`, `transfer()`), controller `Receivables\Admin\Handover`, view `receivables/admin/handover.php`.
- What moves: approval and issue steps still ahead on invoices in progress (the route snapshot is edited for those steps only, with a note on the invoice), the person's steps on routes (including the name shown on them), unfinished invoices they raised, and invoices they had accepted for a team (released back to the team, recorded in the history). Their delegations, given or received, are ended. Issued invoices stay put; `ar_actions`, `ar_notes` and `ar_payments` are never rewritten. Team steps stay with the team. Everything is audited (`ar.work_transferred`, `ar.left_teams`) and the replacement is told about the step that is now theirs.
- The replacement must already hold the per-company rights the work needs: membership of each company, `receivables.approve` where steps or routes are moved, `receivables.submit` where raised invoices are moved. The check names what is missing and nothing is changed if anything is. This is stricter than the payables service, which only checks that the replacement can see the company.
- Optionally switches the account off and removes the person from every team, through `UserService::setStatus()`. That option needs `users.delete` as well and is not offered otherwise. It runs after the work has moved, so if the platform refuses (for example the last Super Admin) the work is already with the replacement. Only a Super Admin can hand over a Super Admin's work.
- Found while doing this: the payables `App\Core\Invoices\UserOffboarding` is not used by any screen or test, so payables work still has no hand-over, and it does not update the name on route steps. Left as it is.
- Why: phase 4 (part 4) of the receivables plan. Deactivating an account alone leaves its named steps, drafts and delegations stranded.
- Files / areas: `app/Core/Receivables/ArUserOffboarding.php`, `app/Controllers/Receivables/Admin/Handover.php`, `app/Views/receivables/admin/handover.php`, `app/Config/{Routes,Backoffice,Help}.php`, `tests/Receivables/ReceivablesHandoverTest.php`.

## 2026-10-23: Receivables My tasks and dashboard tab (receivables 1.5.0)
- My customer invoice tasks (`receivables/tasks`, in the sidebar with a count of what is waiting on you): invoices waiting on you (as named approver, active delegate, or team member, including team steps still to be accepted), overdue invoices you are expected to chase (the ones you raised, and those in companies where you hold `receivables.collect`), your own invoices sent back, in approval, and drafts. Group-level users also see everything in approval.
- Dashboard: a Receivables tab (`'dashboard'` registry entry, provider `ArDashboardTab`, view `dashboard/tabs/receivables.php`) with waiting, sent back, overdue and due-in-7-days cards, the amounts still to collect and received in the last seven days (net of reversals, per currency), the overdue and waiting lists, and recent activity including payments. The tab badge counts what is waiting plus what came back. The Today tab shows it too.
- New classes `ArApprovalQueue`, `ArNavCounts` (session-cached for a minute), `ArActivityFeed` and `ArDashboardTab`; controller `Receivables\Tasks`; views `receivables/tasks/`. New nav badge key `receivables.waiting` in `nav_badge()`. Help covers My tasks and the dashboard.
- Everything is limited through `AccessScope`; the dashboard totals ignore invoices of companies the user cannot see.
- Why: phase 4 (part 3) of the receivables plan.
- Files / areas: `app/Core/Receivables/`, `app/Controllers/Receivables/Tasks.php`, `app/Views/receivables/tasks/`, `app/Views/dashboard/tabs/receivables.php`, `app/Config/{Routes,Backoffice,Help}.php`, `app/Helpers/app_helper.php`, `tests/Receivables/ReceivablesTasksTest.php`.

## 2026-10-23: Receivables admin screens (receivables 1.4.0)
- Setup screens for customer invoices, so a company can be set up without touching the database. Under Administration: Customer invoice routes and the route builder (`receivables-admin/routes`), Customer invoice types (`invoice-types`: default types assigned to companies in bulk, plus a High priority switch on any company type), Customer route variants (`route-variants`), and Customer invoice teams (`teams`). Routes, types and variants need `receivables.workflow`; teams need `receivables.manage`. Under Receivables: My delegations (`receivables/delegations`), open to everyone with `receivables.view`; managers can add `?all=1` to see and set up delegations for others.
- Controllers in `app/Controllers/Receivables/Admin/` and `Receivables/Delegations.php`, views in `app/Views/receivables/admin/` and `receivables/delegations.php`, new classes `ArRouteVariants`, `ArTeamMembers` (removing a member returns what they had accepted to the team) and `ArDefaultTypeAssigner`. These started as copies of the payables ones, with the `ar_` tables, `ar.*` audit events and the receivables rights.
- Route rules: step types are submit, approve, issue and notify. A route may have at most one issue step and it must come after every approve step (checked on every save); to be switched on it needs exactly one issue step as the last step anyone acts on. `ReceivablesEngine::routeProblem()` holds that rule and is used both by the route builder and when an invoice is submitted. Anyone named on an approve or issue step, or in a team on one, must hold the per-company `receivables.approve` right, checked through `service('scope')->can()`.
- Company-type priority now posts to `receivables-admin/company-types/{id}/priority`, so it cannot be confused with a default type id.
- New module stylesheet `public/assets/css/itm/receivables.css` (imported by `itm.css`): the route builder's drag handle and time-limit field, which the copied markup had as inline `style` attributes that the strict CSP blocks. The payables route builder still has those two inline styles.
- Also fixed in the copy: the variant badge on the route page printed `<?= esc(...) ?>` as text. The same bug is in the payables `invoices/admin/routes/edit.php`, which is untouched.
- Help: new topic "Customer invoice set-up" (routes, variants, types, teams), and a delegation section in "Customer invoices".
- Not copied: bulk upload of default types and the routing import, which the payables screens link to.
- Why: phase 4 (part 2) of the receivables plan.
- Files / areas: `app/Controllers/Receivables/`, `app/Views/receivables/`, `app/Core/Receivables/`, `app/Helpers/{receivables_helper.php,ar_route_step_row.view.php}`, `app/Config/{Routes,Backoffice,Help}.php`, `public/assets/css/`, `tests/Receivables/ReceivablesAdminTest.php`.

## 2026-10-23: Receivables screens, part 1 (receivables 1.3.0)
- The first Receivables screens, behind `receivables.view` and the per-company rights: the register at `receivables` (filters, sorting, paging, CSV export, summary cards for outstanding, overdue and received), a form to raise and edit a customer invoice with a live preview of its approval route, and the invoice page with the PDF viewer, the approve / return / reject / issue bar, team accept and release, notes, supporting documents, and a Payments tab to record and reverse receipts. Issuing asks who the invoice was sent to. Payments need `receivables.collect` as well as the per-company right.
- Routes under `receivables/...` (JSON `receivables/types` for the form), controller `App\Controllers\Receivables\CustomerInvoices`, views in `app/Views/receivables/`, script `public/assets/js/receivables-form.js`. Sidebar group Receivables with status shortcuts (All, Drafts, In approval, To be issued, Outstanding, Overdue, Paid, Returned). Help topic "Customer invoices".
- New query and timeline classes `ArQuery` and `ArTimeline`, and helper `receivables_helper.php` (autoloaded): `ar_status_badge()` shows an issued or part-paid invoice as "overdue" in red with the words, not colour alone.
- Shared: new `AccessScope::companiesWith($userId, $capability)` lists the active companies where a user holds a per-company capability, for choices in forms. It works for both stored and legacy-column capabilities, so the payables screens can adopt it later.
- Reuse, no new tokens or CSS: the screens use the existing theme and the generic classes in `itm/invoices.css`, and include the `invoices/components/*` views (page header, stat card, approval timeline) and the `ReportService` age filters. Follow-up: move those components to a neutral path so Receivables does not depend on the Invoices folder.
- Not yet: My tasks, the dashboard tab, reports, the admin screens for routes, invoice types, teams and delegations, and offboarding. Until the admin screens exist, routes and types for a company are set up in the database.
- Why: phase 4 (part 1) of the receivables plan.
- Files / areas: `app/Controllers/Receivables/`, `app/Views/receivables/`, `app/Core/Receivables/{ArQuery,ArTimeline}.php`, `app/Core/Tenancy/AccessScope.php`, `app/Helpers/receivables_helper.php`, `app/Config/{Routes,Backoffice,Help,Autoload}.php`, `public/assets/js/receivables-form.js`, `tests/Receivables/ReceivablesScreensTest.php`.

## 2026-10-23: Receivables collection (receivables 1.2.0)
- New `PaymentService`: `record()` and `reverse()` for money received against issued customer invoices. Receipts are append-only rows in `ar_payments`; a mistake is undone by a reversal (a negative row pointing at the receipt, with a mandatory reason), never an edit or delete. Each call locks the invoice row, writes the receipt and asks the engine to bring the invoice in line, in one transaction, so two people recording at once cannot overpay. Needs the per-company right `receivables.collect`; there is no group-level override for money.
- `ReceivablesEngine::refreshCollection()` is the only place collection changes the status: `issued` with nothing received, `part_paid`, `paid` when the amount is covered (sets `paid_at` and `ended_at`). Reversing a receipt on a paid invoice reopens it. It refuses to run outside a transaction. This keeps the rule that only the engine writes `ar_invoices.status`.
- Rules on a receipt: amount above zero with at most two decimals and no more than is still owed; date received valid and not in the future on the company's calendar; method optional but from the listed set; the invoice must be issued or part paid.
- New `Collections` helper. Overdue is derived and never stored: an issued or part-paid invoice is overdue from the day after its due date, counted on its company's own calendar (`overdue()` lists them most overdue first; `outstanding()` gives the balance).
- New command `php spark receivables:remind [--dry-run]` and `OverdueReminder`. The raiser and everyone holding `receivables.collect` for the company are told (`ar.overdue`) the day after the due date and then every `ReceivablesSettings.remindEveryDays` days (new config `Config\ReceivablesSettings`, default 7). A day is claimed by inserting into the new `ar_reminders` table first (unique per invoice and day), so running it twice or overlapping runs never reminds twice. It exits cleanly while the module is off. Add it to cron (see DEPLOYMENT).
- New migration `2026-10-23-110000_CreateArReminders`. New notification type `ar.payment_reversed`; `ar.payment_received`, `ar.paid` and `ar.overdue` now have wording in `ArNotifier`. Audit events `ar.payment_recorded`, `ar.paid`, `ar.payment_reversed`.
- Why: phase 3 of the receivables plan. Still no screens, routes or sidebar entries.
- Files / areas: `app/Core/Receivables/{PaymentService,Collections,OverdueReminder,ReceivablesEngine,ArNotifier}.php`, `app/Commands/ReceivablesRemind.php`, `app/Config/{ReceivablesSettings,Backoffice}.php`, `app/Database/Migrations/`, `docs/DEPLOYMENT.md`, `tests/Receivables/`.

## 2026-10-23: Receivables engine (receivables 1.1.0)
- New `App\Core\Receivables\ReceivablesEngine`: the customer invoice state machine, a parallel of the payables `WorkflowEngine` that leaves it untouched. Create and edit drafts, submit and resubmit, approve, return (to the raiser or to the previous approver), reject, cancel, notes, supporting documents, team accept and release, delegation and group-level override.
- Status flow: draft, in_approval, approved (waiting at the issue step), issued; returned, rejected and cancelled as in payables. Approving the issue step records `sent_to` and `issued_at`; the invoice is then issued, not ended, and collection (phase 3) ends it. A route needs exactly one `issue` step and it must be the last step anyone acts on, otherwise submission is refused with a message for the administrator.
- Rights are checked only through `service('scope')->can()` with `receivables.submit` and `receivables.approve`, and the company must be active; the engine does not read `user_companies.can_*`, so the invoices rights give nothing here.
- Submit also refuses a number another non-cancelled invoice of the same company already uses. The customer is taken from the shared list by id or name and kept as a snapshot; a switched-off customer cannot be newly chosen but may stay on an invoice that already has it. The customer's default currency applies when none is given.
- Supporting classes: `ArNotifier` (the `ar.*` wording, links to `receivables/<id>`), `ArAudit`, `ArDelegationResolver`, `ArFiles` and `ArDocuments` (upload checks are delegated to `InvoiceFiles` and `InvoiceDocuments` so there is one set of rules; files are stored under `receivables/`), and `ReceivablesException`. `inv_users` is reused for names.
- CLAUDE.md gains the rule that only the engine writes `ar_invoices.status`.
- Why: phase 2 of the receivables plan. Still no screens, routes or sidebar entries.
- Files / areas: `app/Core/Receivables/`, `tests/Receivables/`, `tests/_support/Receivables/`, `CLAUDE.md`, `app/Config/Backoffice.php`.

## 2026-10-23: Receivables foundation (platform 1.5.0, receivables 1.0.0)
- New optional module `receivables` (customer invoices we issue and collect on), declared in the registry with no screens yet: the register, workflow engine and payments follow in later changes, so the sidebar has no Receivables entries for now.
- New migration `2026-10-23-100000_CreateReceivablesModuleTables`: `ar_invoices` (customer name snapshot plus `customer_id` to the core `customers` table, `amount_paid`, issued and paid dates, a CHECK that paid never exceeds the amount), append-only `ar_payments` (reversal is a negative row that points at the receipt; a receipt can be reversed once), and structural twins of the `inv_` routing and history tables (`ar_invoice_types`, `ar_routes`, `ar_route_steps`, `ar_route_variants`, `ar_teams`, `ar_team_members`, `ar_delegations`, `ar_files`, `ar_documents`, `ar_notes`, `ar_actions`, `ar_step_claims`, `ar_job_titles`, `ar_default_invoice_types`). Route step types are submit, approve, issue and notify; there is no pay step. Names still come from the `inv_users` view.
- New permissions `receivables.view`, `.reports`, `.collect`, `.manage`, `.workflow`; roles Receivables User (also `customers.view`) and Receivables Manager (also `customers.manage`); Admin and Manager system roles gain the receivables permissions. Run `php spark db:seed RbacSeeder`.
- New per-company rights on Manage company access: Can raise, Can approve and issue, Can record payments (`receivables.submit|approve|collect`, stored in `user_company_capabilities`, separate from the invoices rights).
- Sixteen `ar.*` notification types registered. The module declares `customerUsage`, so Admin > Customers now shows a Receivables invoices count while the module is on.
- Why: phase 1b of the receivables plan. The payables engine is untouched.
- Known issue found, not changed here: the committed `inv_` migration passes its foreign-key rules to `Forge::addForeignKey` as (delete, update), but the method takes (onUpdate, onDelete). The `inv_` tables therefore cascade on delete where RESTRICT or SET NULL was intended (for example deleting a company deletes its invoices). The `ar_` migration uses the correct order. A new migration should correct `inv_`.
- Files / areas: `app/Database/Migrations/`, `app/Config/Backoffice.php`, `tests/Receivables/`.

## 2026-10-23: Global customers (platform 1.4.0, customers 1.0.0)
- New core `customers` module and shared service `service('customers')` (`App\Core\CustomerService`), mirroring suppliers so that receivables now, and CRM, quotes or projects later, can all use one customer list. New tables `customers` and `customer_contacts` (migration `2026-10-23-090000_CreateCustomers`). Customers hold only neutral details (contact, billing address, tax id, payment terms, default currency); balances and credit limits belong in the consuming module.
- New permissions `customers.view` and `customers.manage`, roles Customers User and Customers Manager, and Admin > Customers (list, edit, contacts, CSV bulk upload via `CustomerImporter`). Admin and Manager system roles gain both permissions. Run `php spark db:seed RbacSeeder`.
- New registry key `customerUsage` (alias => SQL with `{id}`): a module that references customers declares it and Admin > Customers shows the count while the module is on, so the screen never names another module's tables.
- Audit events `customer.created`, `customer.updated`, `customer.contact_added`, `customer.contact_removed`, `customer.bulk_uploaded`. CLAUDE.md gains the customers rule. Help gains a Customers section.
- Why: first step of the receivables plan. Customers are a platform entity, not part of any one module, and ship before any receivables code.
- Files / areas: `app/Core/{CustomerService,CustomerImporter}.php`, `app/Controllers/Admin/Customers.php`, `app/Views/admin/customers/`, `app/Config/{Backoffice,Routes,Services,Help}.php`, `tests/Customers/`.

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

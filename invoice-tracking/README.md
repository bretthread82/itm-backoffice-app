# Maloney Group Invoice Approval & Tracking

CodeIgniter 4 (PHP 8.2+) + MySQL 8 + Bootstrap 5. Replaces the earlier Power Platform design.
See the approved plan in the ITM project notes for scope and decisions.

## Local development

```
composer install
copy env .env            # then edit: baseURL, database, admin.* (quote values that contain spaces)
php spark key:generate
php spark migrate --all  # app tables + Shield + Settings
php spark db:seed DatabaseSeeder   # top-level group + default admin from .env
php spark serve
```

The app runs on **MySQL 8** (MySQLi driver, utf8mb4). Create two empty databases and a user, for example
`invoicing_dev` (the app) and `invoicing_test` (the test suite, which wipes its tables), and set
`database.default.*` and `database.tests.*` in `.env`. `numberNative` is on so IDs and counts come back as integers.
Always use `php spark migrate --all`: plain `migrate` skips Shield's tables.

Long-term analytics uses a third pair of databases (`invoicing_analytics`, and `invoicing_test_analytics` for the tests; set
`database.analytics.*` and `database.analyticsTests.*`). Create the schemas, then `php spark analytics:migrate` (it is not
part of `migrate --all`) and `php spark analytics:backfill`. See docs/DEPLOYMENT.md "Long-term analytics".

The seeded administrator must change their password at first sign-in. Set `admin.email`,
`admin.password` (12+ characters) and `admin.name` in `.env`; never commit real values.

## Importing the routing list

Admin > Import routing. Upload an export of the SharePoint *Approval Routing* list (CSV or XLSX; columns
Company, Invoice Type, Role Order, Role, Person and optionally Person Email), review the report, then import.
Nothing is written until you confirm. People without an email become inactive placeholders; upload the updated
list with emails later (tick "replace steps of existing routes") and the same accounts get their email addresses,
then use Admin > Users > "Invite all pending". Routes are edited in Admin > Routes.

## Uploading an invoice

* Required to save, even as a draft: company, invoice type, invoice number, invoice date, due date, amount (above zero)
  and currency. The supplier and the document are required to submit. The currency is a list
  (`invoice_currencies()` in `app/Helpers/invoice_helper.php`) that defaults to Barbados dollar (BBD) through
  `AppSettings.defaultCurrency` (Admin > Settings).
* The document is a **PDF**, or **photos of a paper invoice** (JPEG, PNG or WebP, up to 20 pages). Photos are checked,
  turned upright, scaled down and combined into one PDF on the server by `ImagePdfBuilder` (PHP `gd` required), then go
  through the same checks as an uploaded PDF. Everything after the upload (approval, preview, SharePoint, purge, API)
  only ever sees a PDF.
* "Take photo of paper invoice" opens an in-browser camera preview with a list of every camera the browser detects. It
  needs https (localhost is fine in development) and the user's permission; otherwise it falls back to the device's own
  camera app.
* API tokens are created by administrators only (Admin > API Tokens), for themselves or any active user.

## Dashboard and reports

* **Outstanding** = submitted and not yet paid: in approval, at the process step, returned for changes, or processed and waiting at the pay step. Drafts,
  rejected and cancelled invoices are not outstanding. Lifecycle: in approval, approved (at the process step), processed, paid. A route without a pay step ends at processed.
* **Aging** is by days past the due date: not yet due, 1-30, 31-60 (warning), 61-90 and over 90 (critical).
  **Critical starts at 61 days.** The threshold is `ReportService::CRITICAL_FROM`.
* Totals are always shown **per currency** and never added across currencies.
* Everything is limited to the companies the signed-in user can see (members: their companies; group-level users:
  all). The Reports company filter cannot widen that.
* Dashboard: to-do tiles, then totals, aging, by-company and most-overdue. Reports adds sub-group totals, company
  and sub-group filters and a CSV of outstanding invoices. Every tile links to the invoice list with the matching
  filter (`status=outstanding`, `age=overdue|critical|current|1-30|31-60|61-90|90+`).
* Admin > Group-level activity: every action a group-level user took on a step that was not theirs, with reason, step
  and who it was on behalf of; filters by person, company and date; CSV; and a log of who gave or removed
  group-level access. Exports are written to the audit log.
* CSV files neutralise cells that start with `=`, `+`, `-` or `@` so supplier names cannot run as spreadsheet formulas.

## Microsoft sign-in (Entra ID): built in, off by default

Local email/password sign-in always works, including for administrators, whether Microsoft sign-in is on or off.

To enable it later (needs the customer's Microsoft 365 tenant):

1. In the Entra admin centre create an **app registration**: single tenant, platform **Web**, redirect URI exactly
   as shown in Admin > Settings (`https://<host>/auth/microsoft/callback`), delegated permissions `openid`,
   `profile`, `email`. Create a client secret. Registering an app for sign-in is normally included with Microsoft 365;
   Conditional Access policies are what need a premium licence.
2. Put these in the server's `.env` (never in source control):
   `entra.tenantId = <directory (tenant) ID>`, `entra.clientId = <application (client) ID>`, `entra.clientSecret = <secret>`.
   The tenant must be the organisation's own ID: `common`, `organizations` and `consumers` are refused, because they
   would let accounts from other organisations in.
3. Admin > Settings > Microsoft sign-in > switch on. The "Sign in with Microsoft" button then appears on the login page.

Rules, all enforced and tested:

* **Accounts are never created automatically.** The person must already exist (bulk upload or Admin > Users) with
  the same email address. Unknown, inactive and email-less placeholder users are refused.
* The first sign-in links the Microsoft account's stable object id to the user. After that the object id is what
  matches, so a renamed mailbox still works, and an email that is already linked to a *different* Microsoft account
  is refused. Admin > Users > Edit has "Unlink".
* The sign-in is bound to the browser (state, nonce, PKCE), expires after 10 minutes and works once. The ID token's
  signature, audience, issuer, expiry and tenant are checked, and tokens without a signature are refused.
* If Microsoft cannot be reached, users see a plain message and can still use their password.
* Switching it off, or removing the `.env` values, hides the button and makes both endpoints refuse.

## Email (SendGrid)

Two paths, both configured in `.env`:

* **Notifications** (invoice alerts, daily summary): SendGrid Web API.
  `mail.transport = sendgrid` (default in production; `log` in development, which only writes to the log)
  and `sendgrid.apiKey = SG.xxxx` (a key restricted to "Mail Send").
* **Invitations and sign-in links** (Shield): CodeIgniter's email class over SendGrid's SMTP relay:
  `email.protocol = smtp`, `email.SMTPHost = smtp.sendgrid.net`, `email.SMTPUser = apikey`,
  `email.SMTPPass = <the same API key>`, `email.SMTPPort = 587`, `email.SMTPCrypto = tls`.

The sender address and name are managed in Admin > Settings (default `invoicing@maloneygroup.net`). The sender
domain must be authenticated in SendGrid (SPF/DKIM records added by the customer to their DNS).

## Scheduled jobs (cron)

```
*/5 * * * *  cd /var/www/app && php spark notifications:send     # retry emails that failed or were left pending
30 7 * * *   cd /var/www/app && php spark notifications:digest   # once-a-day summary of outstanding invoices
0 * * * *    cd /var/www/app && php spark notifications:escalate # remind / escalate invoices past a step's time limit
*/5 * * * *  cd /var/www/app && php spark sharepoint:sync          # send processed invoices to SharePoint
15 3 * * *   cd /var/www/app && php spark files:purge             # delete old server copies of PDFs (try --dry-run first)
30 1 * * *   cd /var/www/app && php spark analytics:load          # nightly copy into the long-term analytics database
```

`notifications:digest` is safe to run more than once a day (one summary per user per day) and sends nothing to
people with nothing outstanding.

`notifications:escalate` uses the optional **Time limit (days)** on each approve/process/pay step in the route builder. After that
long at the step the approver (or the team member who accepted it) is reminded; after twice as long every active
group-level user is told. Each is sent once per visit to a step (a resubmitted invoice starts afresh), the limit is
copied into the invoice when it is submitted (so editing a route does not affect invoices in flight), and steps with no
limit are never chased. Days are calendar days. Email failures never block an approval: they are logged in the `notifications`
table and retried (up to 5 attempts).

## SharePoint (placeholders until credentials exist)

Processed invoices are saved to SharePoint (the PDF in a document library, plus an optional list item) through
Microsoft Graph with an app-only sign-in. **Nothing is sent until an administrator switches a connection on**; until
then finished invoices wait in the queue ("No connection yet") and their PDFs are kept.

* **Admin > SharePoint connections**: create as many as needed. Blank placeholders are fine.
  Each holds tenant ID, client ID, client secret (stored encrypted with `encryption.key`, never shown again),
  site address, library, folder, optional list name and a column mapping such as
  `{"Title": "invoice_number", "Supplier": "supplier", "Amount": "amount"}` (SharePoint *internal* column names).
* **Which one a company uses**: chosen on the company page. A company with its own connection uses only that one
  (it never silently falls back); a company with none uses the connection marked Default.
* **Switching on** needs a successful *Test connection* of the current settings (sign-in, site, library, list and every
  mapped column; nothing is written). Changing any setting, or the secret, needs a new test. A failed test or send
  switches the connection off.
* **Admin > SharePoint sync**: the queue, with Retry and Sync now. Failures retry with growing delays (5 minutes up to
  6 hours, 8 attempts), then administrators are alerted. A file already uploaded is not uploaded twice on retry.
* **Microsoft side** (ITM): register an app, create a secret, give it the *Sites.Selected* application permission with
  admin consent, then grant it write access to just the one site. Set `encryption.key` in `.env` before saving any secret,
  and do not change it afterwards or saved secrets cannot be read.

Production setup, backups, restore and the file-purge rules are in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Versioning

The version (`major.minor.bugfix`) and a build number live in `version.json` and show at the foot of the sidebar.

```
php spark version:bump bugfix   # fix only:        1.2.3 -> 1.2.4
php spark version:bump minor    # new feature:    1.2.3 -> 1.3.0
php spark version:bump major    # breaking change: 1.2.3 -> 2.0.0
php spark version:bump build    # same version, new build
php spark version:bump          # show the current version
```

Every bump adds one to the build number, which never resets. Run it when cutting a release, then commit `version.json`.

## Web server

Clean URLs (no `index.php`). The document root is `public/`, and unknown paths must go to `public/index.php`.
Nginx: `location / { try_files $uri $uri/ /index.php$is_args$args; }` with PHP-FPM. Apache uses the shipped
`public/.htaccess` (`mod_rewrite`). Uploaded invoice PDFs live in `writable/uploads/invoices` (outside the web
root) and are only served through the app after an access check. Raise `upload_max_filesize` and `post_max_size`
at least to the "Largest upload" setting (default 20 MB per file; photos can add up to 20 files, so also check
`max_file_uploads`).

## Tests

```
vendor\bin\phpunit        # needs the database.tests.* MySQL database, about 3 minutes
bash tests/e2e/smoke.sh   # real HTTP: login, CSRF, multipart PDF upload, PDF endpoint, API token creation, bell (see the file header)
```

CodeIgniter's feature-test harness cannot simulate multipart uploads, so two upload tests are skipped on purpose
and `tests/e2e/smoke.sh` covers that path against a running server and a throwaway database.

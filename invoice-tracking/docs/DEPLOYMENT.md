# Deployment (Linux, managed by ITM)

## Server

* PHP 8.2+ with `intl`, `mbstring`, `curl`, `fileinfo`, `mysqli`, `sodium`/`openssl`; MySQL 8; Apache or Nginx with PHP-FPM.
* Document root is `public/` only. Nothing else in the project may be reachable by URL.
* `writable/` is owned by the web user and is **not** inside the document root (uploads live in `writable/uploads`; company and group logos are in `writable/uploads/logos`, which is not touched by the file purge and should be included in backups).
* TLS everywhere. Redirect HTTP to HTTPS and send `Strict-Transport-Security` from the web server.
* PHP limits: `upload_max_filesize` and `post_max_size` at least as large as the upload limit in Admin > Settings (default 20 MB), plus a little.
* Photos of paper invoices are converted to a PDF on upload: PHP `gd` is required (`exif` is recommended so phone photos are turned upright). `max_file_uploads` must be at least 20 and `post_max_size` large enough for several photos. The in-browser camera picker needs the site to be served over https (localhost is fine for development); on plain http the button falls back to the device camera app.

### Nginx

```
server {
    server_name invoicing.example.com;
    root /var/www/app/public;
    index index.php;
    client_max_body_size 25m;

    location / { try_files $uri $uri/ /index.php$is_args$args; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

(Apache: `DocumentRoot /var/www/app/public`, `AllowOverride All` so the shipped `.htaccess` applies.)

## First install

```
composer install --no-dev --optimize-autoloader
cp env .env             # then edit, see below
php spark migrate --all
php spark analytics:migrate   # long-term analytics tables (see "Long-term analytics" below)
php spark db:seed       # creates the default administrator (see .env), who must change the password at first sign-in
```

### `.env` for production

```
CI_ENVIRONMENT = production
app.baseURL = 'https://invoicing.example.com/'
app.forceGlobalSecureRequests = true
cookie.secure = true

database.default.hostname = localhost
database.default.database = invoicing
database.default.username = invoicing
database.default.password = <strong password>
database.default.DBDriver = MySQLi

encryption.key = <php spark key:generate --show>     # set once; never change it afterwards
mail.transport = sendgrid
sendgrid.apiKey = SG.xxxx                             # restricted to "Mail Send"
# Optional antivirus on every upload; exit code 0 = clean. Leave out if not used.
# AppSettings.scanCommand = "clamdscan --no-summary {file}"
```

`encryption.key` protects the stored SharePoint and Microsoft secrets. It is included in the backups. Changing or losing it
means those secrets must be typed in again.

## Scheduled jobs (cron, as the web user)

```
*/5 * * * *  cd /var/www/app && php spark notifications:send      # retry failed emails
*/5 * * * *  cd /var/www/app && php spark sharepoint:sync         # send processed invoices to SharePoint
30 7 * * *   cd /var/www/app && php spark notifications:digest    # daily summary of outstanding invoices
0 * * * *    cd /var/www/app && php spark notifications:escalate  # reminders/escalations for steps past their time limit
15 3 * * *   cd /var/www/app && php spark files:purge             # delete old server copies of PDFs
30 1 * * *   cd /var/www/app && php spark analytics:load          # copy the day's activity into the analytics database
30 2 * * *   /var/www/app/scripts/backup.sh >> /var/log/invoicing-backup.log 2>&1
```

Check the purge first with `php spark files:purge --dry-run`.

### What `files:purge` will and will not delete

* **Finished invoices** (paid, or processed on a route with no pay step): only when SharePoint receipt is confirmed (`synced`, with the item or file link stored), and only
  once the retention period (Admin > Settings, default 30 days) has passed since the *later* of completion and confirmed sync.
* **Rejected or cancelled invoices**: after the retention period since they ended. If a rejected invoice is resubmitted later
  the submitter uploads a fresh PDF.
* Drafts, invoices in approval, returned or approved or processed-but-not-yet-paid invoices are never touched. Anything pending, failed
  or `not_configured` for SharePoint is never touched, however old.
* The invoice record, history, notes and SharePoint link are kept. Every deletion is written to the audit log.

## Long-term analytics

Managers and administrators get trend screens (Reports & Analytics, under the "Long-term analysis" tabs): volume and spend with year-on-year
comparison, cycle time and where invoices wait, and how the outstanding backlog moved over time. They read a **separate
database**, so reporting never competes with approvals and history can outlive the live data.

* **What it is:** a derived copy, rebuilt from the app database by `analytics:load` (nightly, incremental) and
  `analytics:backfill` (full rebuild). Nothing in the approval workflow reads it, and if it is down the analytics screens say so
  and the rest of the app is unaffected. It never receives note text, audit detail, files or SharePoint identifiers.
* **Set up** (once): a MySQL administrator creates the schema and grants the app user access, for example

  ```
  CREATE DATABASE invoicing_analytics CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
  GRANT ALL ON invoicing_analytics.* TO 'invoicing'@'localhost';
  ```

  then set `database.analytics.hostname/database/username/password/port` in `.env` (the same server and user are fine), and run
  `php spark analytics:migrate` to create the tables. The tests use their own schema (`database.analyticsTests.*`,
  e.g. `invoicing_test_analytics`), which is wiped like `invoicing_test`.
* **First load:** `php spark analytics:backfill --dry-run`, then `php spark analytics:backfill`. This rebuilds every invoice and
  *estimates* the backlog for past days from submit and finish dates (marked "est." on screen). From then on the nightly job
  records the real backlog each day.
* **Every upgrade:** run `php spark migrate --all` **and** `php spark analytics:migrate`. The analytics migrations are kept out
  of `migrate --all` on purpose, so the two databases never share migration history.
* **Backups:** `scripts/backup.sh` also dumps the analytics database to `analytics.sql.gz` when `database.analytics.database` is
  set. Restore it the same way as `database.sql.gz`. Back it up: the daily backlog snapshots cannot be recreated exactly.
* **Repairs:** after a fix to how facts are built, `php spark analytics:backfill` rebuilds them safely (it replaces facts and
  estimated snapshots, never recorded ones). `analytics:load` and `analytics:backfill` share one lock, so they never overlap.

## Running on more than one server

One server is the default and needs none of this. To put several app servers behind a load balancer, everything that
lives on a server's own disk has to move somewhere they share. Each item below is a separate `.env` choice; the defaults
keep today's single-server behaviour. (The workflow itself already lives entirely in MySQL.)

| What | Single server (default) | Several servers | `.env` |
|---|---|---|---|
| Sessions | files in `writable/session` | Redis (otherwise users are signed out when the balancer switches server) | `session.driver`, `session.savePath` |
| Cache and rate-limit counters | files | Redis (otherwise the 10/min sign-in and API limits become 10 per server) | `cache.handler = redis`, `cache.redis.*` |
| Invoice PDFs, logos, import staging | `writable/uploads` | S3-compatible bucket | `filestore.driver = s3`, `filestore.s3.*` |
| Client IP behind the balancer | the connecting address | trust the balancer's forwarded header | `app.trustedProxies`, `app.trustedProxyHeader` |
| Logs | `writable/logs` | PHP `error_log`, collected by your log agent | `logger.destination = errorlog` |

Requirements for Redis: the PHP `redis` extension on every server. For S3: a private bucket with server-side encryption on;
give the servers an IAM role (leave `filestore.s3.key`/`secret` unset) or a key limited to that bucket. Nothing is served
from the bucket directly; PDFs still go through the application's access check.

### Moving an existing install to the shared file store

1. Set `filestore.driver = s3` and the `filestore.s3.*` values in `.env` on one server.
2. `php spark files:migrate-to-store --dry-run`, then without `--dry-run`. It copies `writable/uploads` to the bucket and
   checks each copy by hash. It never deletes the local files, and it is safe to run again.
3. Put the same `.env` on every server and restart PHP-FPM. Keep the local copies and the backups until the new store has
   been in use for a while. (`files:purge` and `scripts/backup.sh` act on the file store in use: the backup script still
   archives only `writable/uploads`, so back up the bucket with the provider's tools once you switch.)

### Scheduled jobs

Install the same cron lines on **every** server. Each job takes a database lock, so if two servers start the same job at the
same moment one of them runs and the other exits with "already running". SharePoint sync and escalations also claim
individual rows, so a job that crashes part-way is picked up cleanly on the next run. The one exception is
`scripts/backup.sh`, which takes no lock: schedule it on one server only.

### Load balancer

* Health check: `GET /health` returns `200 {"status":"ok"}` when the server can reach the database and the file store,
  otherwise `503`. It needs no sign-in and gives no detail (the reason is logged).
* Terminate TLS at the balancer, forward `X-Forwarded-For` and `X-Forwarded-Proto`, and set `app.trustedProxies` to the
  balancer's addresses. Without it, sign-in throttling and the audit log see the balancer's address for everyone.
* Every server needs the same `.env`, in particular the same `encryption.key` (SharePoint and Microsoft secrets are encrypted
  with it).
* Updating: run `php spark migrate --all` and `php spark analytics:migrate` once (from one server), and deploy the new code to all servers together. Migrations
  are forward-only, so old code must not keep serving after a migration that changes a table it uses.

## Backups and restore

`scripts/backup.sh` writes a dated folder with `database.sql.gz`, `uploads.tar.gz` and `env.backup`, keeps 14 days by default
and fails loudly on an empty dump. Copy the backup directory off the server (object storage or another host); a backup on the
same disk is not a backup.

Restore (test this on a spare machine at least once):

```
gunzip -c database.sql.gz | mysql invoicing
tar -xzf uploads.tar.gz -C /var/www/app/writable
cp env.backup /var/www/app/.env        # restores encryption.key
chown -R www-data: /var/www/app/writable
```

## Updating

```
cd /var/www/app && git pull        # or unpack the new release
composer install --no-dev --optimize-autoloader
php spark migrate --all
php spark analytics:migrate
```

Take a backup first. Migrations are forward-only.

## Built-in protections (for the security review)

* All forms carry CSRF tokens; sign-in, emailed sign-in link, password change and Microsoft sign-in are limited to 10 submissions
  a minute per address (HTTP 429).
* Invoice PDFs are served only through the application after an access check, inline, `nosniff`, `no-store`; never from a public URL.
* Uploads must be real PDFs (extension, MIME type, `%PDF-` header, complete file). PDFs containing scripts, launch/submit/remote
  actions, or encryption are refused. An optional antivirus command can be enforced and fails closed.
* Secrets (SharePoint client secrets, Microsoft client secret) are encrypted at rest and never shown after saving.
* Invoice history, notes and the audit log are append-only in the application. Admin > Audit log is read-only.
* Group-level access is a single admin-only boundary; every action taken through it is recorded and reported.

## Not covered by this release

* Content-Security-Policy header (the pages use a few inline scripts today).
* Two-factor authentication for local accounts.
* Automatic off-server backup copy (configure with ITM's backup tooling).

## API

The read-only JSON API lives under `/api/v1` and authenticates with personal bearer tokens (Account menu > API tokens). Serve it only over https: tokens are credentials. Rate limiting is per client IP and token, so the web server or proxy must pass the real client address (see `App::$proxyIPs`). Details for integrators are in `docs/API.md`. No extra scheduled jobs or settings are needed.

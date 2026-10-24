# Deploying ITM Back Office (Linux, Apache, MySQL, Redis)

## Requirements

| Component | Version / notes |
|---|---|
| PHP | 8.2 or newer (developed and tested on 8.5) with `intl`, `mbstring`, `mysqli`, `curl`, `gd`, `openssl`, `fileinfo`, `redis` |
| Web server | Apache 2.4 with `mod_rewrite`, `mod_headers`, `mod_ssl`, and `proxy_fcgi` (PHP-FPM) |
| Database | MySQL 8.0+ (InnoDB, `utf8mb4`) |
| Cache / sessions | Redis 6+ |
| Build tools | Composer 2 on the server or CI. Node is **not** needed; Bootstrap is already in `public/assets/vendor`. |

## 1. Database

```sql
CREATE DATABASE itm_backoffice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'itm_app'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL PRIVILEGES ON itm_backoffice.* TO 'itm_app'@'localhost';
```

Migrations need DDL rights. After a release is migrated you may drop the runtime user to
`SELECT, INSERT, UPDATE, DELETE` and use a separate deploy user for migrations.

The application uses no table prefix; do not set one.

## 2. Code and configuration

```bash
sudo mkdir -p /var/www/itm-backoffice && cd /var/www/itm-backoffice
# copy the release here, then:
composer install --no-dev --optimize-autoloader

cp .env.production.example .env      # edit every value
php spark key:generate               # writes encryption.key into .env

# Only these paths are writable by the web user:
sudo chown -R www-data:www-data writable
sudo chmod -R u=rwX,g=rX,o= writable
sudo chmod 640 .env && sudo chown root:www-data .env
```

## 3. First install

```bash
php spark migrate --all
php spark app:install --email admin@yourcompany.com --first Jane --last Admin --password 'a-long-temporary-password'
```

`app:install` seeds the roles and permissions and creates the first Super Admin. That
account is forced to choose a new password at first sign-in.

Re-run `php spark db:seed RbacSeeder` after any release that adds modules or permissions
(it is idempotent and never removes permissions you added to a role).

## 4. Apache virtual host

`DocumentRoot` **must** be the `public/` directory so that `.env`, `app/`, `vendor/` and
`writable/` are never reachable over HTTP.

```apache
<VirtualHost *:80>
    ServerName backoffice.example.com
    Redirect permanent / https://backoffice.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName backoffice.example.com
    DocumentRoot /var/www/itm-backoffice/public

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/backoffice.crt
    SSLCertificateKeyFile /etc/ssl/private/backoffice.key

    <Directory /var/www/itm-backoffice/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All          # uses public/.htaccess for URL rewriting
        Require all granted
    </Directory>

    # Never serve dotfiles or composer metadata even if the docroot is misconfigured.
    <FilesMatch "^\.(?!well-known)|composer\.(json|lock)$">
        Require all denied
    </FilesMatch>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # The application sets CSP, X-Frame-Options, X-Content-Type-Options and
    # Referrer-Policy itself. HSTS is sent by CodeIgniter when HTTPS is forced.

    ErrorLog  ${APACHE_LOG_DIR}/itm-backoffice-error.log
    CustomLog ${APACHE_LOG_DIR}/itm-backoffice-access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite headers ssl proxy_fcgi setenvif
sudo a2enconf php8.3-fpm
sudo systemctl reload apache2
```

Recommended PHP settings (`php.ini` for FPM): `expose_php=Off`, `display_errors=Off`,
`opcache.enable=1`, `upload_max_filesize=6M`, `post_max_size=8M`, `session.cookie_secure=1`.

## 5. Scheduled work (cron)

Every job below reports its own start and end, and **Admin > Cron jobs** (administrators only) shows whether each one is running on time, how its last run ended, and the crontab lines to install. Add the `cron:check` line as well: it alerts administrators (in the app and by email) when a job fails, is late or hangs, and prunes run history older than 90 days. The app cannot see the server's crontab, so it judges a job by whether it has reported in; if cron stops completely the page shows a warning once the hourly check goes missing, but no alert can be sent.

```cron
5 * * * * www-data cd /var/www/itm-backoffice && php spark cron:check >> writable/logs/cron-check.log 2>&1
```

Entra directory sync (only does anything once Entra is enabled and configured; it exits
cleanly otherwise). Run as the web user:

```cron
# /etc/cron.d/itm-backoffice
0 */4 * * * www-data cd /var/www/itm-backoffice && php spark entra:sync >> writable/logs/entra-sync.log 2>&1
```

Overdue customer invoice reminders (Receivables). It exits cleanly while the module is switched off, and is safe to
run more than once a day: each invoice is reminded the day after its due date and then every
`ReceivablesSettings.remindEveryDays` days (default 7) until it is paid. "Today" is each invoice's own company calendar,
so one run per day, late enough that every company's date has rolled over, is enough. Add `--dry-run` to see what would be sent.

```cron
30 6 * * * www-data cd /var/www/itm-backoffice && php spark receivables:remind >> writable/logs/receivables-remind.log 2>&1
```

Customer subscriptions (Receivables). `receivables:subscriptions` raises a draft customer invoice for every active
subscription whose bill date has arrived, and notifies the owner and collectors ahead of time (days set by
`ReceivablesSettings.subscriptionLeadDays`, default `14,7,1`). It exits cleanly while the module is switched off, takes a
lock, and is safe to run more than once a day: a cycle is billed once and a notice sent once. Run it once a day, after
`receivables:remind`. Add `--dry-run` to see what would happen.

```cron
45 6 * * * www-data cd /var/www/itm-backoffice && php spark receivables:subscriptions >> writable/logs/receivables-subscriptions.log 2>&1
```

Database backups. `backup:run` dumps the database, encrypts it and sends it to every destination switched on in
**Admin > Backups**; it takes a lock, so overlapping runs are impossible, and it exits cleanly if nothing is set up.
`backup:check` warns the backup managers when no good backup has been made within `backups.staleHours` (default 26),
which also catches cron itself stopping. See section 8 for setup.

```cron
0 2 * * * www-data cd /var/www/itm-backoffice && php spark backup:run >> writable/logs/backup.log 2>&1
15 * * * * www-data cd /var/www/itm-backoffice && php spark backup:check >> writable/logs/backup.log 2>&1
```

There is no queue worker: notification emails are sent inline. If a worker is added later,
Redis is already available for it.

Housekeeping you may want (not scheduled by the app):
- Rotate `writable/logs/` (logrotate).
- Prune old rows from `audit_log`, `notifications` and `auth_logins` according to your retention policy.

## 6. Enabling Microsoft Entra

The feature is optional. See **Admin → Microsoft Entra** in the app for the exact steps and
your redirect URI. In short: register the app (single tenant), add the redirect URI, grant
`User.Read.All` and `GroupMember.Read.All` (application, admin consent), create a secret,
put the three values in `.env`, set `entra.enabled = true`, then **Test connection**.

Notes:
- The client secret lives only in `.env`. Calendar a reminder before it expires; sign-in and
  sync fail once it does.
- `entra.linkExistingByEmail = true` lets a first Microsoft sign-in take over an existing
  *unlinked* local account with the same email. Turn it off if you do not trust the email
  addresses in your tenant.

## 7. Multi-factor authentication (MFA)

Off by default. An administrator with the `mfa.manage` permission (Super Admin by default)
controls it at **Admin > Multi-factor auth**: a master on/off switch, which of the three methods
are offered, which roles are **required** to use it, and how many days a browser may be
remembered (0 = always ask).

| Method | Needs |
|---|---|
| Authenticator app (TOTP) | `encryption.key` in `.env` (`php spark key:generate`) |
| Email code | Outbound email configured (`email.fromEmail`, SMTP) |
| Duo (Universal Prompt) | `duo.clientId`, `duo.clientSecret`, `duo.apiHost` in `.env`, plus the redirect URL shown on the admin page registered in Duo |

- A method that cannot work yet is shown greyed out with the reason and cannot be switched on.
- **Back up `encryption.key`.** Authenticator secrets are encrypted with it; if it is lost or
  changed, every enrolled authenticator becomes unreadable and users must be reset and re-enrol.
  Recovery codes and email codes are keyed hashes derived from the same key.
- People in a required role who have no method are made to set one up at their next sign-in.
  Everyone else may opt in from **Profile > Two-step verification**; once they do, they are asked
  at every sign-in. Each user gets 10 one-time **recovery codes** (shown once).
- A user who loses their device is reset by an administrator from the user's page
  (**Reset two-step verification**). Only a Super Admin can reset a Super Admin.
- **Microsoft Entra sign-ins are not challenged** by the app; Entra enforces its own MFA.
- An admin whose own role would become required cannot save the setting until they have enrolled
  a method themselves (so they know it works). If MFA is on but no method is currently available,
  nobody is challenged rather than everyone being locked out.
- Safeguards: codes are single use; TOTP time steps cannot be replayed; 5 wrong codes end the
  pending sign-in and 10 failures lock the account out of MFA for 15 minutes; remembered browsers
  are signed out when a password is changed or reset, MFA is reset, or the rules are tightened.
- Shield's own "remember me" is disabled on purpose; it would let a password thief obtain a
  cookie that skips MFA.
- Duo fails **closed**: if Duo cannot be reached the user is not let through, so keep another
  method (or an administrator who can reset) available.

## 8. Database backups

Admins and backup managers see status at **Admin > Backups**; managers also add destinations and press **Run now**.

Setup:
1. Install the MySQL client tools so `mysqldump` exists on the server (`apt install mysql-client`), or set
   `backups.mysqldumpPath` in `.env`. The database user needs `SELECT`, `SHOW VIEW`, `TRIGGER`, `EVENT`, `LOCK TABLES`
   (and `PROCESS` is not required; `--no-tablespaces` is used).
2. Generate a long random passphrase (`openssl rand -base64 32`), put it in `.env` as `backups.passphrase`, and **store a
   copy off the server** (password manager, safe). It is separate from `encryption.key`. A backup cannot be restored
   without it, and it is not stored anywhere in the app.
3. Make sure `writable/backups/` is writable by the cron user (or set `backups.workDir`). The newest `backups.localKeep`
   encrypted copies stay there.
4. Add destinations in the app and press **Test** on each. Types: SharePoint / OneDrive (Microsoft Graph: its own app
   registration with the application permission `Files.ReadWrite.All`, or `Sites.Selected` for one site, admin consent
   granted), S3-compatible bucket (give the key write access to one bucket only; turn on versioning or object lock),
   WebDAV, and a folder or mounted share.
5. Add the cron lines from section 5.

Notes:
- Destination passwords and keys are stored encrypted with `encryption.key`. **Back up `encryption.key` too** (see
  section 7): restoring the database onto a new server without it leaves destinations unreadable, and they must be
  re-entered.
- Files are named `itm-backup-YYYYMMDD-HHMMSS.sql.gz.enc`. Only files with that name pattern are ever pruned at a
  destination, so it is safe to share a folder, but give it its own folder anyway.
- A run is **Success** (every destination received it), **Partly sent**, or **Failed**. Failures and partial runs notify
  everyone with `backups.manage` in the app, and by email.
- `php spark backup:run --dry-run` checks the setup without making a backup. `--destination ID` sends to one destination.

Restoring:

```bash
php spark backup:decrypt /path/itm-backup-20261023-020000.sql.gz.enc /tmp/restore.sql.gz   # asks for the passphrase if it is not in .env
gunzip -c /tmp/restore.sql.gz | mysql -u USER -p DATABASE
```

Practise a restore into a scratch database before you need one. Remove the decrypted file afterwards.

## 9. Upgrading

```bash
cd /var/www/itm-backoffice
# deploy new code, then:
composer install --no-dev --optimize-autoloader
php spark migrate --all
php spark db:seed RbacSeeder
php spark cache:clear
sudo systemctl reload php8.3-fpm
```

Take a database backup first (`mysqldump --single-transaction itm_backoffice > backup.sql`).

## 10. Operational notes and known limits

- **Passwords:** administrators create users and issue one-time passwords (users must change
  them at first sign-in). Users can reset a forgotten password themselves from the sign-in page
  once email is configured (`email.fromEmail` and SMTP settings): they are emailed a single-use
  link that expires after 60 minutes (`Config\Backoffice::$passwordResetTtlMinutes`), at most 3
  per account per hour. Until email is configured the page tells users to ask an administrator,
  who can still issue a new one-time password from the user's page. Accounts managed by
  Microsoft Entra, and inactive or suspended accounts, cannot use it.
- **Behind a reverse proxy / load balancer:** if TLS ends at the proxy, add the proxy's IP to
  `$proxyIPs` in `app/Config/App.php` (for example `['10.0.0.5' => 'X-Forwarded-For']`).
  Without it the app does not trust `X-Forwarded-Proto`, sees plain HTTP, and redirects to
  HTTPS forever. The same setting makes the sign-in throttle (10 submissions per minute per IP)
  use the real client IP instead of the proxy's.
- **Sessions:** 2 hours of inactivity. Redis sessions survive PHP-FPM restarts.
- A remote "sign out other sessions" list is not implemented. See section 7 for MFA.
- **Email** is skipped (and logged) until `email.fromEmail` is configured. For local development
  without SMTP set `backoffice.mailLogOnly = true` in `.env`: messages, including reset links,
  are written to `writable/logs/mail.log`. It is ignored when `CI_ENVIRONMENT = production`.
- **Password reset timing:** the lookup and email run after the HTTP response is sent (using
  `fastcgi_finish_request()` under PHP-FPM), so response time does not reveal whether an address
  has an account.
- **Content-Security-Policy** is enforced. Any new page must avoid inline `<script>`, inline
  event handlers (`onclick=`) and `style=` attributes; use files under `public/assets/js`.

## 11. Verifying a deployment

1. `https://your-host/` redirects to the sign-in page; `http://` redirects to `https://`.
2. `https://your-host/.env`, `/app/Config/App.php` and `/writable/` return 403/404.
3. Sign in as the Super Admin and change the password when prompted.
4. Response headers include `Content-Security-Policy`, `Strict-Transport-Security`,
   `X-Frame-Options` and `X-Content-Type-Options`.
5. `php spark routes` runs without errors; `php spark entra:sync` prints "not enabled" (or syncs).

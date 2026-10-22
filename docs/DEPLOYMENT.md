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

Entra directory sync (only does anything once Entra is enabled and configured; it exits
cleanly otherwise). Run as the web user:

```cron
# /etc/cron.d/itm-backoffice
0 */4 * * * www-data cd /var/www/itm-backoffice && php spark entra:sync >> writable/logs/entra-sync.log 2>&1
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

## 8. Upgrading

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

## 9. Operational notes and known limits

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

## 10. Verifying a deployment

1. `https://your-host/` redirects to the sign-in page; `http://` redirects to `https://`.
2. `https://your-host/.env`, `/app/Config/App.php` and `/writable/` return 403/404.
3. Sign in as the Super Admin and change the password when prompted.
4. Response headers include `Content-Security-Policy`, `Strict-Transport-Security`,
   `X-Frame-Options` and `X-Content-Type-Options`.
5. `php spark routes` runs without errors; `php spark entra:sync` prints "not enabled" (or syncs).

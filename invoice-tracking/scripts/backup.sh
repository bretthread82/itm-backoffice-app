#!/usr/bin/env bash
# Nightly backup: database dump (+ analytics database when configured) + uploaded invoice PDFs + .env, then prune old backups.
#   30 2 * * *  /var/www/app/scripts/backup.sh >> /var/log/invoicing-backup.log 2>&1
# Settings can be overridden from the environment: APP_DIR, BACKUP_DIR, KEEP_DAYS.
set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-/var/www/app}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/invoicing}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"

env_value() { grep -E "^[[:space:]]*$1[[:space:]]*=" "$APP_DIR/.env" | tail -n1 | sed -E 's/^[^=]*=[[:space:]]*//; s/^["'\'']//; s/["'\'']$//'; }

DB_HOST="$(env_value database.default.hostname)"
DB_NAME="$(env_value database.default.database)"
DB_USER="$(env_value database.default.username)"
DB_PASS="$(env_value database.default.password)"

mkdir -p "$BACKUP_DIR"
OUT="$BACKUP_DIR/$STAMP"
mkdir -p "$OUT"

# Password goes through the environment so it never appears in the process list.
MYSQL_PWD="$DB_PASS" mysqldump --host="${DB_HOST:-localhost}" --user="$DB_USER" \
    --single-transaction --routines --no-tablespaces "$DB_NAME" | gzip > "$OUT/database.sql.gz"

# Long-term analytics database (a derived copy, but history older than the app data is not rebuildable, so keep it).
A_NAME="$(env_value database.analytics.database)"
if [ -n "$A_NAME" ]; then
    A_HOST="$(env_value database.analytics.hostname)"
    A_USER="$(env_value database.analytics.username)"
    A_PASS="$(env_value database.analytics.password)"
    MYSQL_PWD="$A_PASS" mysqldump --host="${A_HOST:-localhost}" --user="$A_USER" \
        --single-transaction --no-tablespaces "$A_NAME" | gzip > "$OUT/analytics.sql.gz"
    test -s "$OUT/analytics.sql.gz"
fi

# Invoice PDFs still held on the server (older ones live in SharePoint once purged).
if [ -d "$APP_DIR/writable/uploads" ]; then
    tar -czf "$OUT/uploads.tar.gz" -C "$APP_DIR/writable" uploads
fi

# .env holds encryption.key. Without it, saved SharePoint/Microsoft secrets cannot be decrypted after a restore.
cp "$APP_DIR/.env" "$OUT/env.backup"

# Refuse to call an empty dump a success.
test -s "$OUT/database.sql.gz"

find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime "+$KEEP_DAYS" -exec rm -rf {} +
echo "$(date -Is) backup ok: $OUT"

#!/bin/bash
# backup_one_db.sh — бэкап одной БД по требованию
set -euo pipefail
DB="$1"
if [[ ! "$DB" =~ ^[a-zA-Z0-9_]{1,64}$ ]]; then echo "ERROR: invalid db name"; exit 1; fi
BACKUP_DIR="/opt/backups/databases"
MYSQL_CONF="/root/.adelpanel/mysql.conf"
DATE=$(date +%Y-%m-%d_%H-%M)
mkdir -p "$BACKUP_DIR"
ARCHIVE="${BACKUP_DIR}/${DB}_${DATE}.sql.gz"
mysqldump --defaults-file="$MYSQL_CONF" --single-transaction --routines --triggers "$DB" \
    | gzip > "$ARCHIVE"
echo "OK: $ARCHIVE ($(du -sh "$ARCHIVE" | cut -f1))"

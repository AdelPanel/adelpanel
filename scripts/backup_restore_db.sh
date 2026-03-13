#!/bin/bash
# backup_restore_db.sh — восстановить БД из бэкапа
set -euo pipefail
ARCHIVE="$1"  # .sql.gz
DB_NAME="$2"

if [[ ! "$ARCHIVE" =~ ^/opt/backups/ ]]; then
    echo "ERROR: archive must be in /opt/backups/"; exit 1
fi
if [[ ! "$DB_NAME" =~ ^[a-zA-Z0-9_]{1,64}$ ]]; then
    echo "ERROR: invalid db name"; exit 1
fi
[ ! -f "$ARCHIVE" ] && echo "ERROR: file not found" && exit 1

MYSQL_CONF="/root/.adelpanel/mysql.conf"
mysql --defaults-file="$MYSQL_CONF" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
gunzip -c "$ARCHIVE" | mysql --defaults-file="$MYSQL_CONF" "$DB_NAME"
echo "OK: database $DB_NAME restored from $ARCHIVE"

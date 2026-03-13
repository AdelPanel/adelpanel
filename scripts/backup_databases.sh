#!/bin/bash
# backup_databases.sh — ежедневный бэкап каждой БД отдельно
set -euo pipefail

BACKUP_DIR="/opt/backups/databases"
MYSQL_CONF="/root/.adelpanel/mysql.conf"
DATE=$(date +%Y-%m-%d_%H-%M)

KEEP_DAYS=$(php -r "
    \$cfg = '/opt/adelpanel/config/backup_settings.json';
    if (file_exists(\$cfg)) {
        \$d = json_decode(file_get_contents(\$cfg), true);
        echo \$d['db_keep_days'] ?? 7;
    } else { echo 7; }
" 2>/dev/null || echo 7)

mkdir -p "$BACKUP_DIR"
echo "[$(date)] DB backups started (keep ${KEEP_DAYS}d)"

DATABASES=$(mysql --defaults-file="$MYSQL_CONF" -N -e "SHOW DATABASES;" 2>/dev/null \
    | grep -Ev "^(information_schema|performance_schema|mysql|sys|adelpanel)$")

for DB in $DATABASES; do
    ARCHIVE="${BACKUP_DIR}/${DB}_${DATE}.sql.gz"
    mysqldump --defaults-file="$MYSQL_CONF" \
        --single-transaction --routines --triggers "$DB" 2>/dev/null \
        | gzip > "$ARCHIVE" \
        && echo "[$(date)] OK $DB → $ARCHIVE ($(du -sh "$ARCHIVE" | cut -f1))" \
        || echo "[$(date)] ERROR $DB"
done

find "$BACKUP_DIR" -name "*.sql.gz" -mtime +"$KEEP_DAYS" -delete
echo "[$(date)] DB backups done"

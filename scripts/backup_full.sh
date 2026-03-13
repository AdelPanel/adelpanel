#!/bin/bash
# backup_full.sh — полный бэкап (XtraBackup / mariadb-backup / mysqldump)
# Автоматически определяет тип СУБД и использует подходящий инструмент
set -euo pipefail

BACKUP_DIR="/opt/backups/full"
MYSQL_CONF="/root/.adelpanel/mysql.conf"
DATE=$(date +%Y-%m-%d_%H-%M)
DEST="${BACKUP_DIR}/${DATE}"

# Читаем период хранения из конфига панели (дни), по умолчанию 7
KEEP_DAYS=$(php -r "
    define('ADELPANEL_ROOT', '/opt/adelpanel');
    \$cfg = '/opt/adelpanel/config/backup_settings.json';
    if (file_exists(\$cfg)) {
        \$d = json_decode(file_get_contents(\$cfg), true);
        echo \$d['full_keep_days'] ?? 7;
    } else { echo 7; }
" 2>/dev/null || echo 7)

mkdir -p "$DEST/mysql"
echo "[$(date)] Full backup started → $DEST (keep ${KEEP_DAYS}d)"

# ── Определяем тип СУБД ──────────────────────────────────────────
detect_db_type() {
    # Проверяем через mysqld --version
    local ver
    ver=$(mysqld --version 2>/dev/null || mysqld_safe --version 2>/dev/null || echo "")
    if echo "$ver" | grep -qi "mariadb"; then
        echo "mariadb"
    else
        echo "mysql"
    fi
}

DB_TYPE=$(detect_db_type)
echo "[$(date)] Detected DB: ${DB_TYPE}"

# ── Бэкап БД ─────────────────────────────────────────────────────
backup_done=0

if [ "$DB_TYPE" = "mariadb" ] && command -v mariadb-backup &>/dev/null; then
    # mariadb-backup для MariaDB
    mariadb-backup --backup \
        --defaults-file="$MYSQL_CONF" \
        --target-dir="${DEST}/mysql" 2>/dev/null \
        && mariadb-backup --prepare \
            --target-dir="${DEST}/mysql" 2>/dev/null \
        && echo "[$(date)] OK mariadb-backup → ${DEST}/mysql" \
        && backup_done=1 \
        || echo "[$(date)] WARN mariadb-backup failed"

elif [ "$DB_TYPE" = "mysql" ] && command -v xtrabackup &>/dev/null; then
    # XtraBackup для Percona/MySQL
    MYSQL_PASS=$(grep -oP '(?<=password=)\S+' "$MYSQL_CONF" 2>/dev/null || \
                 grep password "$MYSQL_CONF" | awk -F= '{print $2}' | tr -d ' ')
    xtrabackup --backup \
        --user=root --password="$MYSQL_PASS" \
        --target-dir="${DEST}/mysql" \
        --compress --compress-threads=2 2>/dev/null \
        && echo "[$(date)] OK XtraBackup → ${DEST}/mysql" \
        && backup_done=1 \
        || echo "[$(date)] WARN XtraBackup failed"
fi

# Fallback на mysqldump если специализированный инструмент недоступен или упал
if [ "$backup_done" -eq 0 ]; then
    mysqldump --defaults-file="$MYSQL_CONF" \
        --all-databases --single-transaction --routines --triggers \
        2>/dev/null | gzip > "${DEST}/mysql/all_databases.sql.gz"
    echo "[$(date)] OK mysqldump fallback → ${DEST}/mysql/all_databases.sql.gz"
fi

# ── Архив всех сайтов ─────────────────────────────────────────────
tar -czf "${DEST}/sites_all.tar.gz" -C / var/www 2>/dev/null \
    && echo "[$(date)] OK sites → ${DEST}/sites_all.tar.gz" \
    || echo "[$(date)] ERROR archiving sites"

# ── Манифест ──────────────────────────────────────────────────────
{
    echo "AdelPanel Full Backup"
    echo "Date:    $DATE"
    echo "DB type: $DB_TYPE"
    echo ""
    echo "--- MySQL/DB ---"
    ls -lh "${DEST}/mysql/" 2>/dev/null || echo "(empty)"
    echo ""
    echo "--- Sites ---"
    ls -lh "${DEST}/sites_all.tar.gz" 2>/dev/null || echo "(not found)"
    echo ""
    echo "Total size: $(du -sh "$DEST" | cut -f1)"
} > "${DEST}/MANIFEST.txt"

# ── Очистка старых бэкапов ────────────────────────────────────────
find "$BACKUP_DIR" -maxdepth 1 -mindepth 1 -type d -mtime +"$KEEP_DAYS" \
    -exec rm -rf {} \; 2>/dev/null || true
echo "[$(date)] Cleanup: removed full backups older than ${KEEP_DAYS} days"

echo "[$(date)] Full backup DONE → $DEST"

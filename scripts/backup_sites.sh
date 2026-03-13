#!/bin/bash
# backup_sites.sh — ежедневный бэкап каждого сайта отдельно
set -euo pipefail

BACKUP_DIR="/opt/backups/sites"
WEBROOT="/var/www"
DATE=$(date +%Y-%m-%d_%H-%M)

# Читаем период хранения из конфига панели
KEEP_DAYS=$(php -r "
    \$cfg = '/opt/adelpanel/config/backup_settings.json';
    if (file_exists(\$cfg)) {
        \$d = json_decode(file_get_contents(\$cfg), true);
        echo \$d['sites_keep_days'] ?? 7;
    } else { echo 7; }
" 2>/dev/null || echo 7)

mkdir -p "$BACKUP_DIR"
echo "[$(date)] Site backups started (keep ${KEEP_DAYS}d)"

for SITE_DIR in "$WEBROOT"/*/; do
    [ -d "$SITE_DIR" ] || continue
    DOMAIN=$(basename "$SITE_DIR")
    ARCHIVE="${BACKUP_DIR}/${DOMAIN}_${DATE}.tar.gz"
    tar -czf "$ARCHIVE" -C "$WEBROOT" "$DOMAIN" 2>/dev/null \
        && echo "[$(date)] OK $DOMAIN → $ARCHIVE ($(du -sh "$ARCHIVE" | cut -f1))" \
        || echo "[$(date)] ERROR $DOMAIN"
done

find "$BACKUP_DIR" -name "*.tar.gz" -mtime +"$KEEP_DAYS" -delete
echo "[$(date)] Site backups done, removed files older than ${KEEP_DAYS}d"

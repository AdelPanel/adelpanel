#!/bin/bash
# backup_restore_site.sh — восстановить сайт из бэкапа
set -euo pipefail
ARCHIVE="$1"   # полный путь к .tar.gz
DOMAIN="$2"    # имя домена

if [[ ! "$ARCHIVE" =~ ^/opt/backups/sites/ ]]; then
    echo "ERROR: archive must be in /opt/backups/sites/"; exit 1
fi
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+$ ]]; then
    echo "ERROR: invalid domain"; exit 1
fi
[ ! -f "$ARCHIVE" ] && echo "ERROR: file not found: $ARCHIVE" && exit 1

WEBROOT="/var/www"
DEST="${WEBROOT}/${DOMAIN}"

# Бэкап текущей версии перед восстановлением
STAMP=$(date +%Y%m%d_%H%M%S)
[ -d "$DEST" ] && mv "$DEST" "${DEST}.before_restore_${STAMP}"

tar -xzf "$ARCHIVE" -C "$WEBROOT"
chown -R www-data:www-data "$DEST"
echo "OK: site $DOMAIN restored from $ARCHIVE"

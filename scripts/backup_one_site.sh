#!/bin/bash
# backup_one_site.sh — бэкап одного сайта по требованию
set -euo pipefail
DOMAIN="$1"
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+$ ]]; then echo "ERROR: invalid domain"; exit 1; fi
BACKUP_DIR="/opt/backups/sites"
WEBROOT="/var/www"
DATE=$(date +%Y-%m-%d_%H-%M)
mkdir -p "$BACKUP_DIR"
ARCHIVE="${BACKUP_DIR}/${DOMAIN}_${DATE}.tar.gz"
[ ! -d "${WEBROOT}/${DOMAIN}" ] && echo "ERROR: ${WEBROOT}/${DOMAIN} not found" && exit 1
tar -czf "$ARCHIVE" -C "$WEBROOT" "$DOMAIN"
echo "OK: $ARCHIVE ($(du -sh "$ARCHIVE" | cut -f1))"

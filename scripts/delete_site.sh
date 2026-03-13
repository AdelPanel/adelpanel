#!/bin/bash
# AdelPanel — scripts/delete_site.sh
set -euo pipefail

DOMAIN="$1"
DELETE_FILES="${2:-0}"

if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$ ]]; then
    echo "ERROR: invalid domain"; exit 1
fi

# Удаляем из conf.d (основное) и sites-available/enabled (для совместимости)
rm -f "/etc/nginx/conf.d/${DOMAIN}.conf"
rm -f "/etc/nginx/sites-enabled/${DOMAIN}.conf"
rm -f "/etc/nginx/sites-available/${DOMAIN}.conf"

nginx -t 2>/dev/null && nginx -s reload

if [ "$DELETE_FILES" = "1" ]; then
    # Безопасность: только /var/www/
    WEBROOT="/var/www/$DOMAIN"
    if [ -d "$WEBROOT" ]; then
        rm -rf "$WEBROOT"
        echo "INFO: Deleted $WEBROOT"
    fi
fi

echo "OK: site $DOMAIN deleted"

#!/bin/bash
set -euo pipefail
DOMAIN="$1"
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+$ ]]; then echo "ERROR: invalid domain"; exit 1; fi
CONF="/etc/nginx/sites-available/${DOMAIN}.conf"
[ ! -f "$CONF" ] && echo "ERROR: config not found" && exit 1
sed -i '/auth_basic/d' "$CONF"
rm -f "/etc/nginx/.htpasswd_${DOMAIN}"
nginx -t && nginx -s reload
echo "OK: HTTP Auth disabled for $DOMAIN"

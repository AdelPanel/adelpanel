#!/bin/bash
set -euo pipefail
DOMAIN="$1"
ACME="/root/.acme.sh/acme.sh"
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$ ]]; then
    echo "ERROR: invalid domain"; exit 1
fi
"$ACME" --revoke -d "${DOMAIN}" 2>&1 || true
"$ACME" --remove -d "${DOMAIN}" 2>&1 || true
# Убираем SSL из nginx конфига
CONF="/etc/nginx/sites-available/${DOMAIN}.conf"
if [ -f "$CONF" ]; then
    sed -i '/listen 443/d;/ssl_certificate/d;/ssl_protocols/d;/ssl_ciphers/d;/ssl_prefer/d;/Strict-Transport/d' "$CONF"
    nginx -t 2>/dev/null && nginx -s reload
fi
echo "OK: SSL revoked for ${DOMAIN}"

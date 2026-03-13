#!/bin/bash
# ssl_selfsigned.sh — самоподписанный сертификат
set -euo pipefail
DOMAIN="$1"
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+$ ]]; then echo "ERROR: invalid domain"; exit 1; fi
SSL_DIR="/etc/nginx/ssl/${DOMAIN}"
mkdir -p "$SSL_DIR"
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "${SSL_DIR}/privkey.pem" \
    -out    "${SSL_DIR}/fullchain.pem" \
    -subj "/CN=${DOMAIN}/O=AdelPanel/C=RU" 2>/dev/null

CONF="/etc/nginx/sites-available/${DOMAIN}.conf"
if [ -f "$CONF" ] && ! grep -q "ssl_certificate" "$CONF"; then
    sed -i "s|listen 80;|listen 80;\n    listen 443 ssl;\n    ssl_certificate ${SSL_DIR}/fullchain.pem;\n    ssl_certificate_key ${SSL_DIR}/privkey.pem;|" "$CONF"
fi
nginx -t && nginx -s reload
echo "OK: self-signed SSL for $DOMAIN (valid 365d)"

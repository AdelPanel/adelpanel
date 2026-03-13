#!/bin/bash
# httpauth_enable.sh
set -euo pipefail
DOMAIN="$1"; USER="$2"; PASS="$3"; REALM="${4:-Protected Area}"

if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+$ ]]; then echo "ERROR: invalid domain"; exit 1; fi
if [[ ! "$USER"   =~ ^[a-zA-Z0-9_-]{1,32}$ ]];         then echo "ERROR: invalid user";   exit 1; fi

HTPASSWD_FILE="/etc/nginx/.htpasswd_${DOMAIN}"
CONF="/etc/nginx/sites-available/${DOMAIN}.conf"

# Создаём .htpasswd
htpasswd -bc "$HTPASSWD_FILE" "$USER" "$PASS"

# Вставляем auth_basic в server блок если ещё нет
if ! grep -q "auth_basic" "$CONF"; then
    sed -i "s|location / {|auth_basic \"${REALM}\";\n    auth_basic_user_file ${HTPASSWD_FILE};\n\n    location / {|" "$CONF"
fi

nginx -t && nginx -s reload
echo "OK: HTTP Auth enabled for $DOMAIN"

#!/bin/bash
# set_panel_port.sh
set -euo pipefail
PORT="$1"
if [[ ! "$PORT" =~ ^[0-9]+$ ]] || [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    echo "ERROR: invalid port (1024-65535)"; exit 1
fi
CONF="/etc/nginx/sites-available/adelpanel.conf"
sed -i "s|listen [0-9]*;|listen ${PORT};|" "$CONF"

# UFW: добавляем новый порт, удаляем старый
OLD_PORT=$(ufw status | grep -oP '\d+(?=/tcp.*AdelPanel)' | head -1)
ufw allow "${PORT}/tcp" comment 'AdelPanel' 2>/dev/null
[ -n "$OLD_PORT" ] && [ "$OLD_PORT" != "$PORT" ] && \
    ufw delete allow "${OLD_PORT}/tcp" 2>/dev/null || true
ufw reload 2>/dev/null

nginx -t && nginx -s reload
echo "OK: panel port → $PORT"

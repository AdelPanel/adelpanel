#!/bin/bash
# ssl_renew_all.sh — cron: обновление всех сертификатов
set -euo pipefail
ACME="$HOME/.acme.sh/acme.sh"
[ ! -f "$ACME" ] && exit 0
"$ACME" --cron --home "$HOME/.acme.sh" --log /var/log/adelpanel-acme.log 2>&1
nginx -s reload 2>/dev/null || true
echo "[$(date)] SSL renew-all done"

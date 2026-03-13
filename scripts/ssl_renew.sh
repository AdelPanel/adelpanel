#!/bin/bash
set -euo pipefail
DOMAIN="$1"
ACME="/root/.acme.sh/acme.sh"
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$ ]]; then
    echo "ERROR: invalid domain"; exit 1
fi
"$ACME" --renew -d "${DOMAIN}" --force 2>&1 \
    && echo "OK: SSL renewed for ${DOMAIN}" \
    || { echo "ERROR: renewal failed"; exit 1; }

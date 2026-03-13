#!/bin/bash
# set_hostname.sh
set -euo pipefail
HOSTNAME="$1"
if [[ ! "$HOSTNAME" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,62}$ ]]; then echo "ERROR: invalid hostname"; exit 1; fi
hostnamectl set-hostname "$HOSTNAME"
echo "OK: hostname set to $HOSTNAME"

#!/bin/bash
# ftp_change_pass.sh
set -euo pipefail
USER="$1"; PASS="$2"
if [[ ! "$USER" =~ ^[a-zA-Z0-9_-]{1,32}$ ]]; then echo "ERROR: invalid user"; exit 1; fi
echo "$PASS" | pure-pw passwd "$USER" -m
echo "OK: password changed for $USER"

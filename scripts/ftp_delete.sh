#!/bin/bash
# ftp_delete.sh
set -euo pipefail
USER="$1"
if [[ ! "$USER" =~ ^[a-zA-Z0-9_-]{1,32}$ ]]; then echo "ERROR: invalid user"; exit 1; fi
pure-pw userdel "$USER" -m
userdel "$USER" 2>/dev/null || true
echo "OK: FTP user $USER deleted"

#!/bin/bash
set -euo pipefail
TZ="$1"
timedatectl set-timezone "$TZ"
echo "OK: timezone → $TZ"

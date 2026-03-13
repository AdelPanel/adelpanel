#!/bin/bash
# set_php_version.sh — переключить дефолтный PHP
set -euo pipefail
VER="$1"
if [[ ! "$VER" =~ ^[78]\.[0-9]$ ]]; then echo "ERROR: invalid PHP version"; exit 1; fi
update-alternatives --set php "/usr/bin/php${VER}" 2>/dev/null || true
echo "OK: default PHP → ${VER}"

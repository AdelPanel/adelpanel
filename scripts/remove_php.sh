#!/bin/bash
set -euo pipefail
VER="$1"
if [[ ! "$VER" =~ ^[78]\.[0-9]$ ]]; then echo "ERROR: invalid PHP version"; exit 1; fi
systemctl stop "php${VER}-fpm" 2>/dev/null || true
apt-get purge -y -qq "php${VER}*" 2>/dev/null
apt-get autoremove -y -qq 2>/dev/null
echo "OK: PHP ${VER} removed"

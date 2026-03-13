#!/bin/bash
# install_php.sh — установить произвольную версию PHP
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

PHP_VER="$1"   # например: 7.4, 8.0, 8.1, 8.2, 8.3

if [[ ! "$PHP_VER" =~ ^[78]\.[0-9]$ ]]; then
    echo "ERROR: invalid PHP version (допустимо: 7.4, 8.0-8.3)"; exit 1
fi

MODS="fpm cli mysql mbstring xml curl zip gd bcmath opcache intl"
PKGS=""
for mod in $MODS; do PKGS="$PKGS php${PHP_VER}-${mod}"; done

apt-get update -qq
apt-get install -y -qq $PKGS

systemctl enable "php${PHP_VER}-fpm"
systemctl start  "php${PHP_VER}-fpm"
echo "OK: PHP ${PHP_VER} installed and running"

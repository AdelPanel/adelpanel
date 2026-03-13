#!/bin/bash
# run_update.sh
set -euo pipefail
COMPONENT="${1:-all}"

export DEBIAN_FRONTEND=noninteractive

case "$COMPONENT" in
    all)     apt-get update -qq && apt-get upgrade -y -qq ;;
    nginx)   apt-get install --only-upgrade -y -qq nginx ;;
    php)     apt-get install --only-upgrade -y -qq "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm" ;;
    mariadb) apt-get install --only-upgrade -y -qq mysql-server ;;
    certbot) apt-get install --only-upgrade -y -qq certbot python3-certbot-nginx ;;
    *)       echo "ERROR: unknown component"; exit 1 ;;
esac

echo "OK: updated $COMPONENT"

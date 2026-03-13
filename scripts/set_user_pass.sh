#!/bin/bash
# set_user_pass.sh — обновить пароль пользователя панели
set -euo pipefail

USER="$1"
PASS="$2"

if [[ ! "$USER" =~ ^[a-zA-Z0-9_\-]{1,40}$ ]]; then echo "ERROR: invalid user"; exit 1; fi
if [ ${#PASS} -lt 8 ]; then echo "ERROR: password too short"; exit 1; fi

PANEL_DIR="/opt/adelpanel"

php << PHPEOF
<?php
define('ADELPANEL_ROOT', '${PANEL_DIR}');
require_once '${PANEL_DIR}/config/config.php';
require_once '${PANEL_DIR}/core/Auth.php';

\$hash = Auth::hashPassword('${PASS}');
Auth::saveCredentials('${USER}', \$hash);
echo "OK\n";
PHPEOF

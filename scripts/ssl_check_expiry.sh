#!/bin/bash
# ssl_check_expiry.sh — ежедневная проверка сроков SSL
set -euo pipefail
PANEL_DIR="/opt/adelpanel"
ACME_HOME="/root/.acme.sh"
LOG="[$(date '+%Y-%m-%d %H:%M:%S')]"
echo "$LOG Проверка SSL..."

php -r "
    define('ADELPANEL_ROOT', '/opt/adelpanel');
    require_once '/opt/adelpanel/config/config.php';
    require_once '/opt/adelpanel/core/DB.php';
    require_once '/opt/adelpanel/core/Notify.php';
    \$certs = DB::fetchAll('SELECT * FROM ssl_certs WHERE expires_at IS NOT NULL AND expires_at > 0');
    \$now = time();
    \$warned = 0;
    foreach (\$certs as \$c) {
        \$days = (int)((\$c['expires_at'] - \$now) / 86400);
        if (!in_array(\$days, [30, 14, 7, 3, 1, 0]) && \$days > 0) continue;
        \$exists = DB::fetchOne(
            'SELECT id FROM notifications WHERE type=\'ssl_expiry\' AND json_extract(meta,\'$.domain\')=? AND created_at > strftime(\'%s\',\'now\') - 82800',
            [\$c['domain']]
        );
        if (\$exists) continue;
        Notify::sslExpiring(\$c['domain'], \$days);
        \$warned++;
    }
    echo 'Checked:' . count(\$certs) . ' warned:' . \$warned . PHP_EOL;
" 2>/dev/null || echo "$LOG PHP error"

# Обновляем даты из acme.sh
if [ -x "${ACME_HOME}/acme.sh" ]; then
    "${ACME_HOME}/acme.sh" --list 2>/dev/null | awk 'NR>1 {print $1}' | while read -r dom; do
        [ -z "$dom" ] && continue
        CERT="${ACME_HOME}/${dom}_ecc/${dom}.cer"
        [ -f "$CERT" ] || CERT="${ACME_HOME}/${dom}/${dom}.cer"
        [ -f "$CERT" ] || continue
        EXP=$(openssl x509 -noout -enddate -in "$CERT" 2>/dev/null | cut -d= -f2 | xargs -I{} date -d {} +%s 2>/dev/null || echo 0)
        [ "$EXP" -le 0 ] && continue
        php -r "define('ADELPANEL_ROOT','/opt/adelpanel');require_once '/opt/adelpanel/config/config.php';require_once '/opt/adelpanel/core/DB.php';DB::query('UPDATE ssl_certs SET expires_at=?,updated_at=strftime(\'%s\',\'now\') WHERE domain=?',[$EXP,'$dom']);" 2>/dev/null || true
    done
fi
echo "$LOG Done"

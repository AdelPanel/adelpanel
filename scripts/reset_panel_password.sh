#!/bin/bash
# ══════════════════════════════════════════════
#  AdelPanel — reset_panel_password.sh
#  Сброс пароля панели через SSH-терминал
#  Запуск: sudo /opt/adelpanel/scripts/reset_panel_password.sh
# ══════════════════════════════════════════════
set -euo pipefail

[ "$EUID" -ne 0 ] && echo "Запустите от root: sudo $0" && exit 1

PANEL_DIR="/opt/adelpanel"
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

echo ""
echo -e "${BOLD}${CYAN}══ AdelPanel — Сброс пароля ══${NC}"
echo ""

# Читаем текущий логин из .auth_store
CURRENT_USER=$(php -r "
    define('ADELPANEL_ROOT', '${PANEL_DIR}');
    \$f = '${PANEL_DIR}/data/.auth_store';
    if (!file_exists(\$f)) { echo 'admin'; exit; }
    \$lines = explode(\"\\n\", trim(file_get_contents(\$f)), 2);
    \$d = json_decode(\$lines[0], true);
    echo \$d['u'] ?? 'admin';
" 2>/dev/null || echo "admin")

echo -e "  Текущий логин: ${YELLOW}${CURRENT_USER}${NC}"
echo ""
read -rp "  Новый логин (Enter = оставить ${CURRENT_USER}): " NEW_USER
NEW_USER="${NEW_USER:-$CURRENT_USER}"

# Валидация логина
if [[ ! "$NEW_USER" =~ ^[a-zA-Z0-9_\-]{3,40}$ ]]; then
    echo -e "${RED}  ✗ Недопустимый логин (только a-z, 0-9, _, -)${NC}"
    exit 1
fi

echo ""
read -rsp "  Новый пароль (мин. 12 символов): " NEW_PASS
echo ""

if [ ${#NEW_PASS} -lt 12 ]; then
    echo -e "${RED}  ✗ Пароль слишком короткий (мин. 12 символов)${NC}"
    exit 1
fi

read -rsp "  Повторите пароль: " NEW_PASS2
echo ""

if [ "$NEW_PASS" != "$NEW_PASS2" ]; then
    echo -e "${RED}  ✗ Пароли не совпадают${NC}"
    exit 1
fi

echo ""
echo -e "  ${CYAN}Хэширование пароля...${NC}"

# Хэшируем через ту же схему что и Auth.php (Argon2id + PBKDF2-SHA512)
php -r "
    define('ADELPANEL_ROOT', '${PANEL_DIR}');
    // Читаем PANEL_SECRET из config
    require_once '${PANEL_DIR}/config/config.php';

    \$pass  = '${NEW_PASS}';
    \$user  = '${NEW_USER}';

    // Argon2id
    \$argon = password_hash(\$pass, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2
    ]);

    // PBKDF2-SHA512
    \$salt   = random_bytes(192);
    \$pbkdf2 = hash_pbkdf2('sha512', \$argon, \$salt, 310000, 64, true);
    \$hash   = base64_encode(\$salt) . ':' . base64_encode(\$pbkdf2) . ':' . base64_encode(\$argon);

    // Сохраняем с HMAC
    \$data = json_encode(['u' => \$user, 'h' => \$hash, 't' => time()]);
    \$mac  = hash_hmac('sha512', \$data, PANEL_SECRET);
    file_put_contents('${PANEL_DIR}/data/.auth_store', \$data . \"\\n\" . \$mac);
    chmod('${PANEL_DIR}/data/.auth_store', 0640);
    echo 'ok';
"

echo ""
echo -e "  ${GREEN}✓ Пароль успешно изменён!${NC}"
echo ""
echo -e "  Логин:  ${YELLOW}${NEW_USER}${NC}"
echo -e "  Пароль: ${YELLOW}(введённый выше)${NC}"
echo ""

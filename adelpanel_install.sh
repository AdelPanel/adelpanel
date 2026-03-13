#!/bin/bash
# ══════════════════════════════════════════════════════════════════
#  AdelPanel — install.sh
#  Ubuntu 22.04 / 24.04 | Debian 12 / 13
#  Запуск: sudo bash install.sh
# ══════════════════════════════════════════════════════════════════

set -uo pipefail   # НЕТ -e: ошибки обрабатываем явно через die()
export DEBIAN_FRONTEND=noninteractive
export LANG=C.UTF-8

# ── Цвета ─────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

ok()   { echo -e "${GREEN}  ✓${NC} $*"; }
info() { echo -e "${CYAN}  →${NC} $*"; }
warn() { echo -e "${YELLOW}  ⚠${NC} $*"; }
hdr()  { echo -e "\n${BOLD}${CYAN}─── $* ───${NC}"; }

# Показываем команду + строку при падении, потом выходим
die() {
    echo -e "\n${RED}${BOLD}  ✗ ОШИБКА на строке ${BASH_LINENO[0]}:${NC} $*" >&2
    exit 1
}

# ── Root ──────────────────────────────────────────────────────────
[ "$EUID" -ne 0 ] && die "Запустите от root: sudo bash install.sh"

# ── ОС ────────────────────────────────────────────────────────────
[ -f /etc/os-release ] || die "Не удалось определить ОС"
# shellcheck source=/dev/null
source /etc/os-release

case "${ID:-}:${VERSION_ID:-}" in
    ubuntu:22.04) OS_CODENAME="jammy"    ;;
    ubuntu:24.04) OS_CODENAME="noble"    ;;
    debian:12)    OS_CODENAME="bookworm" ;;
    debian:13)    OS_CODENAME="trixie"   ;;
    *) die "Поддерживается: Ubuntu 22/24, Debian 12/13. У вас: ${PRETTY_NAME:-unknown}" ;;
esac
OS_ID="${ID}"
ok "ОС: ${PRETTY_NAME} (${OS_CODENAME})"

# ── Переменные ────────────────────────────────────────────────────
PANEL_DIR="/opt/adelpanel"
PANEL_PORT="${ADELPANEL_PORT:-7474}"
BACKUP_DIR="/opt/backups"
PHP_DEFAULT="8.2"
DATA_DIR="${PANEL_DIR}/data"

GITHUB_USER="adelpanel"
GITHUB_REPO="adelpanel"
GITHUB_BRANCH="main"
GITHUB_URL="https://github.com/${GITHUB_USER}/${GITHUB_REPO}/archive/refs/heads/${GITHUB_BRANCH}.tar.gz"

# ── Генерация случайных строк (без pipe-ловушки с head) ───────────
# Используем /dev/urandom напрямую через dd + xxd/od — без SIGPIPE
rand_str() {
    local len=$1
    local chars="${2:-A-Za-z0-9}"
    # LC_ALL=C нужен чтобы tr корректно работал с диапазонами
    LC_ALL=C tr -dc "$chars" < /dev/urandom 2>/dev/null \
        | dd bs=1 count="$len" 2>/dev/null
}

PANEL_USER="admin_$(rand_str 6 'a-z0-9')"
PANEL_PASS="$(rand_str 20 'A-Za-z0-9!@#%')"
MYSQL_ROOT_PASS="$(rand_str 32 'A-Za-z0-9')"
PANEL_SECRET="$(rand_str 48 'A-Za-z0-9')"
PMA_TOKEN="$(rand_str 8 'a-zA-Z0-9')"
FG_TOKEN="$(rand_str 8 'a-zA-Z0-9')"
FG_USER="filer_$(rand_str 6 'a-z0-9')"
FG_PASS="$(rand_str 20 'A-Za-z0-9!@#%')"
# SERVER_IP — приоритет IPv4, IPv6 не используем
is_ipv4() { [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; }

SERVER_IP=""
# 1) Внешний IPv4 через внешние сервисы
for _url in "https://api.ipify.org" "https://ifconfig.me" "https://ipv4.icanhazip.com"; do
    _ip=$(curl -4 -s --max-time 5 "$_url" 2>/dev/null) || true
    if is_ipv4 "$_ip"; then SERVER_IP="$_ip"; break; fi
done
# 2) Локальный IPv4 через hostname -I (берём первый IPv4-адрес)
if [ -z "$SERVER_IP" ]; then
    for _ip in $(hostname -I 2>/dev/null); do
        if is_ipv4 "$_ip"; then SERVER_IP="$_ip"; break; fi
    done
fi
SERVER_IP="${SERVER_IP:-127.0.0.1}"

echo -e "\n${BOLD}╔═══════════════════════════════════════════╗"
echo -e   "║        AdelPanel — Установка              ║"
echo -e   "╚═══════════════════════════════════════════╝${NC}\n"
info "Сервер: ${SERVER_IP} | Порт панели: ${PANEL_PORT}"

# ── 1. Базовые пакеты ─────────────────────────────────────────────
hdr "1/18 Базовые пакеты"
echo "nameserver 8.8.8.8" > /etc/resolv.conf
echo "nameserver 1.1.1.1" >> /etc/resolv.conf
apt-get update -qq || die "apt-get update не выполнен"
cat <<'EOF' > /root/.bashrc
# ~/.bashrc: executed by bash(1) for non-login shells.
export LS_OPTIONS='--color=auto'
eval "`dircolors`"
alias ls='ls $LS_OPTIONS'
alias ll='ls $LS_OPTIONS -l'
alias l='ls $LS_OPTIONS -lA'

HISTCONTROL=ignoreboth
HISTFILESIZE=999999999
HISTSIZE=999999999
PS1='${debian_chroot:+($debian_chroot)}\[\e[1;31m\]\u\[\e[1;33m\]@\[\e[1;36m\]\h \[\e[1;33m\]\w \[\e[1;35m\]\$ \[\e[0m\]'
EOF
# ── Исправляем проблему с GRUB на виртуальных серверах ──
# На VPS часто нет физического диска /dev/vda или /dev/sda — grub-pc падает
# Решение: зафиксировать пустой список устройств GRUB перед обновлением
if dpkg -l grub-pc &>/dev/null 2>&1; then
    # Определяем реальный загрузочный диск
    GRUB_DISK=""
    for _d in /dev/vda /dev/sda /dev/xvda /dev/nvme0n1 /dev/hda; do
        if [ -b "$_d" ]; then GRUB_DISK="$_d"; break; fi
    done
    if [ -n "$GRUB_DISK" ]; then
        info "GRUB: задаём устройство ${GRUB_DISK}"
        echo "grub-pc grub-pc/install_devices multiselect ${GRUB_DISK}" \
            | debconf-set-selections 2>/dev/null || true
    else
        # Нет физического диска — отключаем grub-pc от установки
        info "GRUB: физический диск не найден, помечаем grub-pc как on-hold"
        echo "grub-pc hold" | dpkg --set-selections 2>/dev/null || true
    fi
fi

# upgrade не обязателен и может вернуть ненулевой код — используем || true
apt-get upgrade -y -qq 2>/dev/null || true
# Если upgrade упал из-за grub — сбрасываем hold и продолжаем
echo "grub-pc install" | dpkg --set-selections 2>/dev/null || true

apt-get install -y -qq \
    curl wget gnupg2 ca-certificates lsb-release apt-transport-https \
    software-properties-common unzip tar git \
    ufw fail2ban rsync socat jq sqlite3 \
    openssl cron logrotate apache2-utils\
    || die "Не удалось установить базовые пакеты"
ok "Базовые пакеты"

# ── 2. Nginx ──────────────────────────────────────────────────────
hdr "2/18 Nginx"
if ! command -v nginx &>/dev/null; then
    info "Добавляем официальный репозиторий Nginx..."
    curl -fsSL https://nginx.org/keys/nginx_signing.key \
        | gpg --dearmor -o /usr/share/keyrings/nginx.gpg \
        || die "Не удалось получить ключ Nginx"
    echo "deb [signed-by=/usr/share/keyrings/nginx.gpg] https://nginx.org/packages/${OS_ID} ${OS_CODENAME} nginx" \
        > /etc/apt/sources.list.d/nginx.list
    apt-get update -qq
    apt-get install -y -qq nginx || die "Не удалось установить Nginx"
fi
systemctl enable nginx 2>/dev/null || true
systemctl start  nginx 2>/dev/null || true
NGINX_VER=$(nginx -v 2>&1 | grep -oP '[\d.]+' | head -1)
ok "Nginx ${NGINX_VER}"

# ── 3. PHP ────────────────────────────────────────────────────────
hdr "3/18 PHP 8.1 / 8.2 / 8.3"
if [ "$OS_ID" = "ubuntu" ]; then
    add-apt-repository -y ppa:ondrej/php 2>/dev/null \
        || die "Не удалось добавить PPA ondrej/php"
else
    curl -fsSL https://packages.sury.org/php/apt.gpg \
        | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg \
        || die "Не удалось получить ключ sury/php"
    echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main" \
        > /etc/apt/sources.list.d/php.list
fi
apt-get update -qq

PHP_MODS="fpm cli mysql mbstring xml curl zip gd bcmath opcache intl pdo pdo-sqlite sqlite3"
for VER in 8.1 8.2 8.3; do
    PKGS=""
    for mod in $PHP_MODS; do PKGS="$PKGS php${VER}-${mod}"; done
    # Каждая версия — отдельная команда, ошибка не роняет скрипт
    if apt-get install -y -qq $PKGS 2>/dev/null; then
        ok "PHP ${VER}"
    else
        warn "PHP ${VER} не установлен (пропускаем)"
    fi
done
systemctl enable "php${PHP_DEFAULT}-fpm" 2>/dev/null || true
systemctl start  "php${PHP_DEFAULT}-fpm" 2>/dev/null || true

# ── 4. MySQL (Percona 8.0, fallback → MariaDB 10.11) ─────────────
hdr "4/18 База данных"

# Проверяем доступность Percona
PERCONA_AVAILABLE=0
if curl -fsSL --max-time 10 https://repo.percona.com/percona/apt/dists/ &>/dev/null 2>&1; then
    PERCONA_AVAILABLE=1
fi

DB_CHOICE="1"
if [ "$PERCONA_AVAILABLE" = "0" ]; then
    warn "Percona репозиторий недоступен, переключаемся на MariaDB 10.11"
    DB_CHOICE="2"
fi

if [ "$DB_CHOICE" = "2" ]; then
    DB_TYPE="mariadb"
    info "Устанавливаем MariaDB 10.11..."
    curl -fsSL https://downloads.mariadb.com/MariaDB/mariadb_repo_setup \
        | bash -s -- --mariadb-server-version="mariadb-10.11" >/dev/null 2>&1 \
        || die "Не удалось настроить репозиторий MariaDB"
    apt-get update -qq
    apt-get install -y -qq mariadb-server mariadb-client mariadb-backup \
        || die "Не удалось установить MariaDB"
    systemctl enable mariadb && systemctl start mariadb
    # Устанавливаем пароль root
    mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASS}'; FLUSH PRIVILEGES;" \
        2>/dev/null \
        || mysqladmin -u root password "${MYSQL_ROOT_PASS}" 2>/dev/null \
        || true
    ok "MariaDB 10.11"
else
    DB_TYPE="percona"
    info "Устанавливаем Percona MySQL 8.0..."
    wget -q https://repo.percona.com/apt/percona-release_latest.generic_all.deb \
        -O /tmp/percona-release.deb \
        || die "Не удалось скачать percona-release"
    dpkg -i /tmp/percona-release.deb >/dev/null \
        || die "Не удалось установить percona-release"
    percona-release setup ps80 -y >/dev/null 2>&1 \
        || die "percona-release setup ps80 failed"
    apt-get update -qq
    debconf-set-selections <<< "percona-server-server percona-server-server/auth-root-authentication-plugin select Use Legacy Authentication Method"
    apt-get install -y -qq percona-server-server \
        || die "Не удалось установить Percona Server"
    # XtraBackup — опционально
    apt-get install -y -qq percona-xtrabackup-80 qpress 2>/dev/null || true
    systemctl enable mysql && systemctl start mysql
    mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${MYSQL_ROOT_PASS}'; FLUSH PRIVILEGES;" \
        2>/dev/null || true
    ok "Percona MySQL 8.0"
fi

# Сохраняем конфиг подключения
mkdir -p ${PANEL_DIR}/data
cat > ${PANEL_DIR}/data/mysql.conf << MYSQLCONF
[client]
user=root
password=${MYSQL_ROOT_PASS}
socket=/var/run/mysqld/mysqld.sock
MYSQLCONF
chmod 640 ${PANEL_DIR}/data/mysql.conf
chown adelpanel:adelpanel ${PANEL_DIR}/data/mysql.conf
echo "$DB_TYPE" > ${PANEL_DIR}/data/db_type

# Удаляем тестовые данные
mysql --defaults-file=${PANEL_DIR}/data/mysql.conf <<MYSQL_CLEANUP 2>/dev/null || true
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
MYSQL_CLEANUP
ok "MySQL/MariaDB готов (тип: ${DB_TYPE})"

# ── 5. Pure-FTPd ──────────────────────────────────────────────────
hdr "5/18 Pure-FTPd"
apt-get install -y -qq pure-ftpd pure-ftpd-common || warn "Pure-FTPd не установлен"
if command -v pure-ftpd &>/dev/null; then
    mkdir -p /etc/pure-ftpd/conf
    echo "yes" > /etc/pure-ftpd/conf/BrokenClientsCompatibility
    echo "30" > /etc/pure-ftpd/conf/MaxIdleTime
    echo "no" > /etc/pure-ftpd/conf/PAMAuthentication
    echo "yes" > /etc/pure-ftpd/conf/NoAnonymous
    echo "1000" > /etc/pure-ftpd/conf/MinUID
    echo "no" > /etc/pure-ftpd/conf/PAMAuthentication
    echo "1" > /etc/pure-ftpd/conf/TLS
    echo "no" > /etc/pure-ftpd/conf/UnixAuthentication
    echo "35000 50000" > /etc/pure-ftpd/conf/PassivePortRange
    useradd -r -s /bin/false -d /dev/null ftpuser 2>/dev/null || true
    openssl req -x509 -nodes -newkey rsa:2048 -keyout /etc/ssl/private/pure-ftpd.pem -out pure-ftpd.pem -days 365
    openssl dhparam -out /etc/ssl/private/pure-ftpd-dhparams.pem 3072
    openssl req -x509 -nodes -newkey rsa:2048 -keyout /etc/ssl/private/pure-ftpd.pem -out /etc/ssl/private/pure-ftpd.pem -days 365
    ln -s /etc/pure-ftpd/conf/PureDB /etc/pure-ftpd/auth/50pure
    echo "ChrootEveryone               yes" > /etc/pure-ftpd/pure-ftpd.conf
    echo "BrokenClientsCompatibility   no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "MaxClientsNumber             50" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "Daemonize                    yes" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "MaxClientsPerIP              8" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "VerboseLog                   no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "DisplayDotFiles              yes" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "AnonymousOnly                no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "NoAnonymous                  no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "SyslogFacility               ftp" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "DontResolve                  yes" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "MaxIdleTime                  15" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "PureDB                       /etc/pureftpd.pdb" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "LimitRecursion               10000 8" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "AnonymousCanCreateDirs       no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "MaxLoad                      4" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "PassivePortRange             35000 50000" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "AntiWarez                    yes" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "Umask                        133:022" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "MinUID                       100" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "AllowUserFXP                 no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "AllowAnonymousFXP            no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "ProhibitDotFilesWrite        no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "ProhibitDotFilesRead         no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "AutoRename                   no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "AnonymousCantUpload          no" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "MaxDiskUsage                   99" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "CustomerProof                yes" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "TLS                          1" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "CertFile                     /etc/pure-ftpd/pure-ftpd.pem" >> /etc/pure-ftpd/pure-ftpd.conf
    echo "CertFileAndKey               /etc/pure-ftpd/pure-ftpd.pem" >> /etc/pure-ftpd/pure-ftpd.conf
    touch /etc/pure-ftpd/pureftpd.passwd
    pure-pw mkdb /etc/pure-ftpd/pureftpd.pdb \
        -f /etc/pure-ftpd/pureftpd.passwd 2>/dev/null || true
    systemctl enable pure-ftpd 2>/dev/null || true
    systemctl start  pure-ftpd 2>/dev/null || true
    ok "Pure-FTPd"
fi

# ── 6. acme.sh ────────────────────────────────────────────────────
hdr "6/18 acme.sh (SSL)"
if [ ! -f "$HOME/.acme.sh/acme.sh" ]; then
    info "Устанавливаем acme.sh..."
    curl -fsSL https://get.acme.sh | sh -s email="${PANEL_USER}@${SERVER_IP}" \
        2>&1 | grep -E '(Install|error|Error)' || true
    # shellcheck source=/dev/null
    [ -f "$HOME/.acme.sh/acme.sh.env" ] && source "$HOME/.acme.sh/acme.sh.env" || true
    "$HOME/.acme.sh/acme.sh" --set-default-ca --server letsencrypt 2>/dev/null || true
    ok "acme.sh установлен"
else
    ok "acme.sh уже установлен"
fi

# ── 7. UFW ────────────────────────────────────────────────────────
hdr "7/18 UFW Файервол"
# ufw reset может вернуть ненулевой код если правил не было
ufw --force reset >/dev/null 2>&1 || true
ufw default deny incoming  >/dev/null 2>&1
ufw default allow outgoing >/dev/null 2>&1
ufw allow 22/tcp            comment 'SSH'       >/dev/null 2>&1
ufw allow 80/tcp            comment 'HTTP'      >/dev/null 2>&1
ufw allow 443/tcp           comment 'HTTPS'     >/dev/null 2>&1
ufw allow 21/tcp            comment 'FTP'       >/dev/null 2>&1
ufw allow 30000:35000/tcp   comment 'FTP-Pass'  >/dev/null 2>&1
ufw allow "${PANEL_PORT}/tcp" comment 'AdelPanel' >/dev/null 2>&1
ufw --force enable          >/dev/null 2>&1
ok "UFW: SSH/HTTP/HTTPS/FTP/Panel(${PANEL_PORT})"

# ── 8. Fail2ban ───────────────────────────────────────────────────
hdr "8/18 Fail2ban"
mkdir -p /etc/fail2ban/filter.d

cat > /etc/fail2ban/jail.d/adelpanel.conf << F2B_JAIL
[DEFAULT]
bantime  = 3600
findtime = 600
maxretry = 5

[sshd]
enabled  = true
maxretry = 3
bantime  = 86400

[nginx-http-auth]
enabled = true

[adelpanel]
enabled  = true
port     = ${PANEL_PORT}
filter   = adelpanel
logpath  = ${PANEL_DIR}/logs/panel.log
maxretry = 5
bantime  = 3600
F2B_JAIL

cat > /etc/fail2ban/filter.d/adelpanel.conf << F2B_FILTER
[Definition]
failregex = ^\[.*\] \[AUTH_FAIL\].*IP <HOST>
ignoreregex =
F2B_FILTER

systemctl enable fail2ban 2>/dev/null || true
systemctl restart fail2ban 2>/dev/null || true
ok "Fail2ban"

# ── 9. Загружаем AdelPanel ────────────────────────────────────────
hdr "9/18 Загрузка AdelPanel"

# Сначала проверяем — есть ли локальные файлы (запуск из папки проекта)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-install.sh}")" 2>/dev/null && pwd)" || SCRIPT_DIR="$(pwd)"
LOCAL_INSTALL=0

if [ -f "${SCRIPT_DIR}/public/panel.html" ] && [ -f "${SCRIPT_DIR}/core/Auth.php" ]; then
    info "Найдены локальные файлы в ${SCRIPT_DIR} — используем их"
    mkdir -p "$PANEL_DIR"
    # Копируем всё кроме самого install.sh чтобы не затереть случайно
    rsync -a --exclude='*.log' --exclude='.git' "${SCRIPT_DIR}/" "${PANEL_DIR}/" 2>/dev/null \
        || cp -r "${SCRIPT_DIR}/." "${PANEL_DIR}/"
    LOCAL_INSTALL=1
    ok "Локальные файлы скопированы в ${PANEL_DIR}"
else
    # Скачиваем с GitHub
    TMP_DIR=$(mktemp -d)
    trap 'rm -rf "$TMP_DIR"' EXIT

    DL_OK=0
    info "Скачиваем с GitHub: ${GITHUB_URL}"
    if wget -q --timeout=60 --tries=3 \
           --show-progress \
           "${GITHUB_URL}" -O "${TMP_DIR}/panel.tar.gz" 2>&1 \
       && [ -s "${TMP_DIR}/panel.tar.gz" ] \
       && tar -tzf "${TMP_DIR}/panel.tar.gz" >/dev/null 2>&1; then
        DL_OK=1
        ok "Загружено с GitHub"
    else
        warn "GitHub недоступен, пробуем jsDelivr CDN..."
        CDN_URL="https://cdn.jsdelivr.net/gh/${GITHUB_USER}/${GITHUB_REPO}@${GITHUB_BRANCH}/release.tar.gz"
        if wget -q --timeout=60 "${CDN_URL}" \
               -O "${TMP_DIR}/panel.tar.gz" 2>/dev/null \
           && [ -s "${TMP_DIR}/panel.tar.gz" ] \
           && tar -tzf "${TMP_DIR}/panel.tar.gz" >/dev/null 2>&1; then
            DL_OK=1
            ok "Загружено через CDN"
        fi
    fi

    if [ "$DL_OK" -eq 0 ]; then
        die "Не удалось загрузить файлы панели. Запустите install.sh из папки с файлами проекта или проверьте интернет."
    fi

    # Распаковываем
    tar -xzf "${TMP_DIR}/panel.tar.gz" -C "${TMP_DIR}" 2>/dev/null \
        || die "Не удалось распаковать архив"
    EXTRACTED=$(find "${TMP_DIR}" -maxdepth 1 -mindepth 1 -type d | head -1)
    [ -d "$EXTRACTED" ] || die "Не найдена директория после распаковки"
    mkdir -p "$PANEL_DIR"
    cp -r "${EXTRACTED}/." "${PANEL_DIR}/" || die "Не удалось скопировать файлы"
    ok "Файлы распакованы в ${PANEL_DIR}"
fi

mkdir -p "${PANEL_DIR}"/{logs,data,tmp,extras}
mkdir -p "${BACKUP_DIR}"/{sites,databases,full}

# ── 10. Пользователь ──────────────────────────────────────────────
hdr "10/18 Системный пользователь"
useradd -r -s /bin/false -d "$PANEL_DIR" -M adelpanel 2>/dev/null || true
chown -R adelpanel:adelpanel "$PANEL_DIR"
chmod -R 750 "$PANEL_DIR"
chmod -R 755 "$PANEL_DIR/public"
find "$PANEL_DIR/scripts" -name "*.sh" -exec chmod +x {} \; 2>/dev/null || true
chmod 700 "$PANEL_DIR/data"
ok "Пользователь adelpanel"

# ── 11. PHP-FPM пул ───────────────────────────────────────────────
hdr "11/18 PHP-FPM пул"
mkdir -p /var/log/php
cat > "/etc/php/${PHP_DEFAULT}/fpm/pool.d/adelpanel.conf" << FPMPOOL
[adelpanel]
user  = adelpanel
group = adelpanel

listen = /run/php/adelpanel.sock
listen.owner = adelpanel
listen.group = adelpanel
listen.mode  = 0660

pm = dynamic
pm.max_children      = 50
pm.start_servers     = 20
pm.min_spare_servers = 10
pm.max_spare_servers = 30
request_terminate_timeout = 180s

php_admin_value[error_log]      = /var/log/php/adelpanel-error.log
php_admin_flag[log_errors]      = on
php_admin_value[display_errors] = Off
php_admin_value[open_basedir]   = ${PANEL_DIR}:/var/www:/tmp:/opt/backups:/proc:/sys:/etc/nginx:/etc/pure-ftpd:/var/log/nginx:/etc/letsencrypt:/root/.acme.sh:/var/lib/php/sessions
FPMPOOL

# Убеждаемся что www-data в группе adelpanel (для сокета)
usermod -aG adelpanel www-data 2>/dev/null || true

systemctl reload "php${PHP_DEFAULT}-fpm" 2>/dev/null \
    || systemctl restart "php${PHP_DEFAULT}-fpm" 2>/dev/null \
    || die "PHP-FPM не запустился"
ok "PHP-FPM пул adelpanel (php${PHP_DEFAULT})"

# ── Записываем кастомный /etc/nginx/nginx.conf ──────────────────
cat > /etc/nginx/nginx.conf << 'MAINNGINXEOF'
user  adelpanel;
worker_processes  auto;
worker_rlimit_nofile 51200;

error_log  /var/log/nginx/error.log notice;
pid        /run/nginx.pid;

events {
    use epoll;
    worker_connections  2048;
    multi_accept on;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;

    log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
                      '$status $body_bytes_sent "$http_referer" '
                      '"$http_user_agent" "$http_x_forwarded_for"';

    access_log  /var/log/nginx/access.log  main;

    server_names_hash_bucket_size 512;
    client_header_buffer_size 32k;
    large_client_header_buffers 4 32k;
    client_max_body_size 256m;
    client_body_buffer_size 128k;
    sendfile   on;
    tcp_nopush on;
    keepalive_timeout 60s;
    tcp_nodelay on;
    server_tokens off;
    access_log off;
    reset_timedout_connection on;
    client_body_timeout 60s;
    send_timeout 60s;
    open_file_cache max=200000 inactive=20s;
    open_file_cache_valid 30s;
    open_file_cache_min_uses 2;
    open_file_cache_errors on;
    proxy_headers_hash_max_size 1024;
    proxy_headers_hash_bucket_size 128;

    gzip on;
    gzip_http_version 1.1;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/x-javascript text/xml application/xml application/xml+rss text/javascript application/javascript application/wasm image/svg+xml;
    gzip_vary on;
    gzip_proxied   expired no-cache no-store private auth;
    gzip_disable   "MSIE [1-6]\.";

    set_real_ip_from 173.245.48.0/20;
    set_real_ip_from 103.21.244.0/22;
    set_real_ip_from 103.22.200.0/22;
    set_real_ip_from 103.31.4.0/22;
    set_real_ip_from 141.101.64.0/18;
    set_real_ip_from 108.162.192.0/18;
    set_real_ip_from 190.93.240.0/20;
    set_real_ip_from 188.114.96.0/20;
    set_real_ip_from 197.234.240.0/22;
    set_real_ip_from 198.41.128.0/17;
    set_real_ip_from 162.158.0.0/15;
    set_real_ip_from 104.16.0.0/13;
    set_real_ip_from 104.24.0.0/14;
    set_real_ip_from 172.64.0.0/13;
    set_real_ip_from 131.0.72.0/22;
    set_real_ip_from 2400:cb00::/32;
    set_real_ip_from 2606:4700::/32;
    set_real_ip_from 2803:f800::/32;
    set_real_ip_from 2405:b500::/32;
    set_real_ip_from 2405:8100::/32;
    set_real_ip_from 2a06:98c0::/29;
    set_real_ip_from 2c0f:f248::/32;
    real_ip_header CF-Connecting-IP;

    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/conf.d/*/*.conf;
}
MAINNGINXEOF
ok "nginx.conf обновлён"

# ── 12. Nginx конфиг ──────────────────────────────────────────────
hdr "12/18 Nginx конфиг панели"

# Создаём папку для SSL
mkdir -p /etc/nginx/ssl
chmod 700 /etc/nginx/ssl

# Генерируем самоподписный сертификат для панели
SSL_CERT="/etc/nginx/ssl/adelpanel.crt"
SSL_KEY="/etc/nginx/ssl/adelpanel.key"
info "Генерируем самоподписный SSL-сертификат..."
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout "$SSL_KEY" \
    -out "$SSL_CERT" \
    -subj "/C=RU/ST=Server/L=Server/O=AdelPanel/CN=${SERVER_IP}" \
    -addext "subjectAltName=IP:${SERVER_IP}" \
    2>/dev/null || die "Не удалось создать самоподписный сертификат"
chmod 600 "$SSL_KEY"
chmod 644 "$SSL_CERT"
ok "Сертификат: ${SSL_CERT}"

	cat > /etc/nginx/conf.d/adelpanel.conf << NGINXCONF
	server {
	    listen ${PANEL_PORT} ssl;
	    server_name _;
	    server_tokens off;

	    ssl_certificate     /etc/nginx/ssl/adelpanel.crt;
	    ssl_certificate_key /etc/nginx/ssl/adelpanel.key;
	    ssl_protocols       TLSv1.2 TLSv1.3;
	    ssl_ciphers         HIGH:!aNULL:!MD5;
	    ssl_session_cache   shared:SSL:10m;
	    ssl_session_timeout 10m;

	    root ${PANEL_DIR}/public;
	    index index.php;

	    client_max_body_size 512M;

	    access_log /var/log/nginx/adelpanel.access.log;
	    error_log  /var/log/nginx/adelpanel.error.log warn;

	    # X-Frame-Options не ставим — нужен для iframe PMA/FileGator (same-origin)
	    add_header X-Content-Type-Options "nosniff" always;
	    add_header X-XSS-Protection "1; mode=block" always;
	    add_header Strict-Transport-Security "max-age=31536000" always;

	    # PHPMyAdmin — доступен по случайному токен-пути
	    location ^~ /${PMA_TOKEN}/ {
	        alias ${PANEL_DIR}/extras/phpmyadmin/;
	        index index.php;
	        location ~ \.php$ {
	            fastcgi_pass unix:/run/php/adelpanel.sock;
	            fastcgi_index index.php;
	            fastcgi_param SCRIPT_FILENAME \$request_filename;
	            include fastcgi_params;
	            fastcgi_read_timeout 60;
	        }
	    }

	    # FileGator — доступен по случайному токен-пути
	    location ^~ /${FG_TOKEN}/ {
	        alias ${PANEL_DIR}/extras/filegator/;
	        index index.php;
	        location ~ \.php$ {
	            fastcgi_pass unix:/run/php/adelpanel.sock;
	            fastcgi_index index.php;
	            fastcgi_param SCRIPT_FILENAME \$request_filename;
	            include fastcgi_params;
	            fastcgi_read_timeout 60;
	        }
	    }

	    # Запрет прямого доступа к panel.html — всегда через index.php (для CSRF)
	    location = /panel.html { return 302 /; }

	    location / {
	        try_files \$uri \$uri/ /index.php?\$query_string;
	    }

	    location ~ \.php$ {
	        fastcgi_pass          unix:/run/php/adelpanel.sock;
	        fastcgi_index         index.php;
	        fastcgi_param         SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
	        include               fastcgi_params;
	        fastcgi_read_timeout  180;
	    }

	    location ~* \.(sh|log|db|htpasswd)$ { deny all; }
	    location ~ /\.                       { deny all; }
	}
NGINXCONF

# Удаляем старый конфиг из sites-enabled если вдруг есть
rm -f /etc/nginx/sites-enabled/adelpanel.conf 2>/dev/null || true
rm -f /etc/nginx/sites-available/adelpanel.conf 2>/dev/null || true
# Удаляем default site чтобы не конфликтовал
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
rm -f /etc/nginx/conf.d/default.conf 2>/dev/null || true

# nginx должен иметь доступ к сокету adelpanel.sock (listen.group = www-data)
# Убеждаемся что nginx worker работает как www-data (стандартно)
NGINX_USER=$(grep -E '^user\s' /etc/nginx/nginx.conf 2>/dev/null | awk '{print $2}' | tr -d ';')
NGINX_USER="${NGINX_USER:-www-data}"
usermod -aG adelpanel "$NGINX_USER" 2>/dev/null || true

nginx -t || die "Nginx конфиг невалиден (nginx -t провалился)"
nginx -s reload 2>/dev/null || systemctl start nginx
ok "Nginx (HTTPS) на порту ${PANEL_PORT} → /etc/nginx/conf.d/adelpanel.conf"


# ── 13. Sudoers ───────────────────────────────────────────────────
hdr "13/18 Sudoers"
cat > /etc/sudoers.d/adelpanel << SUDOERS
# AdelPanel — белый список скриптов
Defaults:adelpanel !requiretty
adelpanel ALL=(root) NOPASSWD: \
    ${PANEL_DIR}/scripts/create_site.sh, \
    ${PANEL_DIR}/scripts/delete_site.sh, \
    ${PANEL_DIR}/scripts/backup_sites.sh, \
    ${PANEL_DIR}/scripts/backup_databases.sh, \
    ${PANEL_DIR}/scripts/backup_full.sh, \
    ${PANEL_DIR}/scripts/backup_one_site.sh, \
    ${PANEL_DIR}/scripts/backup_one_db.sh, \
    ${PANEL_DIR}/scripts/backup_restore_site.sh, \
    ${PANEL_DIR}/scripts/backup_restore_db.sh, \
    ${PANEL_DIR}/scripts/ssl_issue.sh, \
    ${PANEL_DIR}/scripts/ssl_renew.sh, \
    ${PANEL_DIR}/scripts/ssl_renew_all.sh, \
    ${PANEL_DIR}/scripts/ssl_revoke.sh, \
    ${PANEL_DIR}/scripts/ssl_selfsigned.sh, \
    ${PANEL_DIR}/scripts/ssl_check_expiry.sh, \
    ${PANEL_DIR}/scripts/install_php.sh, \
    ${PANEL_DIR}/scripts/remove_php.sh, \
    ${PANEL_DIR}/scripts/set_hostname.sh, \
    ${PANEL_DIR}/scripts/set_timezone.sh, \
    ${PANEL_DIR}/scripts/set_dns.sh, \
    ${PANEL_DIR}/scripts/set_swap.sh, \
    ${PANEL_DIR}/scripts/disable_swap.sh, \
    ${PANEL_DIR}/scripts/set_panel_port.sh, \
    ${PANEL_DIR}/scripts/set_php_version.sh, \
    ${PANEL_DIR}/scripts/run_update.sh, \
    ${PANEL_DIR}/scripts/ftp_create.sh, \
    ${PANEL_DIR}/scripts/ftp_delete.sh, \
    ${PANEL_DIR}/scripts/ftp_change_pass.sh, \
    ${PANEL_DIR}/scripts/httpauth_enable.sh, \
    ${PANEL_DIR}/scripts/httpauth_disable.sh, \
    ${PANEL_DIR}/scripts/reset_panel_password.sh, \
    /usr/sbin/ufw status, \
    /usr/sbin/ufw allow *, \
    /usr/sbin/ufw deny *, \
    /usr/sbin/ufw delete *, \
    /usr/sbin/ufw reload, \
    /sbin/shutdown, \
    /usr/bin/pure-pw, \
    /usr/sbin/ufw
SUDOERS
chmod 440 /etc/sudoers.d/adelpanel
visudo -c -f /etc/sudoers.d/adelpanel || die "sudoers невалиден"
ok "Sudoers"

# ── 14. config.php ────────────────────────────────────────────────
hdr "14/18 Конфигурация панели"
CONFIG="${PANEL_DIR}/config/config.php"
if [ -f "$CONFIG" ]; then
    sed -i "s|change_this_secret_key_32chars!!|${PANEL_SECRET}|g" "$CONFIG"
    ok "config.php обновлён"
else
    warn "config.php не найден (будет использован дефолтный)"
fi

# ── 15. Учётные данные (Argon2id + PBKDF2) ────────────────────────
hdr "15/18 Создание учётных данных"
info "Хэшируем пароль (Argon2id + PBKDF2-SHA512, соль 192 байт)..."

# Проверяем что php доступен
command -v php >/dev/null || die "php не найден в PATH"

# Хэшируем пароль отдельной командой чтобы не вставлять спецсимволы в heredoc
PANEL_PASS_FILE=$(mktemp)
echo -n "${PANEL_PASS}" > "$PANEL_PASS_FILE"
chmod 600 "$PANEL_PASS_FILE"

php << PHPSCRIPT
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

\$passFile = '${PANEL_PASS_FILE}';
\$pass = file_get_contents(\$passFile);
if (\$pass === false) { echo "ERROR: cannot read pass file\n"; exit(1); }

// Argon2id
\$argon = password_hash(\$pass, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 2,
]);
if (!\$argon) { echo "ERROR: password_hash failed\n"; exit(1); }

// PBKDF2-SHA512 с солью 192 байта
\$salt   = random_bytes(192);
\$pbkdf2 = hash_pbkdf2('sha512', \$argon, \$salt, 310000, 64, true);
\$hash   = base64_encode(\$salt) . ':' . base64_encode(\$pbkdf2) . ':' . base64_encode(\$argon);

\$secret  = '${PANEL_SECRET}';
\$dataDir = '${DATA_DIR}';

if (!is_dir(\$dataDir)) mkdir(\$dataDir, 0700, true);

// auth_store с HMAC
\$data = json_encode(['u' => '${PANEL_USER}', 'h' => \$hash, 't' => time()]);
\$mac  = hash_hmac('sha512', \$data, \$secret);
file_put_contents(\$dataDir . '/.auth_store', \$data . "\n" . \$mac, LOCK_EX);
chmod(\$dataDir . '/.auth_store', 0640);

// PMA token
file_put_contents(\$dataDir . '/.pma_token', '${PMA_TOKEN}', LOCK_EX);
chmod(\$dataDir . '/.pma_token', 0640);
file_put_contents(\$dataDir . '/.fg_token', '${FG_TOKEN}', LOCK_EX);
chmod(\$dataDir . '/.fg_token', 0640);

echo "ok\n";
PHPSCRIPT

PHP_EXIT=$?
rm -f "$PANEL_PASS_FILE"
[ $PHP_EXIT -eq 0 ] || die "PHP не смог создать учётные данные (код: ${PHP_EXIT})"

# Записываем настройки в SQLite
php << SQLITESCRIPT
<?php
error_reporting(0);
define('ADELPANEL_ROOT', '${PANEL_DIR}');
define('PANEL_SECRET',   '${PANEL_SECRET}');
define('DATA_DIR',       '${DATA_DIR}');
define('SQLITE_PATH',    DATA_DIR . '/panel.db');
define('LOG_DIR',        '${PANEL_DIR}/logs');

try {
    \$db = new PDO('sqlite:' . SQLITE_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    \$db->exec("PRAGMA journal_mode=WAL");
    \$db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY NOT NULL,
        value TEXT NOT NULL DEFAULT '',
        updated_at INTEGER DEFAULT (strftime('%s','now'))
    )");
    \$db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        severity TEXT NOT NULL DEFAULT 'info',
        title TEXT NOT NULL,
        message TEXT NOT NULL DEFAULT '',
        read INTEGER NOT NULL DEFAULT 0,
        meta TEXT,
        created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");
    \$db->exec("CREATE TABLE IF NOT EXISTS ssl_certs (
        domain TEXT PRIMARY KEY NOT NULL,
        issuer TEXT DEFAULT 'acme.sh',
        issued_at INTEGER DEFAULT 0,
        expires_at INTEGER DEFAULT 0,
        auto_renew INTEGER DEFAULT 1,
        updated_at INTEGER DEFAULT (strftime('%s','now'))
    )");

    \$ins = \$db->prepare("INSERT INTO settings(key,value) VALUES(?,?)
        ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    foreach ([
        ['acme_email',  'admin@example.com'],
        ['panel_port',  '${PANEL_PORT}'],
        ['server_ip',   '${SERVER_IP}'],
        ['db_type',     '${DB_TYPE}'],
        ['pma_token',   '${PMA_TOKEN}'],
        ['fg_token',    '${FG_TOKEN}'],
    ] as \$row) { \$ins->execute(\$row); }

    // Начальное уведомление
    \$db->exec("INSERT INTO notifications(type,severity,title,message)
        VALUES('system','info','AdelPanel установлен',
        'Панель успешно установлена. Версия 0.13')");

    chmod(SQLITE_PATH, 0640);
    echo "ok\n";
} catch (Exception \$e) {
    echo "WARN: SQLite init: " . \$e->getMessage() . "\n";
}
SQLITESCRIPT

chown -R adelpanel:adelpanel "${PANEL_DIR}/data"
chmod 700 "${PANEL_DIR}/data"
ok "Учётные данные созданы (Argon2id + PBKDF2, соль 192 байт)"

# ── 16. PHPMyAdmin ────────────────────────────────────────────────
hdr "16/18 PHPMyAdmin"
PMA_DIR="${PANEL_DIR}/extras/phpmyadmin"
PMA_VER="5.2.1"
mkdir -p "$PMA_DIR"
PMA_URL="https://files.phpmyadmin.net/phpMyAdmin/${PMA_VER}/phpMyAdmin-${PMA_VER}-all-languages.tar.gz"
if wget -q --timeout=60 "${PMA_URL}" -O /tmp/pma.tar.gz 2>/dev/null \
   && [ -s /tmp/pma.tar.gz ]; then
    tar -xzf /tmp/pma.tar.gz -C "$PMA_DIR" --strip-components=1 2>/dev/null
    PMA_BLOWFISH="$(rand_str 32 'A-Za-z0-9!@#%^&*')"
    if [ -f "$PMA_DIR/config.sample.inc.php" ]; then
        cp "$PMA_DIR/config.sample.inc.php" "$PMA_DIR/config.inc.php"
        sed -i "s|cfg\['blowfish_secret'\] = ''|cfg['blowfish_secret'] = '${PMA_BLOWFISH}'|" \
            "$PMA_DIR/config.inc.php"
    fi
    mkdir -p "$PMA_DIR/tmp"
    ok "PHPMyAdmin ${PMA_VER} → /${PMA_TOKEN}/"
else
    warn "PHPMyAdmin не скачан (установите вручную в ${PMA_DIR})"
fi

# ── 17. FileGator ─────────────────────────────────────────────────
hdr "17/18 FileGator"
FG_DIR="${PANEL_DIR}/extras/filegator"
mkdir -p "$FG_DIR"
FG_URL="https://github.com/filegator/static/raw/master/builds/filegator_latest.zip"
if wget -q --timeout=60 "${FG_URL}" -O /tmp/filegator.zip 2>/dev/null \
   && [ -s /tmp/filegator.zip ]; then
    # Распаковываем во временную директорию и перемещаем содержимое
    TMP_FG="/tmp/filegator_unpack_$$"
    mkdir -p "$TMP_FG"
    if unzip -q /tmp/filegator.zip -d "$TMP_FG" 2>/dev/null; then
        # Если внутри одна директория — поднять содержимое наверх
        INNER=$(find "$TMP_FG" -maxdepth 1 -mindepth 1 -type d | head -1)
        if [ -n "$INNER" ] && [ -f "$INNER/index.php" ]; then
            cp -a "$INNER/." "$FG_DIR/"
        else
            cp -a "$TMP_FG/." "$FG_DIR/"
        fi
        rm -rf "$TMP_FG"
        ok "FileGator установлен → /${FG_TOKEN}/"
    else
        rm -rf "$TMP_FG"
        warn "FileGator: ошибка распаковки"
    fi
else
    warn "FileGator не скачан (установите вручную в ${FG_DIR})"
fi

php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === 'c8b085408188070d5f52bcfe4ecfbee5f727afa458b2573b8eaaf77b3419b0bf2768dc67c86944da1544f06fa544fd47') { echo 'Installer verified'.PHP_EOL; } else { echo 'Installer corrupt'.PHP_EOL; unlink('composer-setup.php'); exit(1); }"
php composer-setup.php
php -r "unlink('composer-setup.php');"
mv composer.phar /usr/local/bin/composer

cd ${PANEL_DIR}/extras/filegator/
sudo -u adelpanel composer config audit.block-insecure false
sudo -u adelpanel composer update
sed -i "s|__DIR__.'/repository'|realpath('/var/www')|g" /opt/adelpanel/extras/filegator/configuration.php
FG_PASS_HASH=$(php -r "echo password_hash('$FG_PASS', PASSWORD_DEFAULT);")
FG_FILE_SEC="/opt/adelpanel/extras/filegator/private/users.json"
jq "del(.\"2\") | . + {\"2\": {
  \"username\": \$user,
  \"name\": \$user,
  \"role\": \"admin\",
  \"homedir\": \"/\",
  \"permissions\": \"read|write|upload|download|batchdownload|zip|chmod\",
  \"password\": \$hash
}}" --arg user "$FG_USER" --arg hash "$FG_PASS_HASH" "$FG_FILE_SEC" > "${FG_FILE_SEC}.tmp" && mv "${FG_FILE_SEC}.tmp" "$FG_FILE_SEC"

cd /root/
chown -R adelpanel:adelpanel "${PANEL_DIR}/extras" 2>/dev/null || true

chown -R adelpanel:adelpanel /var/www 2>/dev/null || true


# ── 18. Cron + Logrotate ──────────────────────────────────────────
hdr "18/18 Cron / Logrotate"
cat > /etc/cron.d/adelpanel << CRONFILE
# AdelPanel — автоматические задачи
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

0  3 * * *  root ${PANEL_DIR}/scripts/backup_sites.sh     >> /var/log/adelpanel-backup.log 2>&1
30 3 * * *  root ${PANEL_DIR}/scripts/backup_databases.sh >> /var/log/adelpanel-backup.log 2>&1
0  4 * * 0  root ${PANEL_DIR}/scripts/backup_full.sh      >> /var/log/adelpanel-backup.log 2>&1
0  5 * * *  root find ${BACKUP_DIR} -type f -mtime +7 -delete 2>/dev/null
0  6 * * *  root ${PANEL_DIR}/scripts/ssl_check_expiry.sh >> /var/log/adelpanel-ssl.log 2>&1
0 12 * * *  root /root/.acme.sh/acme.sh --cron --home /root/.acme.sh >> /var/log/adelpanel-ssl.log 2>&1
CRONFILE
chmod 644 /etc/cron.d/adelpanel

cat > /etc/logrotate.d/adelpanel << LOGROTATE
${PANEL_DIR}/logs/panel.log
/var/log/adelpanel-backup.log
/var/log/adelpanel-ssl.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    create 0640 adelpanel adelpanel
}
LOGROTATE
ok "Cron + Logrotate"

# ══════════════════════════════════════════════
#  ФИНАЛ
# ══════════════════════════════════════════════
sleep 5
clear
echo ""
echo -e "${BOLD}${GREEN}"
echo "  ╔══════════════════════════════════════════════════════════╗"
echo "  ║            ✓  AdelPanel установлен!                    ║"
echo "  ╠══════════════════════════════════════════════════════════╣"
echo "  ║  СОХРАНИТЕ ДАННЫЕ — ОНИ НИГДЕ БОЛЬШЕ НЕ ОТОБРАЖАЮТСЯ  ║"
echo "  ╚══════════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  ${BOLD}Панель:${NC}       ${CYAN}https://${SERVER_IP}:${PANEL_PORT}${NC}  ${YELLOW}(самоподписный SSL — подтвердите в браузере)${NC}"
echo -e "  ${BOLD}Логин:${NC}        ${YELLOW}${PANEL_USER}${NC}"
echo -e "  ${BOLD}Пароль:${NC}       ${YELLOW}${PANEL_PASS}${NC}"
echo ""
echo -e "  ${BOLD}PHPMyAdmin:${NC}   ${CYAN}https://${SERVER_IP}:${PANEL_PORT}/${PMA_TOKEN}/${NC}"
echo -e "  ${BOLD}Файловый менеджер:${NC}    ${CYAN}https://${SERVER_IP}:${PANEL_PORT}/${FG_TOKEN}/${NC}"
echo -e "      ${BOLD}Логин:${NC}        ${YELLOW}${FG_USER}${NC}"
echo -e "      ${BOLD}Пароль:${NC}       ${YELLOW}${FG_PASS}${NC}"
echo ""
echo -e "  ${BOLD}MySQL root:${NC}   ${YELLOW}${MYSQL_ROOT_PASS}${NC}"
echo -e "               ${RED}(конфиг: /root/.adelpanel/mysql.conf)${NC}"
echo ""
echo -e "  ${RED}${BOLD}  ↑↑↑  ЗАПИШИТЕ ПАРОЛЬ — НИГДЕ НЕ СОХРАНЁН  ↑↑↑${NC}"
echo ""
echo -e "  ${BOLD}Сброс пароля:${NC}  sudo ${PANEL_DIR}/scripts/reset_panel_password.sh"
echo ""

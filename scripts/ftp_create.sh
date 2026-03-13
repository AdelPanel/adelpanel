#!/bin/bash
# AdelPanel — ftp_create.sh
set -euo pipefail
USER="$1"; PASS="$2"; HOMEDIR="$3"

if [[ ! "$USER" =~ ^[a-zA-Z0-9_-]{1,32}$ ]]; then echo "ERROR: invalid user"; exit 1; fi
if [[ ! "$HOMEDIR" =~ ^/var/www/ ]]; then echo "ERROR: path outside /var/www"; exit 1; fi

mkdir -p "$HOMEDIR"
chown adelpanel:adelpanel "$HOMEDIR"

# Создаём системного пользователя только для FTP
if ! id "$USER" &>/dev/null; then
    useradd -d "$HOMEDIR" -s /bin/false -M "$USER" 2>/dev/null || true
fi

# pure-pw useradd требует пароль дважды — передаём через expect или stdin с двойным printf
if command -v expect &>/dev/null; then
    expect -c "
        spawn pure-pw useradd \"$USER\" -u adelpanel -d \"$HOMEDIR\"
        expect \"Password:\" { send \"$PASS\r\" }
        expect \"Enter it again:\" { send \"$PASS\r\" }
        expect eof
    "
else
    # Без expect — передаём через printf дважды
    printf '%s\n%s\n' "$PASS" "$PASS" | pure-pw useradd "$USER" -u adelpanel -d "$HOMEDIR" -m
fi

# Обновляем базу данных pure-ftpd
pure-pw mkdb 2>/dev/null || true

echo "OK: FTP user $USER created → $HOMEDIR"

#!/bin/bash
# ssl_issue.sh — выдача SSL через acme.sh (Let's Encrypt / ZeroSSL)
set -euo pipefail

DOMAIN="$1"
EMAIL="${2:-admin@example.com}"
WILDCARD="${3:-0}"
ACME="/root/.acme.sh/acme.sh"
WEBROOT="/var/www"
NGINX_CONF="/etc/nginx/sites-available/${DOMAIN}.conf"

if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$ ]]; then
    echo "ERROR: invalid domain"; exit 1
fi

# Убеждаемся что acme.sh установлен
if [ ! -f "$ACME" ]; then
    echo "Installing acme.sh..."
    curl -fsSL https://get.acme.sh | sh -s email="$EMAIL" >/dev/null 2>&1
fi

# Регистрируем email (один раз)
"$ACME" --register-account -m "$EMAIL" >/dev/null 2>&1 || true

# Переключаемся на Let's Encrypt (ZeroSSL по умолчанию может требовать EAB)
"$ACME" --set-default-ca --server letsencrypt >/dev/null 2>&1 || true

# Создаём webroot если его нет
mkdir -p "${WEBROOT}/${DOMAIN}/.well-known/acme-challenge" 2>/dev/null || true

# Выпускаем сертификат
if [ "$WILDCARD" = "1" ]; then
    # Wildcard требует DNS challenge — только для ручного использования
    "$ACME" --issue --dns -d "${DOMAIN}" -d "*.${DOMAIN}" --yes-I-know-dns-manual-mode-enough-go-ahead-please 2>&1
else
    # Webroot challenge (Nginx должен отдавать .well-known)
    "$ACME" --issue -d "${DOMAIN}" -d "www.${DOMAIN}" \
        --webroot "${WEBROOT}/${DOMAIN}" \
        --keylength ec-256 2>&1 \
    || \
    # Fallback: standalone (временно останавливаем nginx)
    "$ACME" --issue -d "${DOMAIN}" -d "www.${DOMAIN}" \
        --standalone --httpport 80 \
        --keylength ec-256 2>&1
fi

# Устанавливаем сертификат в nginx-конфиг
SSL_DIR="/etc/nginx/ssl/${DOMAIN}"
mkdir -p "$SSL_DIR"

"$ACME" --install-cert -d "${DOMAIN}" \
    --cert-file    "${SSL_DIR}/cert.pem" \
    --key-file     "${SSL_DIR}/privkey.pem" \
    --fullchain-file "${SSL_DIR}/fullchain.pem" \
    --reloadcmd "nginx -s reload" 2>&1

# Прописываем SSL в nginx конфиг если ещё не прописан
if [ -f "$NGINX_CONF" ] && ! grep -q "ssl_certificate" "$NGINX_CONF"; then
    sed -i "s|listen 80;|listen 80;\n    listen 443 ssl http2;\n    ssl_certificate ${SSL_DIR}/fullchain.pem;\n    ssl_certificate_key ${SSL_DIR}/privkey.pem;\n    ssl_protocols TLSv1.2 TLSv1.3;\n    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384;\n    ssl_prefer_server_ciphers off;\n    add_header Strict-Transport-Security \"max-age=63072000\" always;|" "$NGINX_CONF"
    # Редирект http -> https
    cat >> "$NGINX_CONF" <<NGINX

# HTTP → HTTPS редирект
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};
    return 301 https://\$host\$request_uri;
}
NGINX
fi

nginx -t 2>&1 && nginx -s reload
echo "OK: SSL issued for ${DOMAIN} via acme.sh"

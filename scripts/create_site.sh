#!/bin/bash
# create_site.sh — создать сайт с конфигом под движок
set -euo pipefail

DOMAIN="$1"; PHP_VER="$2"; WEBROOT="$3"; SSL="${4:-0}"; ENGINE="${5:-php}"

if [[ ! "$DOMAIN"  =~ ^[a-zA-Z0-9][a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$ ]]; then echo "ERROR: invalid domain"; exit 1; fi
if [[ ! "$PHP_VER" =~ ^[78]\.[0-9]$ ]]; then echo "ERROR: invalid PHP version"; exit 1; fi
if [[ ! "$WEBROOT" =~ ^/var/www/ ]]; then echo "ERROR: webroot must be inside /var/www/"; exit 1; fi

mkdir -p "$WEBROOT/public"

CONF="/etc/nginx/conf.d/${DOMAIN}.conf"

PHP_LOCATION="
    location ~ \.php$ {
        include        snippets/fastcgi-php.conf;
        fastcgi_pass   unix:/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include        fastcgi_params;
        fastcgi_read_timeout 300;
    }"

STATIC="
    location ~* \.(jpg|jpeg|gif|png|webp|css|js|ico|svg|woff2?|ttf)$ {
        expires 30d; add_header Cache-Control \"public\";
    }"

write_server_block() {
    local EXTRA_LOCATIONS="$1"
    cat > "$CONF" <<EOF
server {
    listen 80;
    server_name $DOMAIN www.$DOMAIN;
    root $WEBROOT/public;
    index index.php index.html;
    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;
    client_max_body_size 64M;
    $EXTRA_LOCATIONS
    ${PHP_LOCATION}
    ${STATIC}
    location ~ /\. { deny all; }
}
EOF
}

case "$ENGINE" in
    wordpress)
        write_server_block "
    location / { try_files \$uri \$uri/ /index.php?\$args; }
    location ~* /uploads/.*\\.php\$ { deny all; }
    location ~* /(xmlrpc|wp-config)\\.php\$ { deny all; }"
        cat > "$WEBROOT/public/index.php" <<'P'
<?php echo '<p>WordPress: upload files here</p>';
P
        ;;
    laravel)
        write_server_block "location / { try_files \$uri \$uri/ /index.php?\$query_string; }"
        cat > "$WEBROOT/public/index.php" <<'P'
<?php echo '<p>Laravel: deploy your project and run composer install</p>';
P
        ;;
    drupal)
        cat > "$CONF" <<EOF
server {
    listen 80; server_name $DOMAIN www.$DOMAIN;
    root $WEBROOT/public; index index.php;
    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;
    location / { try_files \$uri /index.php?\$query_string; }
    location ~ ^/sites/.*/private/ { return 403; }
    location ~ /\. { deny all; }
    location ~ \\.php(/|\$) {
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        include fastcgi_params;
    }
    ${STATIC}
}
EOF
        ;;
    joomla)
        write_server_block "
    location / { try_files \$uri \$uri/ /index.php?\$args; }
    location ~* /(cache|logs|tmp)/ { deny all; }"
        ;;
    html)
        cat > "$CONF" <<EOF
server {
    listen 80; server_name $DOMAIN www.$DOMAIN;
    root $WEBROOT/public; index index.html index.htm;
    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;
    location / { try_files \$uri \$uri/ =404; }
    location ~ /\. { deny all; }
    ${STATIC}
}
EOF
        echo '<h1>Site ready</h1>' > "$WEBROOT/public/index.html"
        ;;
    *)  # generic php
        write_server_block "location / { try_files \$uri \$uri/ /index.php?\$query_string; }"
        cat > "$WEBROOT/public/index.php" <<P
<?php echo '<h1>${DOMAIN}</h1><p>PHP ' . phpversion() . '</p>';
P
        ;;
esac

chown -R adelpanel:adelpanel "$WEBROOT"
chmod -R 755 "$WEBROOT"

nginx -t && nginx -s reload
echo "OK: $DOMAIN (${ENGINE}) created"

if [ "$SSL" = "1" ]; then
    # Пробуем выдать SSL через acme.sh
    if [ -f "/root/.acme.sh/acme.sh" ]; then
        EMAIL=$(sqlite3 /opt/adelpanel/data/panel.db "SELECT value FROM settings WHERE key='acme_email'" 2>/dev/null || echo "admin@example.com")
        /root/.acme.sh/acme.sh --issue -d "$DOMAIN" -d "www.$DOMAIN" --webroot /var/www --reloadcmd "nginx -s reload" 2>&1 || true
        echo "OK: SSL requested for $DOMAIN"
    fi
fi

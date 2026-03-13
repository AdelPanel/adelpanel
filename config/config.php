<?php
// ══════════════════════════════════════════════
//  AdelPanel — config.php
// ══════════════════════════════════════════════

if (!defined('ADELPANEL_VERSION')) define('ADELPANEL_VERSION', '0.13-alpha');
if (!defined('ADELPANEL_ROOT'))    define('ADELPANEL_ROOT',    dirname(__DIR__));

// ── Панель ──────────────────────────────────
if (!defined('PANEL_PORT'))       define('PANEL_PORT',       7474);
if (!defined('PANEL_SECRET'))     define('PANEL_SECRET',     'change_this_secret_key_32chars!!');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 1800);

// ── Пути (DATA_DIR нужен раньше всего остального) ──
if (!defined('DATA_DIR')) define('DATA_DIR', ADELPANEL_ROOT . '/data');

// ── SQLite (внутренняя БД панели) ────────────
if (!defined('SQLITE_PATH')) define('SQLITE_PATH', ADELPANEL_ROOT . '/data/panel.db');

// ── MySQL root доступ ──
if (!defined('MYSQL_CONF'))   define('MYSQL_CONF',   DATA_DIR . '/mysql.conf');
if (!defined('MYSQL_SOCKET')) define('MYSQL_SOCKET', '/var/run/mysqld/mysqld.sock');

// ── Пути ─────────────────────────────────────
if (!defined('NGINX_SITES_AVAILABLE')) define('NGINX_SITES_AVAILABLE', '/etc/nginx/sites-available');
if (!defined('NGINX_SITES_ENABLED'))   define('NGINX_SITES_ENABLED',   '/etc/nginx/sites-enabled');
if (!defined('NGINX_CONF_D'))          define('NGINX_CONF_D',          '/etc/nginx/conf.d');
if (!defined('WEBROOT'))               define('WEBROOT',               '/var/www');
if (!defined('LOG_DIR'))               define('LOG_DIR',               ADELPANEL_ROOT . '/logs');
if (!defined('PANEL_LOG'))             define('PANEL_LOG',             LOG_DIR . '/panel.log');

// ── Скрипты ───────────────────────────────────
if (!defined('SCRIPTS_DIR')) define('SCRIPTS_DIR', ADELPANEL_ROOT . '/scripts');

// ── Extras ────────────────────────────────────
if (!defined('EXTRAS_DIR'))     define('EXTRAS_DIR',     ADELPANEL_ROOT . '/extras');
if (!defined('PMA_TOKEN_FILE')) define('PMA_TOKEN_FILE', DATA_DIR . '/.pma_token');
if (!defined('FG_TOKEN_FILE'))  define('FG_TOKEN_FILE',  DATA_DIR . '/.fg_token');

// ── FTP ───────────────────────────────────────
if (!defined('PUREFTPD_PASSWD')) define('PUREFTPD_PASSWD', '/etc/pure-ftpd/pureftpd.passwd');
if (!defined('PUREFTPD_PDB'))    define('PUREFTPD_PDB',    '/etc/pure-ftpd/pureftpd.pdb');
if (!defined('PUREFTPD_BIN'))    define('PUREFTPD_BIN',    '/usr/bin/pure-pw');

// ── SSL (acme.sh) ─────────────────────────────
if (!defined('ACME_HOME'))    define('ACME_HOME',    '/root/.acme.sh');
if (!defined('ACME_BIN'))     define('ACME_BIN',     ACME_HOME . '/acme.sh');
if (!defined('ACME_EMAIL'))   define('ACME_EMAIL',   'admin@example.com');
if (!defined('ACME_WEBROOT')) define('ACME_WEBROOT', WEBROOT);

// ── PHP-FPM ───────────────────────────────────
if (!defined('PHP_FPM_POOL_DIR')) define('PHP_FPM_POOL_DIR', '/etc/php/{VER}/fpm/pool.d');
if (!defined('PHP_VERSIONS'))     define('PHP_VERSIONS',     ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4']);

// ── GitHub источник ───────────────────────────
if (!defined('GITHUB_REPO'))   define('GITHUB_REPO',   'adelpanel/adelpanel');
if (!defined('GITHUB_BRANCH')) define('GITHUB_BRANCH', 'main');

// ── Окружение ─────────────────────────────────
if (!defined('APP_ENV'))   define('APP_ENV',   'production');
if (!defined('APP_DEBUG')) define('APP_DEBUG', APP_ENV === 'development');

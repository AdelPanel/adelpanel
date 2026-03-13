<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/sites.php
//  Управление сайтами: list / create / delete / httpauth
// ══════════════════════════════════════════════

require_once __DIR__ . '/../core/Api.php';
require_once __DIR__ . '/../core/Notify.php';

Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'list';

match ($action) {
    'list'      => siteList(),
    'create'    => siteCreate(),
    'delete'    => siteDelete(),
    'httpauth'  => siteHttpAuth(),
    default     => Api::error("Unknown action: {$action}"),
};

// ── Список сайтов ─────────────────────────────
function siteList(): void
{
    Api::onlyGet();

    $sites     = [];
    $seen      = [];

    // Основной источник — conf.d. sites-available только как запасной вариант (без дублей)
    $confDFiles = glob(NGINX_CONF_D . '/*.conf') ?: [];
    $saFiles    = glob(NGINX_SITES_AVAILABLE . '/*.conf') ?: [];

    // Исключаем конфиг самой панели из обоих мест
    $filter = fn($f) => !preg_match('/adelpanel/i', basename($f));
    $confDFiles = array_filter($confDFiles, $filter);
    $saFiles    = array_filter($saFiles, $filter);

    // Домены из conf.d помечаем как seen, чтобы не дублировать из sites-available
    foreach ($confDFiles as $f) $seen[basename($f, '.conf')] = true;

    $confFiles = array_merge(
        array_values($confDFiles),
        array_filter(array_values($saFiles), fn($f) => !isset($seen[basename($f, '.conf')]))
    );

    foreach ($confFiles as $confFile) {
        $domain  = basename($confFile, '.conf');
        $inConfD = str_contains($confFile, '/conf.d/');
        $enabled = $inConfD ? true : file_exists(NGINX_SITES_ENABLED . "/{$domain}.conf");
        $content = file_get_contents($confFile);

        // Парсим root из конфига
        preg_match('/root\s+([^;]+);/', $content, $rootMatch);
        $webroot = trim($rootMatch[1] ?? '');

        // Парсим PHP версию из fastcgi_pass
        preg_match('/php(\d+\.\d+)-fpm\.sock/', $content, $phpMatch);
        $phpVer = $phpMatch[1] ?? 'N/A';

        // SSL?
        $hasSSL    = str_contains($content, 'ssl_certificate');
        $sslPath   = "/etc/letsencrypt/live/{$domain}/cert.pem";
        $sslExpiry = null;
        $sslDays   = null;
        if ($hasSSL && file_exists($sslPath)) {
            $expiry   = shell_exec("openssl x509 -enddate -noout -in " . escapeshellarg($sslPath) . " 2>/dev/null");
            preg_match('/notAfter=(.+)/', (string)$expiry, $em);
            if (!empty($em[1])) {
                $expTs    = strtotime(trim($em[1]));
                $sslExpiry = date('Y-m-d', $expTs);
                $sslDays  = (int) ceil(($expTs - time()) / 86400);
            }
        }

        // HTTP Auth?
        $hasAuth = str_contains($content, 'auth_basic ');

        // Проверяем что nginx конфиг валидный (nginx -t проходит)
        $nginxOk = $enabled;

        $sites[] = [
            'domain'      => $domain,
            'webroot'     => $webroot,
            'path'        => $webroot,   // alias для panel.html
            'php'         => $phpVer,
            'enabled'     => $enabled,
            'ssl'         => $hasSSL,
            'ssl_ok'      => $hasSSL && $sslDays !== null && $sslDays > 0,
            'ssl_expiring' => $hasSSL && $sslDays !== null && $sslDays <= 30 && $sslDays > 0,
            'ssl_expiry'  => $sslExpiry,
            'ssl_days'    => $sslDays,
            'http_auth'   => $hasAuth,
            'nginx_ok'    => $nginxOk,
        ];
    }

    Api::ok($sites);
}

// ── Создать сайт ──────────────────────────────
function siteCreate(): void
{
    Api::onlyPost();
    Api::requireFields(['domain', 'php']);

    $domain  = Api::input('domain');
    $php     = Api::input('php', '8.2');
    $webroot = Api::input('webroot') ?: WEBROOT . '/' . $domain;
    $ssl     = (bool) Api::input('ssl', true);
    $engine  = Api::input('engine', 'html');
    $server  = Api::input('server', 'nginx'); // nginx / apache

    // Валидация домена
    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        Api::error('Недопустимое имя домена');
    }

    // Валидация PHP версии
    if (!in_array($php, PHP_VERSIONS, true)) {
        Api::error('Недопустимая версия PHP');
    }

    // Валидация пути (нельзя выйти за пределы /var/www)
    // Директория ещё не существует — не используем realpath, просто нормализуем
    $webroot = '/' . implode('/', array_filter(explode('/', $webroot)));
    // Защита от path traversal
    // Гарантируем что путь именно внутри /var/www/, а не /var/wwwother
    $webrootBase = rtrim(WEBROOT, '/') . '/';
    if (str_contains($webroot, '..') || !str_starts_with($webroot . '/', $webrootBase)) {
        Api::error('Путь должен быть внутри ' . WEBROOT);
    }

    // Запускаем скрипт
    $result = Api::runScript('create_site.sh', [$domain, $php, $webroot, $ssl ? '1' : '0', $engine]);

    if (!$result['ok']) {
        Api::error('Ошибка создания сайта: ' . $result['output']);
    }

    // Автосоздание БД если запрошено
    $autoDb = (bool) Api::input('create_db', false);
    if ($autoDb) {
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '_', $domain);
        $dbName = substr($dbName, 0, 32);
        try {
            DB::mysql()->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable) { /* не критично */ }
    }

    Notify::siteAdded($domain, $engine ?: 'html');
    Api::ok(['domain' => $domain, 'webroot' => $webroot], "Сайт {$domain} создан");
}

// ── Удалить сайт ──────────────────────────────
function siteDelete(): void
{
    Api::onlyPost();
    Api::requireFields(['domain', 'confirm']);

    $domain      = Api::input('domain');
    $confirm     = Api::input('confirm');
    $deleteFiles = (bool) Api::input('delete_files', false);

    if ($domain !== $confirm) {
        Api::error('Подтверждение не совпадает с именем домена');
    }

    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        Api::error('Недопустимое имя домена');
    }

    $result = Api::runScript('delete_site.sh', [$domain, $deleteFiles ? '1' : '0']);

    if (!$result['ok']) {
        Api::error('Ошибка удаления: ' . $result['output']);
    }

    Notify::siteDeleted($domain);
    Api::ok(null, "Сайт {$domain} удалён");
}

// ── HTTP Basic Auth ───────────────────────────
function siteHttpAuth(): void
{
    Api::onlyPost();
    Api::requireFields(['domain', 'enabled']);

    $domain  = Api::input('domain');
    $enabled = (bool) Api::input('enabled');
    $user    = Api::input('user', '');
    $pass    = Api::input('pass', '');
    $realm   = Api::input('realm', 'Restricted Area');

    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        Api::error('Недопустимое имя домена');
    }

    if ($enabled) {
        if (empty($user) || empty($pass)) {
            Api::error('Укажите логин и пароль для HTTP Auth');
        }
        // Валидация: только безопасные символы
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $user)) {
            Api::error('Недопустимое имя пользователя');
        }
        $result = Api::runScript('httpauth_enable.sh', [$domain, $user, $pass, $realm]);
    } else {
        $result = Api::runScript('httpauth_disable.sh', [$domain]);
    }

    if (!$result['ok']) {
        Api::error('Ошибка: ' . $result['output']);
    }

    Api::ok(null, $enabled ? "HTTP Auth включён для {$domain}" : "HTTP Auth отключён для {$domain}");
}

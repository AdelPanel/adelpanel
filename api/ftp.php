<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/ftp.php
//  Управление FTP (Pure-FTPd виртуальные юзеры)
// ══════════════════════════════════════════════

require_once __DIR__ . '/../core/Api.php';
require_once __DIR__ . '/../core/Notify.php';

Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? '';

match ($action) {
    'list'         => ftpList(),
    'create'       => ftpCreate(),
    'delete'       => ftpDelete(),
    'change_pass'  => ftpChangePass(),
    'change_path'  => ftpChangePath(),
    default        => Api::error("Unknown action: {$action}"),
};

// ── Список FTP пользователей ──
function ftpList(): void
{
    Api::onlyGet();

    // Пробуем несколько возможных путей к файлу паролей
    $candidates = [
        PUREFTPD_PASSWD,
        '/etc/pure-ftpd/pureftpd.passwd',
        '/var/db/pureftpd.passwd',
    ];
    $passwdFile = null;
    foreach ($candidates as $p) {
        if (file_exists($p) && is_readable($p)) { $passwdFile = $p; break; }
    }

    if (!$passwdFile) {
        // Fallback: pure-pw list (требует sudo или root)
        $raw = shell_exec('sudo /usr/bin/pure-pw list 2>/dev/null') ?: '';
        $users = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) < 2) continue;
            $users[] = [
                'user'    => $parts[0],
                'login'   => $parts[0],
                'homedir' => rtrim($parts[1], '/'),
                'path'    => rtrim($parts[1], '/'),
                'site'    => null,
                'active'  => true,
            ];
        }
        Api::ok($users);
        return;
    }

    $users = [];
    $lines = @file($passwdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    // Кэш nginx конфигов для поиска сайта по пути
    $allConfs = array_merge(
        glob(NGINX_CONF_D . '/*.conf') ?: [],
        glob(NGINX_SITES_AVAILABLE . '/*.conf') ?: []
    );

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '#')) continue;

        $parts = explode(':', $line);
        // Pure-FTPd passwd: user:hash:uid:gid:comment:homedir:shell...
        if (count($parts) < 6) continue;

        $user    = trim($parts[0]);
        $homedir = rtrim(trim($parts[5]), '/');
        if (empty($user)) continue;

        // Ищем соответствующий сайт по homedir
        $site = null;
        foreach ($allConfs as $conf) {
            $domain = basename($conf, '.conf');
            if (in_array($domain, ['default', 'adelpanel'], true)) continue;
            $confContent = @file_get_contents($conf) ?: '';
            if (preg_match('/root\s+([^;]+);/', $confContent, $m)) {
                if (rtrim(trim($m[1]), '/') === $homedir) {
                    $site = $domain;
                    break;
                }
            }
        }

        $users[] = [
            'user'    => $user,
            'login'   => $user,
            'homedir' => $homedir,
            'path'    => $homedir,
            'site'    => $site,
            'active'  => true,
        ];
    }

    Api::ok($users);
}

// ── Создать FTP пользователя ──
function ftpCreate(): void
{
    Api::onlyPost();
    Api::requireFields(['user', 'pass']);

    $user    = Api::input('user');
    $pass    = Api::input('pass');
    $homedir = Api::input('homedir') ?: Api::input('path') ?: '';
    if (empty($homedir)) Api::error("Поле 'homedir' обязательно");

    if (!preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $user)) {
        Api::error('Недопустимое имя пользователя FTP');
    }
    if (strlen($pass) < 8) {
        Api::error('Пароль должен быть не менее 8 символов');
    }
    $homedir = rtrim($homedir, '/');
    if (!str_starts_with($homedir, WEBROOT)) {
        Api::error('Путь должен быть внутри ' . WEBROOT);
    }

    $result = Api::runScript('ftp_create.sh', [$user, $pass, $homedir]);
    if (!$result['ok']) {
        Api::error('Ошибка создания FTP: ' . $result['output']);
    }

    Logger::write('ftp', "Создан FTP юзер: {$user} → {$homedir}");
    Notify::ftpAdded($user, $homedir);
    Api::ok(['user' => $user, 'homedir' => $homedir], "FTP пользователь {$user} создан");
}

// ── Удалить FTP пользователя ──
function ftpDelete(): void
{
    Api::onlyPost();
    Api::requireFields(['user']);
    $user = Api::input('user');
    if (!preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $user)) Api::error('Недопустимое имя');
    $result = Api::runScript('ftp_delete.sh', [$user]);
    if (!$result['ok']) Api::error('Ошибка удаления: ' . $result['output']);
    Logger::write('ftp', "Удалён FTP юзер: {$user}");
    Notify::ftpDeleted($user);
    Api::ok(null, "FTP пользователь {$user} удалён");
}

// ── Сменить пароль FTP ──
function ftpChangePass(): void
{
    Api::onlyPost();
    Api::requireFields(['user', 'pass']);
    $user = Api::input('user');
    $pass = Api::input('pass');
    if (!preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $user)) Api::error('Недопустимое имя');
    if (strlen($pass) < 8) Api::error('Пароль должен быть не менее 8 символов');
    $result = Api::runScript('ftp_change_pass.sh', [$user, $pass]);
    if (!$result['ok']) Api::error('Ошибка смены пароля: ' . $result['output']);
    Logger::write('ftp', "Сменён пароль FTP юзера: {$user}");
    Api::ok(null, "Пароль FTP пользователя {$user} изменён");
}

// ── Изменить путь FTP ──
function ftpChangePath(): void
{
    Api::onlyPost();
    Api::requireFields(['user']);
    $user    = Api::input('user');
    $homedir = rtrim(Api::input('homedir') ?: Api::input('path') ?: '', '/');
    if (!preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $user)) Api::error('Недопустимое имя');
    if (!str_starts_with($homedir, WEBROOT)) Api::error('Путь должен быть внутри ' . WEBROOT);
    $result = Api::runScript('ftp_change_path.sh', [$user, $homedir]);
    if (!$result['ok']) Api::error('Ошибка изменения пути: ' . $result['output']);
    Logger::write('ftp', "Изменён путь FTP юзера: {$user} → {$homedir}");
    Api::ok(null, "Путь FTP пользователя {$user} изменён");
}

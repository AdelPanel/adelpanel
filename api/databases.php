<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/databases.php
// ══════════════════════════════════════════════
require_once __DIR__ . '/../core/Api.php';
require_once __DIR__ . '/../core/Notify.php';
require_once __DIR__ . '/../core/DB.php';

Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? '';

match ($action) {
    'list'           => listAll(),
    'list_dbs'       => listDbs(),
    'list_users'     => listUsers(),
    'create_db'      => createDb(),
    'delete_db'      => deleteDb(),
    'create_user'    => createUser(),
    'delete_user'    => deleteUser(),
    'change_pass'    => changeUserPass(),
    'set_grants'     => setGrants(),
    'add_user_to_db' => addUserToDb(),
    default          => Api::error("Unknown action: {$action}"),
};

function listAll(): void
{
    Api::onlyGet();
    $dbs   = [];
    $users = [];
    try {
        // ── Базы данных ──
        $rawDbs = DB::mysql()->query(
            "SELECT s.SCHEMA_NAME as name,
                    ROUND(SUM(t.data_length + t.index_length) / 1024 / 1024, 2) as size_mb,
                    s.DEFAULT_CHARACTER_SET_NAME as charset
             FROM information_schema.SCHEMATA s
             LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
             WHERE s.SCHEMA_NAME NOT IN ('information_schema','performance_schema','mysql','sys')
             GROUP BY s.SCHEMA_NAME, s.DEFAULT_CHARACTER_SET_NAME
             ORDER BY s.SCHEMA_NAME"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawDbs as $db) {
            $sizeMb     = (float)($db['size_mb'] ?? 0);
            $db['size'] = $sizeMb >= 1 ? number_format($sizeMb, 1) . ' MB' : '< 1 MB';
            $uStmt = DB::mysql()->prepare(
                "SELECT DISTINCT User FROM mysql.db WHERE Db = ? AND Host = 'localhost'"
            );
            $uStmt->execute([$db['name']]);
            $db['users'] = array_column($uStmt->fetchAll(PDO::FETCH_ASSOC), 'User');
            $dbs[] = $db;
        }

        // ── Пользователи ──
        $rawUsers = DB::mysql()->query(
            "SELECT User as name, Host as host FROM mysql.user
             WHERE User != '' AND User NOT IN ('mysql.sys','mysql.infoschema','mysql.session','root')
             ORDER BY User"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawUsers as $u) {
            $dbStmt = DB::mysql()->prepare(
                "SELECT Db FROM mysql.db WHERE User = ? AND Host = ?
                 AND Db NOT IN ('information_schema','performance_schema','mysql','sys')"
            );
            $dbStmt->execute([$u['name'], $u['host']]);
            $u['databases'] = array_column($dbStmt->fetchAll(PDO::FETCH_ASSOC), 'Db');
            $u['grants']    = count($u['databases']) ? implode(', ', $u['databases']) : 'нет';
            $users[] = $u;
        }
    } catch (\Throwable $e) {
        Api::ok(['databases' => [], 'users' => [], 'error' => $e->getMessage()]);
        return;
    }
    Api::ok(['databases' => $dbs, 'users' => $users]);
}

function listDbs(): void
{
    Api::onlyGet();
    try {
        $dbs = DB::mysql()->query(
            "SELECT s.SCHEMA_NAME as name,
                    ROUND(SUM(t.data_length + t.index_length) / 1024 / 1024, 2) as size_mb,
                    s.DEFAULT_CHARACTER_SET_NAME as charset
             FROM information_schema.SCHEMATA s
             LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
             WHERE s.SCHEMA_NAME NOT IN ('information_schema','performance_schema','mysql','sys')
             GROUP BY s.SCHEMA_NAME, s.DEFAULT_CHARACTER_SET_NAME
             ORDER BY s.SCHEMA_NAME"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dbs as &$db) {
            $stmt = DB::mysql()->prepare(
                "SELECT DISTINCT User FROM mysql.db WHERE Db = ? AND Host = 'localhost'"
            );
            $stmt->execute([$db['name']]);
            $db['users'] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'User');
        }
    } catch (\Throwable $e) {
        Api::ok([], 'MySQL недоступен: ' . $e->getMessage());
        return;
    }
    Api::ok($dbs);
}

function listUsers(): void
{
    Api::onlyGet();
    $users = DB::mysql()->query(
        "SELECT u.User as name, u.Host as host,
                GROUP_CONCAT(DISTINCT d.Db) as databases,
                CASE WHEN u.Super_priv='Y' THEN 'ALL' ELSE 'LIMITED' END as level
         FROM mysql.user u
         LEFT JOIN mysql.db d ON d.User = u.User AND d.Host = u.Host
         WHERE u.User NOT IN ('root','mysql.sys','mysql.infoschema','mysql.session')
           AND u.Host = 'localhost'
         GROUP BY u.User, u.Host, u.Super_priv
         ORDER BY u.User"
    )->fetchAll(PDO::FETCH_ASSOC);
    Api::ok($users);
}

function createDb(): void
{
    Api::onlyPost();
    Api::requireFields(['name']);
    $name    = Api::input('name');
    $charset = Api::input('charset', 'utf8mb4');
    $collate = Api::input('collate', 'utf8mb4_unicode_ci');

    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) Api::error('Недопустимое имя БД');
    if (!in_array($charset, ['utf8mb4', 'utf8', 'latin1'], true)) Api::error('Недопустимая кодировка');

    try {
        $exists = DB::mysql()->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $exists->execute([$name]);
        if ($exists->fetch()) Api::error("БД '{$name}' уже существует");
        DB::mysql()->exec("CREATE DATABASE `{$name}` CHARACTER SET {$charset} COLLATE {$collate}");
    } catch (\Throwable $e) {
        Api::error('Ошибка MySQL: ' . $e->getMessage());
    }

    Logger::write('db', "Создана БД: {$name}");
    Notify::dbAdded($name);
    Api::ok(['name' => $name], "База данных {$name} создана");
}

function deleteDb(): void
{
    Api::onlyPost();
    Api::requireFields(['name', 'confirm']);
    $name    = Api::input('name');
    $confirm = Api::input('confirm');
    if ($name !== $confirm) Api::error('Подтверждение не совпадает');
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) Api::error('Недопустимое имя БД');
    DB::mysql()->exec("DROP DATABASE IF EXISTS `{$name}`");
    Logger::write('db', "Удалена БД: {$name}");
    Notify::dbDeleted($name);
    Api::ok(null, "База данных {$name} удалена");
}

function createUser(): void
{
    Api::onlyPost();
    Api::requireFields(['user', 'pass']);
    $user = Api::input('user');
    $pass = Api::input('pass');
    $host = Api::input('host', 'localhost');

    if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $user)) Api::error('Недопустимое имя пользователя');
    if (!in_array($host, ['localhost', '127.0.0.1', '%'], true)) Api::error('Недопустимый хост');
    if (strlen($pass) < 8) Api::error('Пароль должен быть не менее 8 символов');

    $exists = DB::mysql()->prepare("SELECT User FROM mysql.user WHERE User = ? AND Host = ?");
    $exists->execute([$user, $host]);
    if ($exists->fetch()) Api::error("Пользователь '{$user}'@'{$host}' уже существует");

    try {
        // Используем backtick-quoted идентификаторы, не placeholder для USER@HOST
        $safeUser = DB::mysql()->quote($user);
        $safeHost = DB::mysql()->quote($host);
        $safePass = DB::mysql()->quote($pass);
        DB::mysql()->exec("CREATE USER {$safeUser}@{$safeHost} IDENTIFIED BY {$safePass}");
        DB::mysql()->exec("FLUSH PRIVILEGES");
    } catch (\Throwable $e) {
        Api::error('Ошибка MySQL: ' . $e->getMessage());
    }

    Logger::write('db', "Создан MySQL юзер: {$user}@{$host}");
    Api::ok(['user' => $user, 'host' => $host], "Пользователь {$user} создан");
}

function deleteUser(): void
{
    Api::onlyPost();
    Api::requireFields(['user']);
    $user = Api::input('user');
    $host = Api::input('host', 'localhost');
    if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $user)) Api::error('Недопустимое имя пользователя');
    $safeUser = DB::mysql()->quote($user);
    $safeHost = DB::mysql()->quote($host);
    DB::mysql()->exec("DROP USER IF EXISTS {$safeUser}@{$safeHost}");
    DB::mysql()->exec("FLUSH PRIVILEGES");
    Logger::write('db', "Удалён MySQL юзер: {$user}@{$host}");
    Api::ok(null, "Пользователь {$user} удалён");
}

function changeUserPass(): void
{
    Api::onlyPost();
    Api::requireFields(['user', 'pass']);
    $user = Api::input('user');
    $pass = Api::input('pass');
    $host = Api::input('host', 'localhost');
    if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $user)) Api::error('Недопустимое имя пользователя');
    if (strlen($pass) < 8) Api::error('Пароль должен быть не менее 8 символов');
    $safeUser = DB::mysql()->quote($user);
    $safeHost = DB::mysql()->quote($host);
    $safePass = DB::mysql()->quote($pass);
    DB::mysql()->exec("ALTER USER {$safeUser}@{$safeHost} IDENTIFIED BY {$safePass}");
    DB::mysql()->exec("FLUSH PRIVILEGES");
    Logger::write('db', "Сменён пароль MySQL юзера: {$user}@{$host}");
    Api::ok(null, "Пароль пользователя {$user} изменён");
}

function setGrants(): void
{
    Api::onlyPost();
    Api::requireFields(['user', 'db', 'grants']);
    $user   = Api::input('user');
    $db     = Api::input('db');
    $grants = Api::input('grants');
    $host   = Api::input('host', 'localhost');

    if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $user)) Api::error('Недопустимый юзер');
    if (!preg_match('/^[a-zA-Z0-9_*]{1,64}$/', $db))   Api::error('Недопустимое имя БД');

    $allowed = ['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER',
                'INDEX','REFERENCES','CREATE VIEW','SHOW VIEW','TRIGGER','LOCK TABLES','EXECUTE'];

    if ($grants === 'ALL') {
        $grantsStr = 'ALL PRIVILEGES';
    } else {
        if (!is_array($grants) || empty($grants)) Api::error('Укажите права');
        $safe = array_filter($grants, fn($g) => in_array(strtoupper($g), $allowed, true));
        if (empty($safe)) Api::error('Нет допустимых прав');
        $grantsStr = implode(', ', array_map('strtoupper', $safe));
    }

    $safeUser = DB::mysql()->quote($user);
    $safeHost = DB::mysql()->quote($host);
    DB::mysql()->exec("REVOKE ALL PRIVILEGES ON `{$db}`.* FROM {$safeUser}@{$safeHost}");
    DB::mysql()->exec("GRANT {$grantsStr} ON `{$db}`.* TO {$safeUser}@{$safeHost}");
    DB::mysql()->exec("FLUSH PRIVILEGES");
    Logger::write('db', "Права {$grantsStr} → {$user}@{$host} на {$db}");
    Api::ok(null, "Права обновлены");
}

function addUserToDb(): void
{
    Api::onlyPost();
    Api::requireFields(['user', 'db']);
    $user  = Api::input('user');
    $db    = Api::input('db');
    $level = Api::input('level', 'rw');
    $host  = Api::input('host', 'localhost');

    if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $user)) Api::error('Недопустимый юзер');
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $db))   Api::error('Недопустимое имя БД');

    $grantsMap = [
        'all' => 'ALL PRIVILEGES',
        'rw'  => 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP',
        'r'   => 'SELECT',
    ];

    $grantsStr = $grantsMap[$level] ?? 'SELECT, INSERT, UPDATE, DELETE';
    $safeUser = DB::mysql()->quote($user);
    $safeHost = DB::mysql()->quote($host);
    DB::mysql()->exec("GRANT {$grantsStr} ON `{$db}`.* TO {$safeUser}@{$safeHost}");
    DB::mysql()->exec("FLUSH PRIVILEGES");
    Logger::write('db', "Добавлен {$user}@{$host} на {$db} с правами {$grantsStr}");
    Api::ok(null, "Пользователь {$user} добавлен на базу {$db}");
}

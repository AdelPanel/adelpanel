<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/backup.php
// ══════════════════════════════════════════════
require_once __DIR__ . '/../core/Api.php';

Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'list';

match($action) {
    'list'         => backupList(),
    'create_site'  => backupCreateSite(),
    'create_db'    => backupCreateDb(),
    'create_full'  => backupCreateFull(),
    'restore_site' => backupRestoreSite(),
    'restore_db'   => backupRestoreDb(),
    'delete'       => backupDelete(),
    'download'     => backupDownload(),
    'upload_sql'   => backupUploadSql(),
    default        => Api::error("Unknown action"),
};

// ── Безопасная проверка пути бэкапа ──────────────────────────────
function safeBackupPath(string $file, string $type = ''): string
{
    $base = '/opt/backups';
    // Нормализуем: basename + тип директории
    $name = basename($file);
    $path = $type ? "{$base}/{$type}/{$name}" : "{$base}/{$name}";
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, $base)) {
        Api::error('Недопустимый путь к файлу бэкапа');
    }
    return $real;
}

// ── Список ───────────────────────────────────────────────────────
function backupList(): void {
    Api::onlyGet();
    $result = ['sites' => [], 'databases' => [], 'full' => []];

    foreach (['sites', 'databases', 'full'] as $type) {
        $dir = "/opt/backups/{$type}";
        if (!is_dir($dir)) continue;
        foreach (glob("{$dir}/*.{tar.gz,sql.gz}", GLOB_BRACE) as $f) {
            $result[$type][] = [
                'name'    => basename($f),   // panel.html uses b.name
                'file'    => basename($f),
                'path'    => $f,
                'size_mb' => round(filesize($f) / 1024 / 1024, 2),
                'size'    => formatBytes(filesize($f)),
                'date'    => date('d.m.Y H:i', filemtime($f)),
                'ts'      => filemtime($f),
            ];
        }
        usort($result[$type], fn($a,$b) => $b['ts'] - $a['ts']);
    }
    Api::ok($result);
}

function formatBytes(int $bytes): string {
    if ($bytes > 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes > 1048576)    return round($bytes / 1048576, 2) . ' MB';
    return round($bytes / 1024) . ' KB';
}

// ── Создание ─────────────────────────────────────────────────────
function backupCreateSite(): void {
    Api::onlyPost();
    Api::requireFields(['domain']);
    $domain = Api::input('domain');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]+$/', $domain)) Api::error('Недопустимый домен');
    $result = Api::runScript('backup_one_site.sh', [$domain]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('backup', "Бэкап сайта: {$domain}");
    Api::ok(null, "Бэкап сайта {$domain} создан");
}

function backupCreateDb(): void {
    Api::onlyPost();
    Api::requireFields(['db']);
    $db = Api::input('db');
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $db)) Api::error('Недопустимое имя БД');
    $result = Api::runScript('backup_one_db.sh', [$db]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('backup', "Бэкап БД: {$db}");
    Api::ok(null, "Бэкап базы {$db} создан");
}

function backupCreateFull(): void {
    Api::onlyPost();
    $result = Api::runScript('backup_full.sh', []);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('backup', "Полный бэкап запущен");
    Api::ok(null, "Полный бэкап создан");
}

// ── Восстановление ────────────────────────────────────────────────
function backupRestoreSite(): void {
    Api::onlyPost();
    Api::requireFields(['file', 'domain']);
    $file   = safeBackupPath(Api::input('file'), 'sites');
    $domain = Api::input('domain');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]+$/', $domain)) Api::error('Недопустимый домен');
    $result = Api::runScript('backup_restore_site.sh', [$file, $domain]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('backup', "Восстановлен сайт {$domain} из {$file}");
    Api::ok(null, "Сайт {$domain} восстановлен");
}

function backupRestoreDb(): void {
    Api::onlyPost();
    Api::requireFields(['file', 'db']);
    $file = safeBackupPath(Api::input('file'), 'databases');
    $db   = Api::input('db');
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $db)) Api::error('Недопустимое имя БД');
    $result = Api::runScript('backup_restore_db.sh', [$file, $db]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('backup', "Восстановлена БД {$db} из {$file}");
    Api::ok(null, "БД {$db} восстановлена");
}

// ── Удаление ─────────────────────────────────────────────────────
function backupDelete(): void {
    Api::onlyPost();
    Api::requireFields(['file', 'type']);
    $type = Api::input('type'); // sites | databases | full
    if (!in_array($type, ['sites', 'databases', 'full'], true)) Api::error('Недопустимый тип');
    $path = safeBackupPath(Api::input('file'), $type);
    unlink($path);
    Logger::write('backup', "Удалён бэкап: {$path}");
    Api::ok(null, 'Бэкап удалён');
}

// ── Скачать бэкап ─────────────────────────────────────────────────
function backupDownload(): void
{
    // GET-запрос с токеном авторизации (сессия уже проверена в Api::init())
    $file = $_GET['file'] ?? '';
    $type = $_GET['type'] ?? '';

    if (!in_array($type, ['sites', 'databases', 'full'], true)) Api::error('Недопустимый тип');

    $path = safeBackupPath($file, $type);

    if (!file_exists($path)) Api::error('Файл не найден', 404);

    $name = basename($path);
    $size = filesize($path);

    // Отменяем JSON-заголовок из Api::init()
    header_remove('Content-Type');

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    // Отдаём файл чанками (экономим память)
    $fp = fopen($path, 'rb');
    while (!feof($fp)) {
        echo fread($fp, 65536);
        flush();
    }
    fclose($fp);

    Logger::write('backup', "Скачан бэкап: {$name}");
    exit;
}

// ── Загрузить SQL дамп ────────────────────────────────────────────
function backupUploadSql(): void
{
    Api::onlyPost();
    Api::requireFields(['db']);

    $db = Api::input('db');
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $db)) Api::error('Недопустимое имя БД');

    // Проверяем загруженный файл
    if (empty($_FILES['sqlfile'])) Api::error('Файл не загружен');

    $upload = $_FILES['sqlfile'];

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $errors = [1=>'Файл превышает upload_max_filesize', 2=>'Файл превышает MAX_FILE_SIZE',
                   3=>'Загружена только часть файла', 4=>'Файл не загружен'];
        Api::error($errors[$upload['error']] ?? 'Ошибка загрузки');
    }

    // Проверяем расширение и MIME (двойная проверка)
    $originalName = $upload['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts = ['sql', 'gz'];

    if (!in_array($ext, $allowedExts, true)) {
        Api::error('Допустимы только файлы .sql и .sql.gz');
    }

    // Проверяем что это текстовый SQL или gzip
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $upload['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['text/plain', 'application/sql', 'application/x-sql',
                     'application/gzip', 'application/x-gzip', 'application/octet-stream'];
    if (!in_array($mime, $allowedMimes, true)) {
        Api::error("Недопустимый тип файла: {$mime}");
    }

    // Ограничение размера: 500 MB
    if ($upload['size'] > 524288000) {
        Api::error('Файл слишком большой (максимум 500 MB)');
    }

    // Сохраняем во временную директорию панели (не в /tmp)
    $tmpDir  = ADELPANEL_ROOT . '/tmp';
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0750, true);

    $tmpFile = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($upload['tmp_name'], $tmpFile)) {
        Api::error('Не удалось сохранить файл');
    }
    chmod($tmpFile, 0640);

    // Восстанавливаем через скрипт
    $result = Api::runScript('backup_restore_db.sh', [$tmpFile, $db]);
    unlink($tmpFile); // сразу удаляем временный файл

    if (!$result['ok']) Api::error('Ошибка импорта: ' . $result['output']);

    Logger::write('backup', "Импортирован SQL дамп в БД {$db} ({$originalName})");
    Api::ok(null, "Дамп успешно импортирован в базу {$db}");
}

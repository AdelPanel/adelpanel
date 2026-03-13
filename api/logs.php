<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/logs.php
//  Чтение системных логов и логов сайтов
// ══════════════════════════════════════════════

require_once __DIR__ . '/../core/Api.php';

Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'read';

match ($action) {
    'read'  => readLog(),
    'view'  => readLog(),   // alias for read
    'panel' => panelLog(),  // dashboard: последние записи панельного лога
    'clear' => clearLog(),
    'list'  => listLogs(),
    default => Api::error("Unknown action: {$action}"),
};

// ── Карта допустимых логов ──────────────────
function getLogMap(): array
{
    $sites = [];
    $allConfs = array_merge(
        glob(NGINX_CONF_D . '/*.conf') ?: [],
        glob(NGINX_SITES_AVAILABLE . '/*.conf') ?: []
    );
    foreach ($allConfs as $conf) {
        $domain = basename($conf, '.conf');
        $sites[$domain . ':access'] = "/var/log/nginx/{$domain}.access.log";
        $sites[$domain . ':error']  = "/var/log/nginx/{$domain}.error.log";
    }

    return array_merge($sites, [
        'nginx:access' => '/var/log/nginx/access.log',
        'nginx:error'  => '/var/log/nginx/error.log',
        'php:fpm'      => '/var/log/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm.log',
        'ftp:main'     => '/var/log/pure-ftpd/transfer.log',
        'ftp:auth'     => '/var/log/auth.log',
        'system:syslog'=> '/var/log/syslog',
        'system:auth'  => '/var/log/auth.log',
        'panel:main'   => PANEL_LOG,
    ]);
}

// ── Список доступных логов ──
function listLogs(): void
{
    Api::onlyGet();
    $map = getLogMap();

    $result = [];
    foreach ($map as $key => $path) {
        $result[] = [
            'key'    => $key,
            'path'   => $path,
            'exists' => file_exists($path),
            'size'   => file_exists($path) ? round(filesize($path) / 1024, 1) . ' KB' : '0 KB',
        ];
    }

    Api::ok($result);
}

// ── Читать лог ──
function readLog(): void
{
    Api::onlyGet();

    $key   = $_GET['key'] ?? $_GET['type'] ?? '';
    $lines = (int)($_GET['lines'] ?? $_GET['limit'] ?? 200);
    $lines = min(max($lines, 10), 2000); // лимит 2000 строк

    $map = getLogMap();

    if (!isset($map[$key])) {
        Api::error('Недопустимый лог');
    }

    $path = $map[$key];

    if (!file_exists($path)) {
        Api::ok(['lines' => [], 'file' => $path, 'message' => 'Файл не найден']);
        return;
    }

    if (!is_readable($path)) {
        Api::error('Нет доступа к файлу лога');
    }

    // Читаем последние N строк через tail (эффективно для больших файлов)
    $raw = shell_exec('tail -n ' . $lines . ' ' . escapeshellarg($path) . ' 2>/dev/null');

    // Парсим строки в структурированный вид
    $parsed = [];
    foreach (explode("\n", trim((string)$raw)) as $line) {
        if (empty($line)) continue;
        $parsed[] = parseLine($line, $key);
    }

    Api::ok([
        'file'  => $path,
        'lines' => array_reverse($parsed), // новые сначала
        'total' => count($parsed),
    ]);
}

// ── Определить тип строки лога ──
function parseLine(string $line, string $key): array
{
    $level = 'info';

    if (str_contains(strtolower($line), 'error') || str_contains($line, ' 5')) {
        $level = 'error';
    } elseif (str_contains(strtolower($line), 'warn') || str_contains($line, ' 4')) {
        $level = 'warn';
    } elseif (str_contains($line, ' 200') || str_contains($line, ' 304')) {
        $level = 'ok';
    }

    // Извлекаем timestamp если есть
    $timestamp = null;
    if (preg_match('/(\d{4}[-\/]\d{2}[-\/]\d{2}[\sT]\d{2}:\d{2}:\d{2})/', $line, $m)) {
        $timestamp = $m[1];
    } elseif (preg_match('/\[(\w+ \w+ \d+ \d+:\d+:\d+ \d+)\]/', $line, $m)) {
        $timestamp = $m[1];
    }

    return [
        'raw'       => $line,
        'level'     => $level,
        'timestamp' => $timestamp,
    ];
}

// ── Очистить лог ──
function clearLog(): void
{
    Api::onlyPost();

    $key = Api::input('key') ?? Api::input('type') ?? '';
    $map = getLogMap();

    if (!isset($map[$key])) {
        Api::error('Недопустимый лог');
    }

    // Нельзя очищать системные логи
    $protected = ['system:syslog', 'system:auth', 'ftp:auth'];
    if (in_array($key, $protected, true)) {
        Api::error('Этот лог очищать нельзя');
    }

    $path = $map[$key];

    if (!file_exists($path)) {
        Api::ok(null, 'Файл не существует');
        return;
    }

    file_put_contents($path, '');

    Logger::write('logs', "Очищен лог: {$path}");
    Api::ok(null, 'Лог очищен');
}

// ── Последние записи лога панели (для дашборда) ──
function panelLog(): void
{
    Api::onlyGet();
    $limit = min((int)($_GET['limit'] ?? 5), 50);
    $path  = PANEL_LOG;

    if (!file_exists($path)) {
        Api::ok(['lines' => []]);
        return;
    }

    $raw  = shell_exec('tail -n ' . $limit . ' ' . escapeshellarg($path) . ' 2>/dev/null');
    $lines = [];
    foreach (array_reverse(explode("\n", trim((string)$raw))) as $line) {
        if (empty($line)) continue;
        $lines[] = parseLine($line, 'panel:main');
    }
    Api::ok(['lines' => $lines]);
}

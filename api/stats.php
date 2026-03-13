<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/stats.php
// ══════════════════════════════════════════════

require_once __DIR__ . '/../core/Api.php';

Api::init();
Api::onlyGet();

$action = $_GET['action'] ?? 'all';
// Все action ведут к одному и тому же — полной статистике
// Оставлено для расширения (можно запрашивать только cpu/ram/disk)

// Собираем все данные для дашборда в одном запросе
$cpu    = getCpu();
$ram    = getRam();
$disk   = getDisk();
$uptime = getUptime();

Api::ok([
    // CPU
    'cpu'          => $cpu['percent'],
    'cpu_info'     => $cpu['cores'] . ' ядро(ядер) · ' . $cpu['freq'],
    // RAM
    'ram_used'     => $ram['used_mb'],
    'ram_total'    => $ram['total_mb'],
    'ram_pct'      => $ram['percent'],
    // Disk
    'disk_used'    => (int)($disk['used_gb'] * 1024),   // в MB для единообразия
    'disk_total'   => (int)($disk['total_gb'] * 1024),
    'disk_pct'     => $disk['percent'],
    // Uptime
    'uptime'       => $uptime['human'],
    'uptime_short' => $uptime['days'] > 0
        ? $uptime['days'] . 'д ' . $uptime['hours'] . 'ч'
        : ($uptime['hours'] > 0
            ? $uptime['hours'] . 'ч ' . (isset($uptime['minutes']) ? $uptime['minutes'] . 'м' : '')
            : (isset($uptime['minutes']) ? $uptime['minutes'] . 'м' : '< 1м')),
    // Versions
    'nginx_ver'    => getNginxVersion(),
    'php_ver'      => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    // Server info
    'hostname'     => gethostname() ?: 'server',
    'server_ip'    => getServerIp(),
    'os'           => getOs(),
    // Counts
    'sites_count'  => getSitesCount(),
    'db_count'     => getDbCount(),
]);

// ── CPU (из /proc/stat через shell_exec — без open_basedir) ──
function getCpu(): array
{
    $s1 = parseStat();
    usleep(200000);
    $s2 = parseStat();

    $idle1  = ($s1['idle'] ?? 0) + ($s1['iowait'] ?? 0);
    $idle2  = ($s2['idle'] ?? 0) + ($s2['iowait'] ?? 0);
    $total1 = array_sum($s1);
    $total2 = array_sum($s2);

    $diffIdle  = $idle2 - $idle1;
    $diffTotal = $total2 - $total1;

    $percent = $diffTotal > 0 ? round((1 - $diffIdle / $diffTotal) * 100, 1) : 0.0;
    $cores   = (int)(trim((string)shell_exec('nproc 2>/dev/null')) ?: 1);
    $freq    = trim((string)shell_exec(
        "awk '/cpu MHz/{sum+=$4; n++} END{if(n>0) printf \"%.0f\", sum/n; else print \"?\"}' /proc/cpuinfo 2>/dev/null"
    ));

    return [
        'percent' => $percent,
        'cores'   => $cores,
        'freq'    => $freq ? $freq . ' MHz' : 'N/A',
    ];
}

function parseStat(): array
{
    // Читаем через shell_exec чтобы не зависеть от open_basedir
    $line = trim((string)shell_exec("head -1 /proc/stat 2>/dev/null"));
    if (!$line) return array_fill_keys(['user','nice','system','idle','iowait','irq','softirq','steal'], 0);
    $parts  = preg_split('/\s+/', $line);
    $fields = ['user','nice','system','idle','iowait','irq','softirq','steal'];
    $result = [];
    foreach ($fields as $i => $key) {
        $result[$key] = (int)($parts[$i + 1] ?? 0);
    }
    return $result;
}

// ── RAM (из /proc/meminfo через shell) ──
function getRam(): array
{
    $out = (string)shell_exec("grep -E '^(MemTotal|MemAvailable):' /proc/meminfo 2>/dev/null");
    $total = $available = 0;
    foreach (explode("\n", $out) as $line) {
        if (str_starts_with($line, 'MemTotal:'))     $total     = (int)preg_replace('/\D/', '', $line);
        if (str_starts_with($line, 'MemAvailable:')) $available = (int)preg_replace('/\D/', '', $line);
    }
    $used = $total - $available;
    return [
        'used_mb'  => (int)round($used / 1024),
        'total_mb' => (int)round($total / 1024),
        'used_gb'  => round($used / 1024 / 1024, 2),
        'total_gb' => round($total / 1024 / 1024, 2),
        'percent'  => $total > 0 ? round($used / $total * 100, 1) : 0,
    ];
}

// ── Диск (через df — без open_basedir на /) ──
function getDisk(): array
{
    $out = trim((string)shell_exec("df -BM / 2>/dev/null | tail -1"));
    if (!$out) return ['used_gb' => 0, 'total_gb' => 0, 'percent' => 0];
    $parts = preg_split('/\s+/', $out);
    // df -BM: Filesystem 1M-blocks Used Available Use% Mounted
    $total = (int)rtrim($parts[1] ?? '0', 'M');
    $used  = (int)rtrim($parts[2] ?? '0', 'M');
    $pct   = (int)rtrim($parts[4] ?? '0', '%');
    return [
        'used_gb'  => round($used / 1024, 1),
        'total_gb' => round($total / 1024, 1),
        'percent'  => $pct,
    ];
}

// ── Аптайм (из /proc/uptime через shell) ──
function getUptime(): array
{
    $out     = trim((string)shell_exec("cat /proc/uptime 2>/dev/null"));
    $seconds = $out ? (float)explode(' ', $out)[0] : 0;
    $days    = (int)floor($seconds / 86400);
    $hours   = (int)floor(($seconds % 86400) / 3600);
    $minutes = (int)floor(($seconds % 3600) / 60);
    return [
        'seconds' => (int)$seconds,
        'human'   => "{$days}д {$hours}ч {$minutes}м",
        'days'    => $days,
        'hours'   => $hours,
    ];
}

// ── Nginx версия ──
function getNginxVersion(): string
{
    preg_match('/nginx\/(\S+)/', (string)shell_exec('nginx -v 2>&1'), $m);
    return $m[1] ?? 'N/A';
}

// ── IP сервера из настроек ──
function getServerIp(): string
{
    try {
        require_once __DIR__ . '/../core/DB.php';
        return DB::setting('server_ip', '—');
    } catch (\Throwable) {
        return '—';
    }
}

// ── ОС ──
function getOs(): string
{
    $out = trim((string)shell_exec("lsb_release -sd 2>/dev/null || cat /etc/os-release | grep PRETTY_NAME | cut -d'=' -f2 | tr -d '\"'"));
    return $out ?: 'Linux';
}

// ── Количество сайтов ──
function getSitesCount(): int
{
    $out = trim((string)shell_exec("ls /etc/nginx/conf.d/ 2>/dev/null | grep -v 'adelpanel' | grep -c '.conf' 2>/dev/null"));
    return max(0, (int)$out);
}

// ── Количество БД ──
function getDbCount(): int
{
    try {
        // Используем mysql cli — не требует open_basedir на /root
        $out = shell_exec("mysql --defaults-file=/root/.adelpanel/mysql.conf -e \"SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema','mysql','performance_schema','sys')\" 2>/dev/null | tail -1");
        return (int)trim((string)$out);
    } catch (\Throwable) {
        return 0;
    }
}

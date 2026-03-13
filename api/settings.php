<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/settings.php
// ══════════════════════════════════════════════
require_once __DIR__ . '/../core/Api.php';
Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'get';

match ($action) {
    'get'              => getSettings(),
    'set_hostname'     => setHostname(),
    'set_timezone'     => setTimezone(),
    'set_dns'          => setDns(),
    'set_swap'         => setSwap(),
    'disable_swap'     => disableSwap(),
    'set_php'          => setPhpVersion(),
    'set_panel_port'   => setPanelPort(),
    'set_panel_pass'   => setPanelPass(),
    'run_update'       => runUpdate(),
    'service_restart'  => serviceRestart(),
    'service_reload'   => serviceReload(),
    'service_status'   => serviceStatus(),
    'reboot'           => rebootServer(),
    default            => Api::error("Unknown action: {$action}"),
};

function getSettings(): void
{
    Api::onlyGet();

    $hostname = trim((string) shell_exec('hostname 2>/dev/null'));
    $timezone = trim((string) shell_exec('cat /etc/timezone 2>/dev/null')) ?: date_default_timezone_get();

    $dns = [];
    if (file_exists('/etc/resolv.conf')) {
        foreach (file('/etc/resolv.conf') as $line) {
            if (preg_match('/^nameserver\s+(\S+)/', trim($line), $m)) $dns[] = $m[1];
        }
    }

    $swapInfo = shell_exec('free -m | grep Swap 2>/dev/null');
    preg_match('/Swap:\s+(\d+)\s+(\d+)\s+(\d+)/', (string)$swapInfo, $sm);
    $swapTotal = (int)($sm[1] ?? 0);

    // PHP версии
    $installedPhp = [];
    foreach (PHP_VERSIONS as $ver) {
        if (is_executable("/usr/bin/php{$ver}")) {
            $fpmSt = trim((string) shell_exec("systemctl is-active php{$ver}-fpm 2>/dev/null")) ?: 'stopped';
            $installedPhp[] = [
                'version'    => $ver,
                'fpm_status' => $fpmSt,
                'fpm_active' => $fpmSt === 'active',
                'is_current' => str_starts_with(phpversion(), $ver),
            ];
        }
    }

    // Статус сервисов
    $services = [];
    foreach (['nginx', 'mysql', 'pure-ftpd'] as $svc) {
        $st = trim((string) shell_exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
        $services[$svc] = $st;
    }

    Api::ok([
        'hostname'    => $hostname,
        'timezone'    => $timezone,
        'dns'         => implode(', ', $dns),
        // swap - в двух форматах для совместимости
        'swap_active' => $swapTotal > 0,
        'swap_size'   => $swapTotal,
        'swap'        => ['enabled' => $swapTotal > 0, 'total_mb' => $swapTotal],
        // php
        'php'         => $installedPhp,
        'php_current' => phpversion(),
        'panel_port'  => PANEL_PORT,
        'os'          => trim((string) shell_exec('lsb_release -ds 2>/dev/null') ?: 'Linux'),
        'kernel'      => trim((string) shell_exec('uname -r 2>/dev/null')),
        'services'    => $services,
    ]);
}

function serviceRestart(): void
{
    Api::onlyPost();
    Api::requireFields(['service']);
    $svc = Api::input('service');
    $allowed = ['nginx', 'mysql', 'pure-ftpd', 'php7.4-fpm', 'php8.0-fpm',
                'php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm', 'php8.4-fpm'];
    if (!in_array($svc, $allowed, true)) Api::error('Недопустимый сервис');
    $out  = shell_exec("sudo systemctl restart " . escapeshellarg($svc) . " 2>&1");
    $st   = trim((string) shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null"));
    Logger::write('settings', "Перезапуск сервиса: {$svc} → {$st}");
    Api::ok(['status' => $st, 'output' => $out], "Сервис {$svc} перезапущен");
}

function serviceReload(): void
{
    Api::onlyPost();
    Api::requireFields(['service']);
    $svc = Api::input('service');
    $allowed = ['nginx', 'mysql', 'pure-ftpd'];
    if (!in_array($svc, $allowed, true)) Api::error('Недопустимый сервис');
    $out = shell_exec("sudo systemctl reload " . escapeshellarg($svc) . " 2>&1");
    $st  = trim((string) shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null"));
    Logger::write('settings', "Перезагрузка конфига: {$svc}");
    Api::ok(['status' => $st], "Конфиг {$svc} перезагружен");
}

function serviceStatus(): void
{
    Api::onlyGet();
    $services = [];
    foreach (['nginx', 'mysql', 'pure-ftpd'] as $svc) {
        $st = trim((string) shell_exec("systemctl is-active {$svc} 2>/dev/null")) ?: 'inactive';
        $services[$svc] = $st;
    }
    Api::ok($services);
}

function setHostname(): void
{
    Api::onlyPost(); Api::requireFields(['hostname']);
    $hostname = Api::input('hostname');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{0,62}$/', $hostname)) Api::error('Недопустимый hostname');
    $result = Api::runScript('set_hostname.sh', [$hostname]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('settings', "Изменён hostname: {$hostname}");
    Api::ok(null, "Hostname изменён на {$hostname}");
}

function setTimezone(): void
{
    Api::onlyPost(); Api::requireFields(['timezone']);
    $tz = Api::input('timezone');
    if (!in_array($tz, timezone_identifiers_list(), true)) Api::error('Недопустимый часовой пояс');
    $result = Api::runScript('set_timezone.sh', [$tz]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('settings', "Изменён timezone: {$tz}");
    Api::ok(null, "Часовой пояс изменён на {$tz}");
}

function setDns(): void
{
    Api::onlyPost(); Api::requireFields(['dns']);
    $dnsRaw  = Api::input('dns');
    $servers = array_filter(array_map('trim', explode(',', $dnsRaw)));
    foreach ($servers as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) Api::error("Недопустимый IP: {$ip}");
    }
    if (count($servers) > 3) Api::error('Максимум 3 DNS-сервера');
    $result = Api::runScript('set_dns.sh', $servers);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('settings', "Изменён DNS: " . implode(', ', $servers));
    Api::ok(null, 'DNS-серверы обновлены');
}

function setSwap(): void
{
    Api::onlyPost(); Api::requireFields(['size_mb']);
    $sizeMb  = (int) Api::input('size_mb');
    $allowed = [512, 1024, 2048, 4096];
    if (!in_array($sizeMb, $allowed, true)) Api::error('Допустимые размеры: ' . implode(', ', $allowed) . ' MB');
    $result = Api::runScript('set_swap.sh', [(string)$sizeMb]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('settings', "Своп установлен: {$sizeMb}MB");
    Api::ok(null, "Своп {$sizeMb} MB установлен");
}

function disableSwap(): void
{
    Api::onlyPost();
    $result = Api::runScript('disable_swap.sh', []);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('settings', "Своп отключён");
    Api::ok(null, 'Своп отключён');
}

function setPhpVersion(): void
{
    Api::onlyPost(); Api::requireFields(['version']);
    $ver = Api::input('version');
    if (!in_array($ver, PHP_VERSIONS, true)) Api::error('Недопустимая версия PHP');
    $result = Api::runScript('set_php_version.sh', [$ver]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('settings', "Сменена версия PHP: {$ver}");
    Api::ok(null, "PHP переключён на {$ver}");
}

function setPanelPort(): void
{
    Api::onlyPost(); Api::requireFields(['port']);
    $port = (int) Api::input('port');
    if ($port < 1024 || $port > 65535) Api::error('Порт должен быть от 1024 до 65535');
    $result = Api::runScript('set_panel_port.sh', [(string)$port]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);
    Logger::write('settings', "Изменён порт панели: {$port}");
    Api::ok(['port' => $port], "Порт панели изменён на {$port}. Переподключитесь.");
}

function setPanelPass(): void
{
    Api::onlyPost(); Api::requireFields(['pass']);
    $pass = Api::input('pass');
    if (strlen($pass) < 8) Api::error('Пароль должен быть не менее 8 символов');
    $user = Auth::user();
    if (!$user) Api::error('Не авторизован');
    $hash = Auth::hashPassword($pass);
    Auth::saveCredentials($user, $hash);
    Logger::write('pass_change', "Сменён пароль: {$user}");
    Api::ok(null, 'Пароль изменён');
}

function runUpdate(): void
{
    Api::onlyPost();
    $component = Api::input('component', 'all');
    if (!in_array($component, ['all', 'nginx', 'php', 'mysql', 'mariadb', 'certbot'], true)) {
        Api::error('Недопустимый компонент');
    }
    $result = Api::runScript('run_update.sh', [$component]);
    Logger::write('settings', "Обновление: {$component}");
    Api::ok(['output' => $result['output']], 'Обновление выполнено');
}

function rebootServer(): void
{
    Api::onlyPost(); Api::requireFields(['confirm']);
    if (Api::input('confirm') !== 'reboot') Api::error('Неверное подтверждение');
    Logger::write('settings', "ПЕРЕЗАГРУЗКА сервера: " . Auth::user());
    Api::ok(null, 'Сервер перезагружается...');
    shell_exec('sudo /sbin/shutdown -r now > /dev/null 2>&1 &');
}

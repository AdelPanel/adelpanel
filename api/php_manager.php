<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/php_manager.php
//  Установка/удаление версий PHP
// ══════════════════════════════════════════════
require_once __DIR__ . '/../core/Api.php';
Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'list';
match($action) {
    'list'    => phpList(),
    'install' => phpInstall(),
    'remove'  => phpRemove(),
    'restart' => phpRestart(),
    default   => Api::error("Unknown action"),
};

function phpList(): void {
    Api::onlyGet();
    $versions = [];
    foreach (PHP_VERSIONS as $ver) {
        $bin       = "/usr/bin/php{$ver}";
        $installed = is_executable($bin);
        $fpmSocket = "/run/php/php{$ver}-fpm.sock";
        $fpmStatus = 'stopped';
        if ($installed) {
            $st = trim((string) shell_exec("systemctl is-active php{$ver}-fpm 2>/dev/null"));
            $fpmStatus = $st ?: 'stopped';
        }
        $versions[] = [
            'version'    => $ver,
            'installed'  => $installed,
            'fpm_status' => $fpmStatus,
            'fpm_active' => $fpmStatus === 'active',
            'is_current' => str_starts_with(phpversion(), $ver),
        ];
    }
    Api::ok($versions);
}

function phpInstall(): void {
    Api::onlyPost();
    Api::requireFields(['version']);
    $ver = Api::input('version');
    if (!in_array($ver, PHP_VERSIONS, true)) Api::error('Недопустимая версия PHP');

    $result = Api::runScript('install_php.sh', [$ver]);
    if (!$result['ok']) Api::error('Ошибка установки: ' . $result['output']);

    Logger::write('php', "Установлен PHP {$ver}");
    Api::ok(null, "PHP {$ver} установлен");
}

function phpRemove(): void {
    Api::onlyPost();
    Api::requireFields(['version']);
    $ver = Api::input('version');
    if (!in_array($ver, PHP_VERSIONS, true)) Api::error('Недопустимая версия PHP');

    // Нельзя удалить текущую дефолтную версию панели
    if (str_starts_with(phpversion(), $ver)) {
        Api::error('Нельзя удалить версию PHP, используемую панелью');
    }

    $result = Api::runScript('remove_php.sh', [$ver]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);

    Logger::write('php', "Удалён PHP {$ver}");
    Api::ok(null, "PHP {$ver} удалён");
}

function phpRestart(): void {
    Api::onlyPost();
    Api::requireFields(['version']);
    $ver = Api::input('version');
    if (!in_array($ver, PHP_VERSIONS, true)) Api::error('Недопустимая версия PHP');

    shell_exec("systemctl restart php{$ver}-fpm 2>/dev/null");
    Logger::write('php', "Перезапущен php{$ver}-fpm");
    Api::ok(null, "PHP {$ver}-fpm перезапущен");
}

<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/backup_settings.php
//  Настройки бэкапов: периоды хранения, расписание
// ══════════════════════════════════════════════
require_once __DIR__ . '/../core/Api.php';
Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'get';

match($action) {
    'get'  => getBackupSettings(),
    'save' => saveBackupSettings(),
    default => Api::error("Unknown action"),
};

$SETTINGS_FILE = ADELPANEL_ROOT . '/config/backup_settings.json';

function getBackupSettings(): void {
    global $SETTINGS_FILE;
    $defaults = [
        'sites_keep_days'    => 7,
        'db_keep_days'       => 7,
        'full_keep_days'     => 7,
        'sites_cron'         => '0 3 * * *',
        'db_cron'            => '30 3 * * *',
        'full_cron'          => '0 4 * * 0',
        'backup_dir'         => '/opt/backups',
        'db_type'            => trim((string)@file_get_contents('/root/.adelpanel/db_type') ?: 'percona'),
    ];

    if (file_exists($SETTINGS_FILE)) {
        $saved = json_decode(file_get_contents($SETTINGS_FILE), true) ?? [];
        $defaults = array_merge($defaults, $saved);
    }

    // Добавляем актуальную статистику дискового пространства
    $backupDir = $defaults['backup_dir'];
    $defaults['disk_used']  = getDirSize($backupDir);
    $defaults['disk_free']  = round(disk_free_space('/') / 1024**3, 1) . ' GB';

    Api::ok($defaults);
}

function saveBackupSettings(): void {
    global $SETTINGS_FILE;
    Api::onlyPost();

    $allowed = ['sites_keep_days', 'db_keep_days', 'full_keep_days',
                'sites_cron', 'db_cron', 'full_cron', 'backup_dir'];

    $settings = [];
    foreach ($allowed as $key) {
        $val = Api::input($key);
        if ($val === null) continue;

        // Валидация периодов хранения
        if (str_ends_with($key, '_keep_days')) {
            $val = (int) $val;
            if ($val < 1 || $val > 365) Api::error("Период хранения должен быть от 1 до 365 дней");
        }

        // Валидация пути бэкапов
        if ($key === 'backup_dir') {
            if (!str_starts_with($val, '/') || str_contains($val, '..')) {
                Api::error('Недопустимый путь для бэкапов');
            }
        }

        // Базовая валидация cron-строки (5 полей)
        if (str_ends_with($key, '_cron')) {
            if (!preg_match('/^[\d*,\/\-]+ [\d*,\/\-]+ [\d*,\/\-]+ [\d*,\/\-]+ [\d*,\/\-]+$/', trim($val))) {
                Api::error("Неверный формат cron для {$key}");
            }
        }

        $settings[$key] = $val;
    }

    file_put_contents($SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));

    // Перегенерируем /etc/cron.d/adelpanel-backup
    updateCronFile($settings);

    Logger::write('backup', "Настройки бэкапов обновлены");
    Api::ok(null, 'Настройки бэкапов сохранены');
}

function updateCronFile(array $s): void {
    $dir    = $s['backup_dir']      ?? '/opt/backups';
    $sc     = $s['sites_cron']      ?? '0 3 * * *';
    $dc     = $s['db_cron']         ?? '30 3 * * *';
    $fc     = $s['full_cron']       ?? '0 4 * * 0';
    $sk     = (int)($s['sites_keep_days'] ?? 7);
    $dk     = (int)($s['db_keep_days']    ?? 7);
    $fk     = (int)($s['full_keep_days']  ?? 7);

    $content = "# AdelPanel автобэкапы (сгенерировано панелью)\n"
        . "{$sc}   root /opt/adelpanel/scripts/backup_sites.sh >> /var/log/adelpanel-backup.log 2>&1\n"
        . "{$dc}   root /opt/adelpanel/scripts/backup_databases.sh >> /var/log/adelpanel-backup.log 2>&1\n"
        . "{$fc}   root /opt/adelpanel/scripts/backup_full.sh >> /var/log/adelpanel-backup.log 2>&1\n"
        . "0 5 * * *   root find {$dir}/sites -type f -mtime +{$sk} -delete 2>/dev/null\n"
        . "0 5 * * *   root find {$dir}/databases -type f -mtime +{$dk} -delete 2>/dev/null\n"
        . "0 5 * * *   root find {$dir}/full -maxdepth 1 -mindepth 1 -type d -mtime +{$fk} -exec rm -rf {} \\; 2>/dev/null\n"
        . "0 12 * * *  root certbot renew --quiet --nginx\n";

    file_put_contents('/etc/cron.d/adelpanel-backup', $content);
}

function getDirSize(string $dir): string {
    if (!is_dir($dir)) return '0 MB';
    $bytes = (int) shell_exec("du -sb " . escapeshellarg($dir) . " 2>/dev/null | cut -f1") ?: 0;
    if ($bytes > 1024**3) return round($bytes / 1024**3, 1) . ' GB';
    return round($bytes / 1024**2, 0) . ' MB';
}

<?php
// ══════════════════════════════════════════════
//  AdelPanel — core/Logger.php
// ══════════════════════════════════════════════

require_once __DIR__ . '/../config/config.php';

class Logger
{
    private static array $notifMap = [
        'auth_fail'       => ['login_fail',       'warn',   true],
        'auth_ok'         => ['auth',              'info',   false],
        'auth_ban'        => ['login_fail',        'danger', true],
        'pass_change'     => ['password_change',   'warn',   true],
        'site_add'        => ['site_add',          'info',   true],
        'site_del'        => ['site_del',          'warn',   true],
        'db_add'          => ['db_add',            'info',   true],
        'db_del'          => ['db_del',            'warn',   true],
        'ftp_add'         => ['ftp_add',           'info',   true],
        'ftp_del'         => ['ftp_del',           'warn',   true],
        'ssl_issue'       => ['ssl_issue',         'info',   true],
        'ssl_renew'       => ['ssl_renew',         'info',   true],
        'ssl_expire'      => ['ssl_expiry',        'danger', true],
        'ssl_expire_warn' => ['ssl_expiry',        'warn',   true],
        'backup'          => ['system',            'info',   false],
        'security'        => ['login_fail',        'danger', true],
        'auth'            => ['auth',              'info',   false],
        'settings'        => ['system',            'info',   false],
        'db'              => ['db_add',            'info',   false],
        'firewall'        => ['system',            'info',   false],
        'script'          => ['system',            'info',   false],
        'ssl'             => ['ssl_issue',         'info',   false],
        'logs'            => ['system',            'info',   false],
    ];

    public static function write(string $type, string $message, string $title = ''): void
    {
        // 1. Файловый лог
        if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0750, true);

        $line = sprintf(
            "[%s] [%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($type),
            $_SESSION['user'] ?? 'system',
            $message
        );
        @file_put_contents(PANEL_LOG, $line, FILE_APPEND | LOCK_EX);

        // 2. Уведомление в SQLite (только для важных событий)
        $map = self::$notifMap[$type] ?? null;
        if (!$map || !$map[2]) return;

        [$notifType, $severity] = $map;
        $notifTitle = $title ?: self::autoTitle($type);

        try {
            require_once __DIR__ . '/DB.php';
            DB::query(
                'INSERT INTO notifications (type, severity, title, message) VALUES (?, ?, ?, ?)',
                [$notifType, $severity, $notifTitle, $message]
            );
        } catch (\Throwable) {
            // Не роняем запрос если SQLite временно недоступен
        }
    }

    private static function autoTitle(string $type): string
    {
        return match($type) {
            'auth_fail'       => 'Неудачная попытка входа',
            'auth_ban'        => 'IP заблокирован',
            'pass_change'     => 'Пароль изменён',
            'site_add'        => 'Сайт добавлен',
            'site_del'        => 'Сайт удалён',
            'db_add'          => 'База данных создана',
            'db_del'          => 'База данных удалена',
            'ftp_add'         => 'FTP пользователь создан',
            'ftp_del'         => 'FTP пользователь удалён',
            'ssl_issue'       => 'SSL выдан',
            'ssl_renew'       => 'SSL обновлён',
            'ssl_expire'      => 'SSL истёк',
            'ssl_expire_warn' => 'SSL истекает',
            'security'        => 'Событие безопасности',
            default           => ucfirst($type),
        };
    }
}

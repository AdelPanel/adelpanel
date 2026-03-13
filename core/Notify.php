<?php
// ══════════════════════════════════════════════════════════════════
//  AdelPanel — core/Notify.php
//  Система уведомлений (хранятся в SQLite)
//  Типы: ssl_expiry | ssl_issue | ssl_renew | login_fail |
//        password_change | site_add | site_del | db_add | db_del |
//        ftp_add | ftp_del
// ══════════════════════════════════════════════════════════════════

require_once __DIR__ . '/DB.php';

class Notify
{
    // Создать уведомление
    public static function add(
        string $type,
        string $title,
        string $message,
        string $severity = 'info',
        array  $meta     = []
    ): int {
        return DB::insert(
            'INSERT INTO notifications(type,severity,title,message,meta,created_at)
             VALUES(?,?,?,?,?,strftime(\'%s\',\'now\'))',
            [
                $type,
                $severity,
                $title,
                $message,
                $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }

    // Получить непрочитанные (для колокольчика)
    public static function unread(int $limit = 50): array
    {
        return DB::fetchAll(
            'SELECT * FROM notifications WHERE read = 0
             ORDER BY created_at DESC LIMIT ?',
            [$limit]
        );
    }

    // Получить все (с пагинацией)
    public static function all(int $limit = 100, int $offset = 0): array
    {
        return DB::fetchAll(
            'SELECT * FROM notifications ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    // Количество непрочитанных
    public static function unreadCount(): int
    {
        $r = DB::fetchOne('SELECT COUNT(*) as c FROM notifications WHERE read = 0');
        return (int)($r['c'] ?? 0);
    }

    // Отметить как прочитанное
    public static function markRead(int $id): void
    {
        DB::query('UPDATE notifications SET read = 1 WHERE id = ?', [$id]);
    }

    // Отметить все как прочитанные
    public static function markAllRead(): void
    {
        DB::query('UPDATE notifications SET read = 1 WHERE read = 0');
    }

    // Удалить старые (старше N дней)
    public static function cleanup(int $days = 30): void
    {
        DB::query(
            'DELETE FROM notifications WHERE created_at < strftime(\'%s\',\'now\') - ?',
            [$days * 86400]
        );
    }

    // ── Шорткаты для конкретных событий ──────────────────────────

    public static function sslExpiring(string $domain, int $daysLeft): void
    {
        $sev = $daysLeft <= 3 ? 'danger' : ($daysLeft <= 7 ? 'warn' : 'info');
        self::add(
            'ssl_expiry',
            "SSL истекает: {$domain}",
            "Сертификат для {$domain} истекает через {$daysLeft} дн. Обновите его в разделе SSL.",
            $sev,
            ['domain' => $domain, 'days_left' => $daysLeft]
        );
    }

    public static function sslIssued(string $domain, string $method = 'acme'): void
    {
        self::add(
            'ssl_issue',
            "SSL выдан: {$domain}",
            "Сертификат Let's Encrypt успешно выдан для {$domain} ({$method}).",
            'info',
            ['domain' => $domain, 'method' => $method]
        );
    }

    public static function sslRenewed(string $domain): void
    {
        self::add(
            'ssl_renew',
            "SSL обновлён: {$domain}",
            "Сертификат для {$domain} успешно обновлён.",
            'info',
            ['domain' => $domain]
        );
    }

    public static function loginFail(string $username, string $ip, int $remaining): void
    {
        self::add(
            'login_fail',
            'Неудачная попытка входа',
            "Неверный логин/пароль для «{$username}» с IP {$ip}. Осталось попыток: {$remaining}.",
            'warn',
            ['username' => $username, 'ip' => $ip, 'remaining' => $remaining]
        );
    }

    public static function loginBanned(string $ip): void
    {
        self::add(
            'login_fail',
            'IP заблокирован',
            "IP {$ip} заблокирован на 1 час за превышение попыток входа.",
            'danger',
            ['ip' => $ip]
        );
    }

    public static function passwordChanged(string $user, string $ip): void
    {
        self::add(
            'password_change',
            'Пароль панели изменён',
            "Пароль пользователя «{$user}» изменён с IP {$ip}.",
            'warn',
            ['user' => $user, 'ip' => $ip]
        );
    }

    public static function siteAdded(string $domain, string $engine): void
    {
        self::add(
            'site_add',
            "Сайт добавлен: {$domain}",
            "Создан сайт {$domain} (движок: {$engine}) в /var/www/{$domain}.",
            'info',
            ['domain' => $domain, 'engine' => $engine]
        );
    }

    public static function siteDeleted(string $domain): void
    {
        self::add(
            'site_del',
            "Сайт удалён: {$domain}",
            "Сайт {$domain} и его файлы удалены с сервера.",
            'warn',
            ['domain' => $domain]
        );
    }

    public static function dbAdded(string $db, string $user = ''): void
    {
        self::add(
            'db_add',
            "БД создана: {$db}",
            "База данных «{$db}»" . ($user ? " (пользователь: {$user})" : '') . " создана.",
            'info',
            ['db' => $db, 'user' => $user]
        );
    }

    public static function dbDeleted(string $db): void
    {
        self::add(
            'db_del',
            "БД удалена: {$db}",
            "База данных «{$db}» удалена безвозвратно.",
            'warn',
            ['db' => $db]
        );
    }

    public static function ftpAdded(string $ftpUser, string $homePath): void
    {
        self::add(
            'ftp_add',
            "FTP пользователь создан: {$ftpUser}",
            "FTP аккаунт «{$ftpUser}» создан, домашний каталог: {$homePath}.",
            'info',
            ['ftp_user' => $ftpUser, 'home' => $homePath]
        );
    }

    public static function ftpDeleted(string $ftpUser): void
    {
        self::add(
            'ftp_del',
            "FTP пользователь удалён: {$ftpUser}",
            "FTP аккаунт «{$ftpUser}» удалён.",
            'warn',
            ['ftp_user' => $ftpUser]
        );
    }
}

<?php
// ══════════════════════════════════════════════════════════════════
//  AdelPanel — core/DB.php
// ══════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/config.php';

class DB
{
    private static ?PDO $sqlite = null;

    public static function get(): PDO
    {
        if (self::$sqlite !== null) return self::$sqlite;

        $dir = dirname(SQLITE_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $isNew = !file_exists(SQLITE_PATH);

        self::$sqlite = new PDO('sqlite:' . SQLITE_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        self::$sqlite->exec('PRAGMA journal_mode=WAL');
        self::$sqlite->exec('PRAGMA synchronous=NORMAL');
        self::$sqlite->exec('PRAGMA foreign_keys=ON');

        if ($isNew) {
            chmod(SQLITE_PATH, 0640);
            self::migrate();
        } else {
            // Применяем миграции на существующей БД
            self::migrateExisting();
        }

        return self::$sqlite;
    }

    private static function migrate(): void
    {
        self::$sqlite->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                type       TEXT NOT NULL DEFAULT 'system',
                severity   TEXT NOT NULL DEFAULT 'info',
                title      TEXT NOT NULL DEFAULT '',
                message    TEXT NOT NULL DEFAULT '',
                read       INTEGER NOT NULL DEFAULT 0,
                meta       TEXT,
                created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            );
            CREATE INDEX IF NOT EXISTS idx_notif_read    ON notifications(read);
            CREATE INDEX IF NOT EXISTS idx_notif_created ON notifications(created_at DESC);

            CREATE TABLE IF NOT EXISTS settings (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL DEFAULT '',
                updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS audit_log (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                action     TEXT NOT NULL,
                object     TEXT,
                user       TEXT,
                ip         TEXT,
                result     TEXT,
                detail     TEXT,
                created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            );
            CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at DESC);

            CREATE TABLE IF NOT EXISTS ssl_certs (
                domain     TEXT PRIMARY KEY,
                issued_at  INTEGER,
                expires_at INTEGER,
                issuer     TEXT,
                method     TEXT DEFAULT 'acme',
                updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            );
        ");
    }

    // Мигрируем существующую БД — добавляем недостающие колонки
    private static function migrateExisting(): void
    {
        try {
            // Если таблица notifications имеет колонку 'level' вместо 'severity' — добавляем severity
            $cols = self::$sqlite->query("PRAGMA table_info(notifications)")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($cols, 'name');

            if (!in_array('severity', $colNames) && in_array('level', $colNames)) {
                // Переименовать через пересоздание
                self::$sqlite->exec("ALTER TABLE notifications ADD COLUMN severity TEXT NOT NULL DEFAULT 'info'");
                self::$sqlite->exec("UPDATE notifications SET severity = level");
            } elseif (!in_array('severity', $colNames)) {
                self::$sqlite->exec("ALTER TABLE notifications ADD COLUMN severity TEXT NOT NULL DEFAULT 'info'");
            }

            if (!in_array('meta', $colNames)) {
                self::$sqlite->exec("ALTER TABLE notifications ADD COLUMN meta TEXT");
            }

            // Убеждаемся что таблица settings существует
            self::$sqlite->exec("CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            )");

            // ssl_certs
            self::$sqlite->exec("CREATE TABLE IF NOT EXISTS ssl_certs (
                domain TEXT PRIMARY KEY,
                issued_at INTEGER,
                expires_at INTEGER,
                issuer TEXT,
                method TEXT DEFAULT 'acme',
                updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            )");

        } catch (\Throwable) {
            // Миграция не критична — продолжаем
        }
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $r = self::query($sql, $params)->fetch();
        return $r ?: null;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int) self::get()->lastInsertId();
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        $r = self::fetchOne('SELECT value FROM settings WHERE key = ?', [$key]);
        return $r ? $r['value'] : $default;
    }

    public static function setSetting(string $key, mixed $value): void
    {
        self::query(
            "INSERT INTO settings(key,value,updated_at) VALUES(?,?,strftime('%s','now'))
             ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
            [$key, $value]
        );
    }

    public static function mysql(): PDO
    {
        static $pdo = null;
        if ($pdo !== null) return $pdo;

        $conf = MYSQL_CONF;
        if (!file_exists($conf)) {
            throw new \RuntimeException('MySQL конфиг не найден: ' . $conf);
        }

        $ini  = parse_ini_file($conf);
        $pass = $ini['password'] ?? '';
        $sock = MYSQL_SOCKET;

        $dsn = file_exists($sock)
            ? "mysql:unix_socket={$sock};charset=utf8mb4"
            : 'mysql:host=127.0.0.1;charset=utf8mb4';

        $pdo = new PDO($dsn, 'root', $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    }

    public static function mysqlQuery(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::mysql()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

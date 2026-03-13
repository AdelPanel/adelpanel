<?php
// ══════════════════════════════════════════════════════════════════
//  AdelPanel — core/Auth.php
// ══════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Logger.php';

class Auth
{
    private const BAN_ATTEMPTS = 5;
    private const BAN_WINDOW   = 600;
    private const BAN_DURATION = 3600;
    private const PBKDF2_ALGO  = 'sha512';
    private const PBKDF2_ITER  = 310000;
    private const PBKDF2_LEN   = 64;
    private const SALT_BYTES   = 192;

    private static function authFile(): string
    {
        return DATA_DIR . '/.auth_store';
    }

    private static function banFile(): string
    {
        return DATA_DIR . '/.ip_bans';
    }

    public static function hashPassword(string $password): string
    {
        $argon = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2,
        ]);
        $salt   = random_bytes(self::SALT_BYTES);
        $pbkdf2 = hash_pbkdf2(self::PBKDF2_ALGO, $argon, $salt, self::PBKDF2_ITER, self::PBKDF2_LEN, true);
        return base64_encode($salt) . ':' . base64_encode($pbkdf2) . ':' . base64_encode($argon);
    }

    public static function verifyPassword(string $password, string $stored): bool
    {
        $parts = explode(':', $stored);
        if (count($parts) !== 3) return false;
        [$saltB64, $hashB64, $argonB64] = $parts;
        $salt  = base64_decode($saltB64);
        $hash  = base64_decode($hashB64);
        $argon = base64_decode($argonB64);
        if ($salt === false || $hash === false || $argon === false) return false;
        if (!password_verify($password, $argon)) return false;
        $computed = hash_pbkdf2(self::PBKDF2_ALGO, $argon, $salt, self::PBKDF2_ITER, self::PBKDF2_LEN, true);
        return hash_equals($hash, $computed);
    }

    public static function saveCredentials(string $username, string $passwordHash): void
    {
        $data = json_encode(['u' => $username, 'h' => $passwordHash, 't' => time()]);
        $mac  = hash_hmac('sha512', $data, PANEL_SECRET);
        file_put_contents(self::authFile(), $data . "\n" . $mac, LOCK_EX);
        chmod(self::authFile(), 0640);
    }

    private static function loadCredentials(): ?array
    {
        $path = self::authFile();
        if (!file_exists($path)) return null;
        $content = file_get_contents($path);
        if ($content === false) return null;
        $lines = explode("\n", trim($content), 2);
        if (count($lines) !== 2) return null;
        [$data, $mac] = $lines;
        $expected = hash_hmac('sha512', $data, PANEL_SECRET);
        if (!hash_equals($expected, $mac)) {
            Logger::write('security', 'ALERT: auth store HMAC mismatch!');
            return null;
        }
        return json_decode($data, true) ?: null;
    }

    private static function getIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function loadBans(): array
    {
        $path = self::banFile();
        if (!file_exists($path)) return [];
        $data = @file_get_contents($path);
        return $data ? (json_decode($data, true) ?? []) : [];
    }

    private static function saveBans(array $bans): void
    {
        file_put_contents(self::banFile(), json_encode($bans), LOCK_EX);
        @chmod(self::banFile(), 0600);
    }

    public static function checkBan(): bool
    {
        $ip   = self::getIp();
        $bans = self::loadBans();
        $now  = time();
        if (!isset($bans[$ip])) return false;
        $entry = $bans[$ip];
        if (isset($entry['banned_until']) && $entry['banned_until'] > $now) {
            $left = ceil(($entry['banned_until'] - $now) / 60);
            Logger::write('auth', "Заблокированный IP {$ip} пытается войти (осталось {$left} мин)");
            return true;
        }
        if (isset($entry['banned_until']) && $entry['banned_until'] <= $now) {
            unset($bans[$ip]);
            self::saveBans($bans);
        }
        return false;
    }

    private static function recordFailedAttempt(): void
    {
        $ip    = self::getIp();
        $bans  = self::loadBans();
        $now   = time();
        $entry = $bans[$ip] ?? ['attempts' => [], 'banned_until' => 0];
        $entry['attempts'] = array_values(array_filter(
            $entry['attempts'] ?? [],
            fn($t) => ($now - $t) < self::BAN_WINDOW
        ));
        $entry['attempts'][] = $now;
        if (count($entry['attempts']) >= self::BAN_ATTEMPTS) {
            $entry['banned_until'] = $now + self::BAN_DURATION;
            $until = date('H:i:s', $entry['banned_until']);
            $who   = $_SESSION['user'] ?? 'anon';
            Logger::write('security', "IP {$ip} ЗАБЛОКИРОВАН до {$until} ({$who})");
        }
        $bans[$ip] = $entry;
        self::saveBans($bans);
    }

    private static function clearFailedAttempts(): void
    {
        $ip   = self::getIp();
        $bans = self::loadBans();
        unset($bans[$ip]);
        self::saveBans($bans);
    }

    public static function remainingAttempts(): int
    {
        $ip   = self::getIp();
        $bans = self::loadBans();
        $now  = time();
        if (!isset($bans[$ip])) return self::BAN_ATTEMPTS;
        $recent = array_filter($bans[$ip]['attempts'] ?? [], fn($t) => ($now - $t) < self::BAN_WINDOW);
        return max(0, self::BAN_ATTEMPTS - count($recent));
    }

    public static function banTimeLeft(): int
    {
        $ip   = self::getIp();
        $bans = self::loadBans();
        if (!isset($bans[$ip]['banned_until'])) return 0;
        return max(0, $bans[$ip]['banned_until'] - time());
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('_ap_sess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        if (isset($_SESSION['last_active'])) {
            if (time() - $_SESSION['last_active'] > SESSION_LIFETIME) {
                self::logout();
                return;
            }
        }
        if (!isset($_SESSION['_regen_at'])) {
            $_SESSION['_regen_at'] = time();
        } elseif (time() - $_SESSION['_regen_at'] > 900) {
            session_regenerate_id(true);
            $_SESSION['_regen_at'] = time();
        }
        $_SESSION['last_active'] = time();
    }

    public static function require(): void
    {
        self::startSession();
        if (!self::check()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized', 'redirect' => '/']);
            exit;
        }
        $fp = hash('sha256', self::getIp() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (isset($_SESSION['_fp']) && !hash_equals($_SESSION['_fp'], $fp)) {
            Logger::write('security', "Session fingerprint mismatch — IP: " . self::getIp());
            self::logout();
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Session invalid']);
            exit;
        }
        $_SESSION['_fp'] = $fp;
    }

    public static function login(string $username, string $password): array
    {
        self::startSession();
        if (self::checkBan()) {
            $left = ceil(self::banTimeLeft() / 60);
            return ['success' => false, 'error' => "IP заблокирован. Попробуйте через {$left} мин.", 'banned' => true];
        }
        if (empty($username) || empty($password) || strlen($password) > 256) {
            return ['success' => false, 'error' => 'Неверный логин или пароль'];
        }
        $creds = self::loadCredentials();
        if ($creds === null) {
            Logger::write('auth', "Попытка входа — auth_store не найден или повреждён");
            return ['success' => false, 'error' => 'Панель не инициализирована'];
        }
        $userOk = hash_equals($creds['u'] ?? '', $username);
        $passOk = $userOk && self::verifyPassword($password, $creds['h'] ?? '');
        if (!$userOk || !$passOk) {
            self::recordFailedAttempt();
            $remaining = self::remainingAttempts();
            $ip = self::getIp();
            Logger::write('auth', "Неудачная попытка: «{$username}» с IP {$ip} (осталось: {$remaining})");
            self::tryNotify('loginFail', $username, $ip, $remaining);
            if ($remaining === 0) self::tryNotify('loginBanned', $ip);
            $msg = $remaining > 0
                ? "Неверный логин или пароль (осталось попыток: {$remaining})"
                : "Неверный логин или пароль";
            return ['success' => false, 'error' => $msg, 'remaining' => $remaining];
        }
        self::clearFailedAttempts();
        // Preserve CSRF token across session regeneration
        $oldCsrf = $_SESSION['csrf_token'] ?? null;
        session_regenerate_id(true);
        $_SESSION['user']        = $username;
        $_SESSION['_fp']         = hash('sha256', self::getIp() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $_SESSION['logged_in']   = true;
        $_SESSION['last_active'] = time();
        $_SESSION['_regen_at']   = time();
        // Generate fresh CSRF token for the new session
        $_SESSION['csrf_token']  = bin2hex(random_bytes(32));
        Logger::write('auth', "Успешный вход: {$username} с IP " . self::getIp());
        return ['success' => true, 'user' => $username];
    }

    private static function tryNotify(string $method, mixed ...$args): void
    {
        try {
            require_once __DIR__ . '/Notify.php';
            Notify::$method(...$args);
        } catch (\Throwable) {}
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $user = $_SESSION['user'] ?? 'unknown';
            Logger::write('auth', "Выход: {$user}");
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
    }

    public static function check(): bool
    {
        return !empty($_SESSION['logged_in']) && !empty($_SESSION['user']);
    }

    public static function user(): ?string
    {
        return $_SESSION['user'] ?? null;
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(string $token): bool
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function getBannedIps(): array
    {
        $bans = self::loadBans();
        $now  = time();
        $result = [];
        foreach ($bans as $ip => $entry) {
            if (!empty($entry['banned_until']) && $entry['banned_until'] > $now) {
                $result[] = [
                    'ip'    => $ip,
                    'until' => date('Y-m-d H:i:s', $entry['banned_until']),
                    'left'  => ceil(($entry['banned_until'] - $now) / 60) . ' мин',
                    'type'  => 'brute-force',
                ];
            }
        }
        return $result;
    }

    public static function unbanIp(string $ip): void
    {
        $bans = self::loadBans();
        unset($bans[$ip]);
        self::saveBans($bans);
        Logger::write('security', "IP разблокирован вручную: {$ip}");
    }
}

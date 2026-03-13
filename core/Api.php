<?php
// ══════════════════════════════════════════════
//  AdelPanel — core/Api.php
// ══════════════════════════════════════════════

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/DB.php';

// Глобальный обработчик — любой uncaught exception возвращает JSON, не пустой 500
set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

class Api
{
    // ── Инициализация: проверка авторизации + заголовки ──
    public static function init(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        Auth::startSession();
        Auth::require();

        // Только POST разрешён для мутаций
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN']
                  ?? $_POST['csrf_token']
                  ?? (self::jsonBody()['csrf_token'] ?? '');

            if (!Auth::verifyCsrf($token)) {
                self::error('Invalid CSRF token', 403);
            }
        }
    }

    // ── Успешный ответ ──
    public static function ok(mixed $data = null, string $message = ''): void
    {
        $response = ['success' => true];
        if ($message) $response['message'] = $message;
        if ($data !== null) $response['data'] = $data;
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Ошибка ──
    public static function error(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error'   => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Прочитать JSON тело запроса ──
    public static function jsonBody(): array
    {
        static $body = null;
        if ($body === null) {
            $raw  = file_get_contents('php://input');
            $body = $raw ? (json_decode($raw, true) ?? []) : [];
        }
        return $body;
    }

    // ── Получить поле из POST или JSON ──
    public static function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? self::jsonBody()[$key] ?? $default;
    }

    // ── Валидация обязательных полей ──
    public static function requireFields(array $fields): void
    {
        foreach ($fields as $field) {
            $val = self::input($field);
            if ($val === null || $val === '') {
                self::error("Поле '{$field}' обязательно");
            }
        }
    }

    // ── Метод запроса ──
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD']);
    }

    public static function isPost(): bool { return self::method() === 'POST'; }
    public static function isGet():  bool { return self::method() === 'GET'; }

    // ── Только POST ──
    public static function onlyPost(): void
    {
        if (!self::isPost()) self::error('Method not allowed', 405);
    }

    // ── Только GET ──
    public static function onlyGet(): void
    {
        if (!self::isGet()) self::error('Method not allowed', 405);
    }

    // ── Выполнить скрипт через sudo и вернуть результат ──
    public static function runScript(string $script, array $args = []): array
    {
        $scriptPath = SCRIPTS_DIR . '/' . basename($script);

        if (!file_exists($scriptPath)) {
            Logger::write('error', "Скрипт не найден: {$scriptPath}");
            return ['code' => 1, 'output' => 'Script not found'];
        }

        // Экранируем все аргументы
        $safeArgs = array_map('escapeshellarg', $args);
        $cmd      = 'sudo ' . escapeshellarg($scriptPath) . ' ' . implode(' ', $safeArgs) . ' 2>&1';

        $output = [];
        $code   = 0;
        exec($cmd, $output, $code);

        $outputStr = implode("\n", $output);
        Logger::write('script', "Script: {$script} | Args: " . implode(', ', $args)
            . " | Code: {$code} | User: " . Auth::user());

        return [
            'code'   => $code,
            'output' => $outputStr,
            'ok'     => $code === 0,
        ];
    }
}

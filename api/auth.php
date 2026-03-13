<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/auth.php
//  Авторизация — НЕ требует Auth::require()
// ══════════════════════════════════════════════

if (!defined('ADELPANEL_ROOT')) define('ADELPANEL_ROOT', dirname(__DIR__));
require_once ADELPANEL_ROOT . '/config/config.php';
require_once ADELPANEL_ROOT . '/core/Logger.php';
require_once ADELPANEL_ROOT . '/core/DB.php';
require_once ADELPANEL_ROOT . '/core/Auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

Auth::startSession();

$action = $_GET['action'] ?? '';

match($action) {
    'login'  => doLogin(),
    'logout' => doLogout(),
    'check'  => doCheck(),
    'csrf'   => doCsrf(),
    default  => jsonOut(['success' => false, 'error' => 'Unknown action'], 400),
};

function doLogin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(['success' => false, 'error' => 'POST required'], 405);
        return;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $user = trim($body['username'] ?? '');
    $pass = $body['password'] ?? '';

    $result = Auth::login($user, $pass);

    if (!$result['success']) {
        Logger::write('auth_fail', "Неудачная попытка входа: «{$user}» с IP " . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        if ($result['banned'] ?? false) {
            Logger::write('auth_ban', "IP заблокирован: " . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        }
        http_response_code(401);
    } else {
        // Include fresh CSRF token in login response so JS can update window._csrf immediately
        $result['csrf_token'] = Auth::csrfToken();
    }

    jsonOut($result);
}

function doLogout(): void {
    Auth::logout();
    jsonOut(['success' => true]);
}

function doCheck(): void {
    jsonOut([
        'success' => true,
        'data'    => [
            'logged_in' => Auth::check(),
            'user'      => Auth::user(),
        ]
    ]);
}

function doCsrf(): void {
    jsonOut(['success' => true, 'token' => Auth::csrfToken()]);
}

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

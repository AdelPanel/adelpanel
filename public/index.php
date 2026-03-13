<?php
// ══════════════════════════════════════════════
//  AdelPanel — public/index.php
// ══════════════════════════════════════════════

if (!defined('ADELPANEL_ROOT')) define('ADELPANEL_ROOT', dirname(__DIR__));
require_once ADELPANEL_ROOT . '/config/config.php';
require_once ADELPANEL_ROOT . '/core/Auth.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

Auth::startSession();

$uri = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

// ── Публичная страница забытого пароля ────────
if ($uri === '/forgot-password') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(ADELPANEL_ROOT . '/public/forgot-password.html');
    exit;
}

// ── API роутинг ───────────────────────────────
if (str_starts_with($uri, '/api/')) {
    $endpoint = basename($uri);
    if (!preg_match('/^[a-z_]+$/', $endpoint)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
        exit;
    }
    $apiFile = ADELPANEL_ROOT . '/api/' . $endpoint . '.php';
    if (!file_exists($apiFile)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found: ' . $endpoint]);
        exit;
    }
    require $apiFile;
    exit;
}

// ── Выход ─────────────────────────────────────
if ($uri === '/logout') {
    Auth::logout();
    header('Location: /');
    exit;
}

// ── SPA — всё через index.php ────────────────
servePanel();

function servePanel(): void
{
    $file = ADELPANEL_ROOT . '/public/panel.html';
    if (!file_exists($file)) {
        http_response_code(500);
        echo 'Panel not found. Run install.sh';
        return;
    }

    // Inject fresh CSRF token - panel.html MUST go through PHP
    $csrf = Auth::csrfToken();
    $html = file_get_contents($file);
    $html = str_replace('{{CSRF_TOKEN}}', htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'), $html);

    header('Content-Type: text/html; charset=utf-8');
    // Prevent browser from caching (CSRF token changes per session)
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $html;
}

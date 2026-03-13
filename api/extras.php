<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/extras.php
// ══════════════════════════════════════════════
require_once __DIR__ . '/../core/Api.php';
Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'info';

match($action) {
    'info'      => extrasInfo(),
    'pma_token' => getPmaTokenAction(),
    default     => Api::error("Unknown action"),
};

function extrasInfo(): void {
    Api::onlyGet();
    $pmaToken = getToken('.pma_token');
    $fgToken  = getToken('.fg_token');
    $pmaDir   = EXTRAS_DIR . '/phpmyadmin';
    $fgDir    = EXTRAS_DIR . '/filegator';

    Api::ok([
        'pma_available' => is_dir($pmaDir) && file_exists($pmaDir . '/index.php'),
        'pma_token'     => $pmaToken,
        'fg_available'  => is_dir($fgDir) && file_exists($fgDir . '/index.php'),
        'fg_token'      => $fgToken,
    ]);
}

function getPmaTokenAction(): void {
    Api::onlyGet();
    Api::ok(['token' => getToken('.pma_token')]);
}

function getToken(string $file): string {
    $path = DATA_DIR . '/' . $file;
    if (file_exists($path)) {
        $t = trim((string) file_get_contents($path));
        if (preg_match('/^[a-zA-Z0-9]{6,16}$/', $t)) return $t;
    }
    // Генерируем новый если нет
    $token = bin2hex(random_bytes(4));
    @file_put_contents($path, $token);
    @chmod($path, 0640);
    return $token;
}

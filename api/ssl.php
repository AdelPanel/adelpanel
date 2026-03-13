<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/ssl.php
//  SSL через acme.sh (Let's Encrypt / ZeroSSL)
// ══════════════════════════════════════════════
require_once __DIR__ . '/../core/Api.php';
require_once __DIR__ . '/../core/Notify.php';
Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'list';

match($action) {
    'list'       => sslList(),
    'check_dns'  => sslCheckDns(),
    'issue'      => sslIssue(),
    'renew'      => sslRenew(),
    'revoke'     => sslRevoke(),
    'selfsigned' => sslSelfsigned(),
    'check_all'  => sslCheckAll(),
    default      => Api::error('Unknown action'),
};


function sslCheckDns(): void {
    Api::onlyGet();
    $domain = $_GET['domain'] ?? '';
    if (empty($domain)) Api::error('Укажите домен');

    // Получаем IP сервера
    $serverIp = trim((string)shell_exec("curl -s --max-time 3 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}'"));

    // Резолвим домен
    $resolved = gethostbyname($domain);
    $ok = ($resolved !== $domain && $resolved === $serverIp);

    Api::ok([
        'ok'         => $ok,
        'domain'     => $domain,
        'resolved'   => $resolved,
        'server_ip'  => $serverIp,
    ]);
}

function sslList(): void {
    Api::onlyGet();
    $certs = DB::fetchAll('SELECT * FROM ssl_certs ORDER BY expires_at ASC');
    $now   = time();
    foreach ($certs as &$c) {
        $c['days_left']  = $c['expires_at'] ? (int)(($c['expires_at'] - $now) / 86400) : null;
        $c['issued_at']  = $c['issued_at']  ? date('d.m.Y', (int)$c['issued_at'])  : null;
        $c['expires_at_fmt'] = $c['expires_at'] ? date('d.m.Y', (int)$c['expires_at']) : null;
        $c['auto_renew'] = (bool)($c['auto_renew'] ?? true);
        $c['status']     = match(true) {
            $c['days_left'] === null  => 'none',
            $c['days_left'] <= 0     => 'expired',
            $c['days_left'] <= 7     => 'expiring_soon',
            $c['days_left'] <= 30    => 'expiring',
            default                   => 'valid',
        };
    }
    // Добавляем сайты без сертификата
    $sites = array_merge(
        glob(NGINX_CONF_D . '/*.conf') ?: [],
        glob(NGINX_SITES_AVAILABLE . '/*.conf') ?: []
    );
    $inDb  = array_column($certs, 'domain');
    foreach ($sites as $f) {
        $d = basename($f, '.conf');
        if (in_array($d, ['adelpanel', 'default'], true)) continue;
        if (!in_array($d, $inDb, true)) {
            $certs[] = ['domain' => $d, 'issued_at' => null, 'expires_at' => null,
                        'issuer' => null, 'method' => null, 'days_left' => null, 'status' => 'none'];
        }
    }
    Api::ok($certs);
}

function sslIssue(): void {
    Api::onlyPost();
    Api::requireFields(['domain']);
    $domain   = Api::input('domain');
    $email    = Api::input('email') ?: DB::setting('acme_email', 'admin@example.com');
    $wildcard = !empty(Api::input('wildcard'));

    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $domain)) {
        Api::error('Недопустимый домен');
    }

    $result = Api::runScript('ssl_issue.sh', [$domain, $email, $wildcard ? '1' : '0']);
    if (!$result['ok']) Api::error('Ошибка выдачи SSL: ' . $result['output']);

    // Сохраняем в SQLite
    $exp = sslGetExpiry($domain);
    DB::query(
        'INSERT INTO ssl_certs(domain,issued_at,expires_at,issuer,method,updated_at)
         VALUES(?,strftime(\'%s\',\'now\'),?,\'Let\'\'s Encrypt\',\'acme\',strftime(\'%s\',\'now\'))
         ON CONFLICT(domain) DO UPDATE SET issued_at=excluded.issued_at,
         expires_at=excluded.expires_at,updated_at=excluded.updated_at',
        [$domain, $exp]
    );

    Notify::sslIssued($domain, 'acme.sh');
    Logger::write('ssl', "Выдан SSL: {$domain}");
    Api::ok(['domain' => $domain, 'expires_at' => $exp], "SSL выдан для {$domain}");
}

function sslRenew(): void {
    Api::onlyPost();
    Api::requireFields(['domain']);
    $domain = Api::input('domain');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $domain)) Api::error('Недопустимый домен');

    $result = Api::runScript('ssl_renew.sh', [$domain]);
    if (!$result['ok']) Api::error('Ошибка обновления: ' . $result['output']);

    $exp = sslGetExpiry($domain);
    DB::query(
        'UPDATE ssl_certs SET expires_at=?,updated_at=strftime(\'%s\',\'now\') WHERE domain=?',
        [$exp, $domain]
    );

    Notify::sslRenewed($domain);
    Logger::write('ssl', "Обновлён SSL: {$domain}");
    Api::ok(null, "SSL для {$domain} обновлён");
}

function sslRevoke(): void {
    Api::onlyPost();
    Api::requireFields(['domain']);
    $domain = Api::input('domain');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $domain)) Api::error('Недопустимый домен');

    $result = Api::runScript('ssl_revoke.sh', [$domain]);
    if (!$result['ok']) Api::error('Ошибка отзыва: ' . $result['output']);

    DB::query('DELETE FROM ssl_certs WHERE domain=?', [$domain]);
    Logger::write('ssl', "Отозван SSL: {$domain}");
    Api::ok(null, "SSL для {$domain} отозван");
}

function sslSelfsigned(): void {
    Api::onlyPost();
    Api::requireFields(['domain']);
    $domain = Api::input('domain');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]+$/', $domain)) Api::error('Недопустимый домен');

    $result = Api::runScript('ssl_selfsigned.sh', [$domain]);
    if (!$result['ok']) Api::error('Ошибка: ' . $result['output']);

    $exp = time() + 365 * 86400;
    DB::query(
        'INSERT INTO ssl_certs(domain,issued_at,expires_at,issuer,method,updated_at)
         VALUES(?,strftime(\'%s\',\'now\'),?,\'Self-Signed\',\'selfsigned\',strftime(\'%s\',\'now\'))
         ON CONFLICT(domain) DO UPDATE SET expires_at=excluded.expires_at,
         issuer=excluded.issuer,updated_at=excluded.updated_at',
        [$domain, $exp]
    );
    Logger::write('ssl', "Самоподписанный SSL: {$domain}");
    Api::ok(null, "Самоподписанный SSL для {$domain} создан");
}

// Проверяем сроки всех сертификатов и создаём уведомления
function sslCheckAll(): void {
    Api::onlyGet();
    $certs = DB::fetchAll('SELECT * FROM ssl_certs WHERE expires_at IS NOT NULL');
    $now   = time();
    $warned = 0;
    foreach ($certs as $c) {
        $days = (int)(($c['expires_at'] - $now) / 86400);
        if ($days <= 14 && $days > 0) {
            // Проверяем — не создавали ли уже уведомление сегодня
            $exists = DB::fetchOne(
                'SELECT id FROM notifications WHERE type=\'ssl_expiry\'
                 AND json_extract(meta,\'$.domain\')=?
                 AND created_at > strftime(\'%s\',\'now\') - 86400',
                [$c['domain']]
            );
            if (!$exists) {
                Notify::sslExpiring($c['domain'], $days);
                $warned++;
            }
        }
    }
    Api::ok(['checked' => count($certs), 'warned' => $warned]);
}

// Читаем дату истечения через acme.sh info
function sslGetExpiry(string $domain): ?int
{
    $acme = ACME_BIN;
    if (!file_exists($acme)) return null;
    $out = shell_exec("{$acme} --info -d " . escapeshellarg($domain) . " 2>/dev/null");
    if (preg_match('/Le_NextRenewTime=\'?(\d+)\'?/', (string)$out, $m)) {
        return (int)$m[1];
    }
    // Fallback: парсим сертификат напрямую
    $certFile = "/root/.acme.sh/{$domain}_ecc/{$domain}.cer";
    if (!file_exists($certFile)) $certFile = "/root/.acme.sh/{$domain}/{$domain}.cer";
    if (file_exists($certFile)) {
        $info = openssl_x509_parse(file_get_contents($certFile));
        return $info['validTo_time_t'] ?? null;
    }
    return null;
}

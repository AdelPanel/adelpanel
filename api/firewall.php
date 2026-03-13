<?php
// ══════════════════════════════════════════════
//  AdelPanel — api/firewall.php
//  Управление UFW: правила, IP блокировка
// ══════════════════════════════════════════════
require_once __DIR__ . '/../core/Api.php';
Api::init();

$action = Api::input('action') ?? $_GET['action'] ?? 'list';
match($action) {
    'list'        => fwList(),
    'status'      => fwStatus(),
    'add_port'    => fwAddPort(),
    'proto'       => fwProto(),    
    'add'         => fwAddPort(),       // alias
    'delete_rule' => fwDeleteRule(),
    'delete'      => fwDeleteRule(),    // alias (panel.html uses this)
    'block_ip'    => fwBlockIp(),
    'block'       => fwBlockIp(),       // alias
    'unblock_ip'  => fwUnblockIp(),
    'unblock'     => fwUnblockIp(),     // alias (panel.html uses this)
    'blocked_ips' => fwBlockedIps(),
    default       => Api::error("Unknown action"),
};

function fwList(): void {
    Api::onlyGet();

    $raw        = shell_exec('sudo /usr/sbin/ufw status numbered 2>/dev/null') ?: '';
    $statusRaw  = trim((string)shell_exec('sudo /usr/sbin/ufw status 2>/dev/null'));
    $status     = str_contains($statusRaw, 'Status: active') ? 'active' : 'inactive';
    $rules  = [];
    $blocked = [];

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Парсим строки вида: [ 1] 22/tcp                     ALLOW IN    Anywhere                   # SSH
        if (!preg_match('/^\[\s*(\d+)\]\s+(.+?)\s+(ALLOW|DENY|REJECT)\s*(IN|OUT|FWD)?\s+(\S+)\s*#?\s*(.*)$/', $line, $m)) continue;

        $num    = (int)$m[1];
        $to     = trim($m[2]);
        $action = $m[3];
        $from   = trim($m[5]);
        $comment= trim($m[6]);

        // Парсим порт/протокол из поля 'to'
        $port  = $to;
        $proto = 'tcp';
        if (preg_match('/^(\S+?)\/(tcp|udp)$/', $to, $pm)) {
            $port  = $pm[1];
            $proto = $pm[2];
        }

        // Защищённые правила (SSH, порт панели)
        $readonly = preg_match('/\b22\b/', $to) || str_contains($comment, 'SSH');

        $rule = [
            'num'      => $num,
            'port'     => $port,
            'proto'    => $proto,
            'action'   => $action,
            'from'     => $from === 'Anywhere' ? 'any' : $from,
            'comment'  => $comment,
            'readonly' => $readonly,
            'service'  => $comment ?: $port,
        ];

        // Отделяем заблокированные IP (DENY from specific IP)
        if ($action === 'DENY' && $from !== 'Anywhere' && $from !== 'any' && filter_var($from, FILTER_VALIDATE_IP)) {
            $blocked[] = [
                'ip'     => $from,
                'reason' => 'ufw deny',
                'date'   => '',
                'num'    => $num,
            ];
        } else {
            $rules[] = $rule;
        }
    }

    // Добавляем заблокированные через Auth (brute-force)
    $authBans = Auth::getBannedIps();
    foreach ($authBans as $ban) {
        $blocked[] = [
            'ip'     => $ban['ip'],
            'reason' => 'brute-force (' . $ban['left'] . ')',
            'date'   => $ban['until'],
            'num'    => null,
        ];
    }

    Api::ok([
        'rules'   => $rules,
        'blocked' => $blocked,
        'status'  => $status,
    ]);
}

function fwStatus(): void {
    Api::onlyGet();
    $status = trim((string) shell_exec('sudo /usr/sbin/ufw status 2>/dev/null'));
    $active = str_contains($status, 'Status: active');
    Api::ok(['active' => $active, 'raw' => $status]);
}

function fwAddPort(): void {
    Api::onlyPost();
    Api::requireFields(['port', 'proto', 'action']);

    $port   = Api::input('port');
    $proto  = Api::input('proto', 'tcp');   // tcp | udp | any
    $action = Api::input('action', 'allow'); // allow | deny
    $from   = Api::input('from', 'any');    // IP или any
    $comment= Api::input('comment', '');

    // Валидация порта (число или диапазон 80:443)
    if (!preg_match('/^\d+(?::\d+)?$/', $port)) Api::error('Недопустимый порт');
    $portNum = (int) explode(':', $port)[0];
    if ($portNum < 1 || $portNum > 65535) Api::error('Порт вне диапазона 1-65535');

    $allowedProtos  = ['tcp', 'udp', 'any'];
    $allowedActions = ['allow', 'deny', 'reject'];
    if (!in_array($proto,  $allowedProtos,  true)) Api::error('Недопустимый протокол');
    if (!in_array($action, $allowedActions, true)) Api::error('Недопустимое действие');

    if ($from !== 'any' && !filter_var($from, FILTER_VALIDATE_IP) && !filter_var($from, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        // Проверяем CIDR
        if (!preg_match('/^[\d.]+\/\d+$/', $from) && !preg_match('/^[a-fA-F0-9:]+\/\d+$/', $from)) {
            Api::error('Недопустимый IP/CIDR в поле from');
        }
    }

    $portStr = $proto === 'any' ? $port : "{$port}/{$proto}";

    if ($from === 'any') {
        $cmd = "sudo /usr/sbin/ufw {$action} {$portStr}";
    } else {
        $cmd = "sudo /usr/sbin/ufw {$action} from {$from} to any port {$port}" . ($proto !== 'any' ? " proto {$proto}" : '');
    }
    if ($comment) $cmd .= " comment " . escapeshellarg($comment);

    $out  = shell_exec("{$cmd} 2>&1");
    $code = 0;
    shell_exec("sudo /usr/sbin/ufw reload 2>/dev/null");

    Logger::write('firewall', "Правило: {$cmd}");
    Api::ok(['output' => $out], "Правило добавлено");
}

function fwDeleteRule(): void {
    Api::onlyPost();
    Api::requireFields(['num']);
    $num = (int)(Api::input('num') ?? Api::input('rule_num'));
    if ($num < 1 || $num > 999) Api::error('Недопустимый номер правила');

    // Нельзя удалять критические правила (SSH на 22)
    $rules_raw = shell_exec('sudo /usr/sbin/ufw status numbered 2>/dev/null') ?: '';
    if (preg_match("/^\[\s*{$num}\].*\b22\b.*SSH/m", $rules_raw)) {
        Api::error('Нельзя удалить правило SSH — потеряете доступ к серверу');
    }

    shell_exec("echo 'y' | sudo /usr/sbin/ufw delete {$num} 2>&1");
    shell_exec("sudo /usr/sbin/ufw reload 2>/dev/null");
    Logger::write('firewall', "Удалено правило #{$num}");
    Api::ok(null, "Правило #{$num} удалено");
}

function fwBlockIp(): void {
    Api::onlyPost();
    Api::requireFields(['ip']);
    $ip = Api::input('ip');

    if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[\d.]+\/\d+$/', $ip)) {
        Api::error('Недопустимый IP или CIDR');
    }

    // Нельзя заблокировать себя
    if ($ip === ($_SERVER['REMOTE_ADDR'] ?? '')) Api::error('Нельзя заблокировать ваш собственный IP');

    shell_exec("sudo /usr/sbin/ufw insert 1 deny from " . escapeshellarg($ip) . " to any 2>&1");
    shell_exec("sudo /usr/sbin/ufw reload 2>/dev/null");
    Logger::write('firewall', "Заблокирован IP: {$ip}");
    Api::ok(null, "IP {$ip} заблокирован");
}

function fwUnblockIp(): void {
    Api::onlyPost();
    Api::requireFields(['ip']);
    $ip = Api::input('ip');

    if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[\d.]+\/\d+$/', $ip)) {
        Api::error('Недопустимый IP');
    }

    shell_exec("sudo /usr/sbin/ufw delete deny from " . escapeshellarg($ip) . " to any 2>&1");
    shell_exec("sudo /usr/sbin/ufw reload 2>/dev/null");
    Logger::write('firewall', "Разблокирован IP: {$ip}");
    Api::ok(null, "IP {$ip} разблокирован");
}

function fwBlockedIps(): void {
    Api::onlyGet();
    $raw   = shell_exec('sudo /usr/sbin/ufw status 2>/dev/null') ?: '';
    $ips   = [];
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/DENY\s+IN\s+([\d.]+(?:\/\d+)?|[a-fA-F0-9:]+(?:\/\d+)?)/', $line, $m)) {
            $ips[] = $m[1];
        }
    }
    Api::ok(array_unique($ips));
}

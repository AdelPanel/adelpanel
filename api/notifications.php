<?php
require_once __DIR__ . '/../core/Api.php';
require_once __DIR__ . '/../core/Notify.php';

Api::init();
$action = $_GET['action'] ?? Api::input('action') ?? 'list';

match($action) {
    'list'          => notifList(),
    'unread_count'  => notifUnreadCount(),
    'mark_read'     => notifMarkRead(),
    'mark_all_read' => notifMarkAllRead(),
    'clear_all'     => notifClearAll(),
    default         => Api::error('Unknown action'),
};

function notifList(): void {
    Api::onlyGet();
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $items  = DB::fetchAll('SELECT * FROM notifications ORDER BY created_at DESC LIMIT ? OFFSET ?', [$limit, $offset]);
    $count  = (int)(DB::fetchOne('SELECT COUNT(*) as c FROM notifications WHERE read=0')['c'] ?? 0);
    foreach ($items as &$n) {
        // meta может отсутствовать в старой схеме БД или быть NULL
        $rawMeta = $n['meta'] ?? null;
        $n['meta']     = ($rawMeta && $rawMeta !== '') ? json_decode($rawMeta, true) : null;
        $n['time_ago'] = timeAgo((int)($n['created_at'] ?? 0));
        // severity может называться level в старых записях
        if (empty($n['severity']) && !empty($n['level'])) {
            $n['severity'] = $n['level'];
        }
        $n['severity'] = $n['severity'] ?? 'info';
    }
    unset($n);
    Api::ok(['items' => $items, 'unread_count' => $count]);
}

function notifUnreadCount(): void {
    Api::onlyGet();
    Api::ok(['count' => Notify::unreadCount()]);
}

function notifMarkRead(): void {
    Api::onlyPost();
    Api::requireFields(['id']);
    Notify::markRead((int)Api::input('id'));
    Api::ok(null, 'OK');
}

function notifMarkAllRead(): void {
    Api::onlyPost();
    Notify::markAllRead();
    Api::ok(null, 'OK');
}

function timeAgo(int $ts): string {
    if ($ts <= 0) return '—';
    $d = time() - $ts;
    if ($d < 60)     return 'только что';
    if ($d < 3600)   return floor($d/60).' мин назад';
    if ($d < 86400)  return floor($d/3600).' ч назад';
    if ($d < 604800) return floor($d/86400).' дн назад';
    return date('d.m.Y H:i', $ts);
}

function notifClearAll(): void {
    Api::onlyPost();
    DB::query('DELETE FROM notifications');
    Api::ok(null, 'Уведомления очищены');
}

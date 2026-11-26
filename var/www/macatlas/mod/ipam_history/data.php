<?php
// История изменений IPAM (ipam_history) — данные для таблицы.
include_once("../../loadinc.php");

header('Content-Type: application/json; charset=utf-8');

if (!$can_view) {
    http_response_code(403);
    echo json_encode(['error' => 'Нет доступа']);
    exit;
}

// ---------- Фильтры ----------
$period  = $_GET['period'] ?? '30d';
$action  = $_GET['action'] ?? '';
$user_id = (int)($_GET['user_id'] ?? 0);   // -1 = только системные
$search  = trim($_GET['search'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($period === 'today') {
    $where[] = "h.created_at >= CURDATE()";
} elseif ($period === '7d') {
    $where[] = "h.created_at >= NOW() - INTERVAL 7 DAY";
} elseif ($period === '30d') {
    $where[] = "h.created_at >= NOW() - INTERVAL 30 DAY";
} // 'all' — без ограничения

if (in_array($action, ['assign', 'release', 'reserve', 'change', 'conflict'], true)) {
    $where[]  = "h.action = ?";
    $params[] = $action;
    $types   .= 's';
}
if ($user_id > 0) {
    $where[]  = "h.user_id = ?";
    $params[] = $user_id;
    $types   .= 'i';
} elseif ($user_id === -1) {
    $where[] = "h.user_id IS NULL";
}
if ($search !== '') {
    // поиск по hostname / MAC / комментарию / точному IP
    $ipInt = filter_var($search, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ? (int)sprintf('%u', ip2long($search)) : 0;
    $where[]  = "(a.hostname LIKE ? OR a.mac LIKE ? OR h.comment LIKE ? OR a.ip = ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $ipInt;
    $types   .= 'sssi';
}

$sql = "SELECT h.id, h.action, h.old_status, h.new_status, h.comment, h.created_at,
               INET_NTOA(a.ip) AS ip, a.hostname, a.mac,
               CONCAT(INET_NTOA(s.network), '/', s.prefix_len) AS subnet,
               st.fio AS user_fio
        FROM ipam_history h
        JOIN ipam_addresses a ON a.id = h.address_id
        LEFT JOIN ipam_subnets s ON s.id = a.subnet_id
        LEFT JOIN acl_staff st ON st.id = h.user_id";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY h.id DESC LIMIT 500";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL: ' . $mysqli->error]);
    exit;
}
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$ACTIONS = [
    'assign'   => 'Выдан',
    'release'  => 'Освобождён',
    'reserve'  => 'Резерв',
    'change'   => 'Изменение',
    'conflict' => 'Конфликт',
];

$data = [];
while ($r = $res->fetch_assoc()) {
    $data[] = [
        'id'         => (int)$r['id'],
        'created_at' => $r['created_at'],
        'ip'         => $r['ip'],
        'subnet'     => $r['subnet'] ?? '',
        'hostname'   => $r['hostname'] ?? '',
        'mac'        => $r['mac'] ?? '',
        'action'     => $r['action'],
        'action_ru'  => $ACTIONS[$r['action']] ?? $r['action'],
        'status_ch'  => ($r['old_status'] ?? '—') . ' → ' . ($r['new_status'] ?? '—'),
        'user_fio'   => $r['user_fio'] ?? 'система',
        'comment'    => $r['comment'] ?? '',
    ];
}
$stmt->close();

// Пользователи для фильтра (кто есть в истории)
$users = [];
$res = $mysqli->query("SELECT DISTINCT st.id, st.fio
                       FROM ipam_history h JOIN acl_staff st ON st.id = h.user_id
                       ORDER BY st.fio");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $users[] = ['user_id' => (int)$r['id'], 'user_fio' => $r['fio']];
    }
}

echo json_encode(['data' => $data, 'users' => $users], JSON_UNESCAPED_UNICODE);

<?php
// Отчёт по инцидентам — данные (JSON) для index.php.
// Читает: incidents, hardware, locations. Только чтение.
include_once("../../loadinc.php");

header('Content-Type: application/json; charset=utf-8');

if (!$can_view) {
    http_response_code(403);
    echo json_encode(['error' => 'Нет доступа']);
    exit;
}

// ---------- Фильтры ----------
$period = $_GET['period'] ?? '30d';
$cause  = $_GET['cause'] ?? '';
$search = trim($_GET['search'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($period === 'today') {
    $where[] = "i.started_at >= CURDATE()";
} elseif ($period === '7d') {
    $where[] = "i.started_at >= NOW() - INTERVAL 7 DAY";
} elseif ($period === '30d') {
    $where[] = "i.started_at >= NOW() - INTERVAL 30 DAY";
} elseif ($period === '90d') {
    $where[] = "i.started_at >= NOW() - INTERVAL 90 DAY";
} // 'all' — без ограничения

$CAUSES = [
    ''         => '—',
    'power'    => 'Питание',
    'access'   => 'Доступ',
    'provider' => 'Провайдер',
    'hardware' => 'Железо',
    'config'   => 'Конфиг',
    'other'    => 'Другое',
];
if ($cause !== '' && array_key_exists($cause, $CAUSES)) {
    $where[]  = "i.cause = ?";
    $params[] = $cause;
    $types   .= 's';
}
if ($search !== '') {
    $where[]  = "(h.switch_name LIKE ? OR h.hardware_ip LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$sql = "SELECT i.id, i.hardware_id, i.started_at, i.ended_at, i.cause, i.comment,
               h.switch_name, h.hardware_ip, l.name AS loc_name,
               TIMESTAMPDIFF(MINUTE, i.started_at, COALESCE(i.ended_at, NOW())) AS dur_min
        FROM incidents i
        LEFT JOIN hardware h ON h.id = i.hardware_id
        LEFT JOIN locations l ON l.id = h.locations_id";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY i.started_at DESC LIMIT 1000";

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

$rows = [];
$byDev = [];
$totalMin = 0;
$openCnt = 0;

while ($r = $res->fetch_assoc()) {
    $dur = (int)$r['dur_min'];
    $open = $r['ended_at'] === null;
    if ($open) $openCnt++;
    $totalMin += $dur;

    $rows[] = [
        'id'       => (int)$r['id'],
        'started'  => $r['started_at'],
        'ended'    => $r['ended_at'] ?? '',
        'open'     => $open,
        'dur_min'  => $dur,
        'cause'    => $r['cause'],
        'cause_ru' => $CAUSES[$r['cause']] ?? $r['cause'],
        'comment'  => $r['comment'] ?? '',
        'device'   => $r['switch_name'] ?: ('hw-' . (int)$r['hardware_id']),
        'ip'       => $r['hardware_ip'] ?? '',
        'loc'      => $r['loc_name'] ?? '',
    ];

    $k = $r['switch_name'] ?: ('hw-' . (int)$r['hardware_id']);
    if (!isset($byDev[$k])) {
        $byDev[$k] = [
            'device' => $k, 'ip' => $r['hardware_ip'] ?? '', 'loc' => $r['loc_name'] ?? '',
            'cnt' => 0, 'sum_min' => 0, 'max_min' => 0, 'last' => '',
        ];
    }
    $byDev[$k]['cnt']++;
    $byDev[$k]['sum_min'] += $dur;
    if ($dur > $byDev[$k]['max_min']) $byDev[$k]['max_min'] = $dur;
    if ($byDev[$k]['last'] === '' || $r['started_at'] > $byDev[$k]['last']) {
        $byDev[$k]['last'] = $r['started_at'];
    }
}
$stmt->close();

// топ проблемных: по суммарному простою, затем по количеству
$top = array_values($byDev);
usort($top, function ($a, $b) {
    if ($b['sum_min'] !== $a['sum_min']) return $b['sum_min'] - $a['sum_min'];
    return $b['cnt'] - $a['cnt'];
});

echo json_encode([
    'summary' => [
        'total'   => count($rows),
        'open'    => $openCnt,
        'sum_min' => $totalMin,
        'avg_min' => count($rows) ? round($totalMin / count($rows)) : 0,
        'devices' => count($byDev),
    ],
    'top'  => $top,
    'data' => $rows,
], JSON_UNESCAPED_UNICODE);

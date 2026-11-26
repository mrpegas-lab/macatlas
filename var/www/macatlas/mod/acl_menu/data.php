<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}

header('Content-Type: application/json; charset=utf-8');

$what = $_GET['what'] ?? '';

if ($what === 'groups') {
    $res = $mysqli->query("SELECT id, title, sort FROM acl_menu_groups ORDER BY sort, title");
    $data = [];
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int)$r['id']; $r['sort'] = (int)$r['sort']; $data[] = $r;
    }
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($what === 'items') {
    $gid = (int)($_GET['group_id'] ?? 0);
    if (!$gid) { echo json_encode(['data' => []]); exit; }

    $stmt = $mysqli->prepare("SELECT id, code, name, sort, group_id FROM acl_resources WHERE group_id = ? ORDER BY sort, name");
    $stmt->bind_param('i', $gid);
    $stmt->execute();
    $res = $stmt->get_result();

    $data = [];
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int)$r['id']; $r['sort'] = (int)$r['sort']; $r['group_id'] = (int)$r['group_id'];
        $data[] = $r;
    }
    $stmt->close();
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['data' => []]);
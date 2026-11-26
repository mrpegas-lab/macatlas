<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


// Автоочистка старых записей
$mysqli->query("DELETE FROM mt_history_runs WHERE created_at < NOW() - INTERVAL 180 DAY");

// === Фильтры из GET (DataTables передаёт параметры поиска, но мы используем свои) ===
$period   = $_GET['period']   ?? '7d';
$userId   = (int)($_GET['user_id'] ?? 0);
$search   = trim($_GET['search'] ?? '');
$onlyFail = !empty($_GET['only_fail']);

$where  = [];
$params = [];
$types  = '';

switch ($period) {
    case 'today': $where[] = "r.created_at >= CURDATE()"; break;
    case '7d':    $where[] = "r.created_at >= NOW() - INTERVAL 7 DAY"; break;
    case '30d':   $where[] = "r.created_at >= NOW() - INTERVAL 30 DAY"; break;
}

if ($userId > 0) {
    $where[] = "r.user_id = ?";
    $params[] = $userId;
    $types .= 'i';
}

if ($search !== '') {
    $where[] = "(r.commands_text LIKE ? OR EXISTS (
                    SELECT 1 FROM mt_history_items i
                     WHERE i.run_id = r.id
                       AND (i.hostname LIKE ? OR i.ip LIKE ?)
                 ))";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($onlyFail) {
    $where[] = "r.fail_count > 0";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Получаем список запусков
$sql = "SELECT r.* FROM mt_history_runs r
        $whereSql
        ORDER BY r.created_at DESC
        LIMIT 500";

$stmt = $mysqli->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$runs = [];
while ($r = $res->fetch_assoc()) {
    // Превью команд
    $r['cmd_preview'] = mb_substr(trim($r['commands_text']), 0, 80);
    if (mb_strlen($r['commands_text']) > 120) $r['cmd_preview'] .= '…';
    $runs[] = $r;
}
$stmt->close();

// Список пользователей для фильтра
$users = [];
$res = $mysqli->query("SELECT DISTINCT user_id, user_fio FROM mt_history_runs WHERE user_fio <> '' ORDER BY user_fio");
while ($r = $res->fetch_assoc()) $users[] = $r;

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'data' => $runs,
    'users' => $users
], JSON_UNESCAPED_UNICODE);
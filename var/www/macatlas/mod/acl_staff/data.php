<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$draw     = (int)($_POST['draw']   ?? 1);
$start    = (int)($_POST['start']  ?? 0);
$length   = (int)($_POST['length'] ?? 50);
$search   = trim($_POST['search']['value'] ?? '');
$orderCol = (int)($_POST['order'][0]['column'] ?? 0);
$orderDir = strtolower($_POST['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

// карта: JS-индекс → SQL (совпадает с порядком columns в index.php)
$orderMap = [
    0 => 's.id',
    1 => 's.login',
    2 => 's.fio',
    3 => 'r.name',
    4 => 's.auth_type',
    5 => 's.is_active',
    6 => 's.is_protected',
    7 => 's.login_date',
];
$orderBy = $orderMap[$orderCol] ?? 's.login';

$where  = '';
$params = [];
$types  = '';
if ($search !== '') {
    $where    = "WHERE (s.login LIKE ? OR s.fio LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$total = (int)$mysqli->query("SELECT COUNT(*) c FROM acl_staff")->fetch_assoc()['c'];

if ($where) {
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) c FROM acl_staff s LEFT JOIN acl_roles r ON r.id = s.role_id $where"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $filtered = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
} else {
    $filtered = $total;
}

$sql = "SELECT s.id, s.login, s.fio, s.login_date, s.role_id,
               s.auth_type, s.is_active, s.is_protected,
               r.name AS role_name
        FROM acl_staff s
        LEFT JOIN acl_roles r ON r.id = s.role_id
        $where
        ORDER BY $orderBy $orderDir
        LIMIT ? OFFSET ?";

$stmt = $mysqli->prepare($sql);
if ($where) {
    $stmt->bind_param($types.'ii', ...array_merge($params, [$length, $start]));
} else {
    $stmt->bind_param('ii', $length, $start);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $row['id']           = (int)$row['id'];
    $row['role_id']      = (int)$row['role_id'];
    $row['is_active']    = (int)$row['is_active'];
    $row['is_protected'] = (int)$row['is_protected'];
    $data[] = $row;
}
$stmt->close();

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $total,
    'recordsFiltered' => $filtered,
    'data'            => $data,
], JSON_UNESCAPED_UNICODE);
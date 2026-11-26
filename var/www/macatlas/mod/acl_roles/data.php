<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

// --- параметры DataTables ---
$draw    = (int)($_POST['draw']   ?? 1);
$start   = (int)($_POST['start']  ?? 0);
$length  = (int)($_POST['length'] ?? 50);
$search  = trim($_POST['search']['value'] ?? '');
$orderCol = (int)($_POST['order'][0]['column'] ?? 0);
$orderDir = strtolower($_POST['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

// карта: индекс колонки в JS → имя в SQL (порядок как в columns[] в JS)
$orderMap = [
    0 => 'r.id',
    1 => 'r.code',
    2 => 'r.name',
    3 => 'r.is_system',
    4 => 'staff_count',
];
$orderBy = $orderMap[$orderCol] ?? 'r.name';

// --- фильтр поиска ---
$where  = '';
$params = [];
$types  = '';
if ($search !== '') {
    $where = "WHERE (r.code LIKE ? OR r.name LIKE ?)";
    $like  = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

// --- общее количество (без фильтра) ---
$total = (int)$mysqli->query("SELECT COUNT(*) c FROM acl_roles")->fetch_assoc()['c'];

// --- количество после фильтра ---
if ($where) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) c FROM acl_roles r $where");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $filtered = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
} else {
    $filtered = $total;
}

// --- страница данных ---
$sql = "SELECT r.id, r.code, r.name, r.is_system,
               (SELECT COUNT(*) FROM acl_staff s WHERE s.role_id = r.id) AS staff_count
        FROM acl_roles r
        $where
        ORDER BY $orderBy $orderDir
        LIMIT ? OFFSET ?";

$stmt = $mysqli->prepare($sql);
if ($where) {
    $types2  = $types . 'ii';
    $params2 = array_merge($params, [$length, $start]);
    $stmt->bind_param($types2, ...$params2);
} else {
    $stmt->bind_param('ii', $length, $start);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $row['id']          = (int)$row['id'];
    $row['is_system']   = (int)$row['is_system'];
    $row['staff_count'] = (int)$row['staff_count'];
    $data[] = $row;
}
$stmt->close();

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $total,
    'recordsFiltered' => $filtered,
    'data'            => $data,
], JSON_UNESCAPED_UNICODE);
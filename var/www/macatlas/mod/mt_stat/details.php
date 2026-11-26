<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$type  = $_POST['type']  ?? '';
$value = $_POST['value'] ?? '';

if ($type === '' || $value === '') {
    echo json_encode(['data' => []]);
    exit;
}

$allowed = [
    'model'            => 'model',
    'board_name'       => 'board_name',
    'version'          => 'version',
    'current_firmware' => 'current_firmware',
    'cpu_count'        => 'cpu_count',
    'cpu_frequency'    => 'cpu_frequency',
];

if (!isset($allowed[$type])) {
    echo json_encode(['data' => []]);
    exit;
}

$col = $allowed[$type];

$sql = "SELECT hostname, ip, model, board_name, version, current_firmware, uptime, last_check
        FROM mt_devices
        WHERE `$col` = ?
        ORDER BY hostname
        LIMIT 500";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $value);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}
$stmt->close();

echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
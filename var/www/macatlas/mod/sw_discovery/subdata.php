<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


$id_switch = (int)($_GET['id_switch'] ?? 0);
if (!$id_switch) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT spi.id,
               spi.port AS raw_port, -- числовой ID порта для мак-таблицы
               -- Если есть port_name, выводим его (например, sfp28-3), иначе число
               IF(spi.port_name IS NOT NULL AND spi.port_name != '', spi.port_name, spi.port) AS port_id, 
               spi.port_name,
               spi.mac_count,
               spi.poe,
               spi.speed,
               spi.port_status AS status,
               spi.last_active,
               spi.last_down,
               spi.scanned_at
        FROM sw_port_info spi
        WHERE spi.id_switch = ?
        ORDER BY spi.port";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $id_switch);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    // Приводим статус и PoE к удобному виду
    $row['status'] = ($row['status'] === 'up' || $row['status'] === '1') ? 'up' : 'down';
    $row['poe']    = ($row['poe'] === 'on' || $row['poe'] === '1') ? 1 : 0;
    $data[] = $row;
}

$stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_UNICODE);
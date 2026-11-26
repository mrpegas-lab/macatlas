<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


$port_id   = (int)($_GET['port_id'] ?? 0);
$id_switch = (int)($_GET['id_switch'] ?? 0);

if (!$port_id || !$id_switch) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT spf.port_mac,
               ov.vendor_name,
               INET_NTOA(ia.ip) AS arp_ip,
               ia.hostname      AS arp_host,
               phone.phone_number
        FROM sw_port_fdb spf
        LEFT JOIN oui_vendors ov ON ov.id = spf.vendor_id
        -- ИСПОЛЬЗУЕМ ПРАВИЛЬНУЮ ТАБЛИЦУ ipam_addresses
        LEFT JOIN ipam_addresses ia ON ia.mac = spf.port_mac 
        LEFT JOIN phone phone ON phone.phone_ip = INET_NTOA(ia.ip)
        WHERE spf.id_switch = ? AND spf.port = ?
        ORDER BY spf.port_mac";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ii', $id_switch, $port_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_UNICODE);
<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: text/html; charset=utf-8');

$vlan_id = (int)($_POST['vlan_id'] ?? 0);
if ($vlan_id <= 0) {
    exit('<option value="">— сначала выберите VLAN —</option>');
}

$stmt = $mysqli->prepare("SELECT id, network, prefix_len
                          FROM ipam_subnets
                          WHERE vlan_ref_id = ?
                          ORDER BY network");
$stmt->bind_param('i', $vlan_id);
$stmt->execute();
$r = $stmt->get_result();

if (!$r || $r->num_rows === 0) {
    $stmt->close();
    exit('<option value="">— в этом VLAN нет подсетей —</option>');
}

$html = '<option value="">— выберите —</option>';
while ($row = $r->fetch_assoc()) {
    $cidr = long2ip($row['network']) . '/' . $row['prefix_len'];
    $html .= '<option value="' . (int)$row['id'] . '">' . htmlspecialchars($cidr) . '</option>';
}
$stmt->close();
echo $html;
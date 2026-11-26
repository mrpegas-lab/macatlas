<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: text/html; charset=utf-8');

$hw_id = (int)($_POST['hw_id'] ?? 0);
if ($hw_id <= 0) {
    exit('<option value="">— сначала выберите роутер —</option>');
}

$r = $mysqli->query("SELECT id, vlan_id, name
                     FROM ipam_vlans
                     WHERE hw_id = $hw_id
                     ORDER BY vlan_id, name");

if (!$r || $r->num_rows === 0) {
    exit('<option value="">— у этого роутера нет VLAN\'ов —</option>');
}

$html = '<option value="">— выберите —</option>';
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {
    $label = 'VLAN ' . (int)$row['vlan_id'];
    if ($row['name'] !== '' && $row['name'] !== null) {
        $label .= ': ' . $row['name'];
    }
    $html .= '<option value="' . (int)$row['id'] . '">'
           . htmlspecialchars($label) . '</option>';
}
echo $html;
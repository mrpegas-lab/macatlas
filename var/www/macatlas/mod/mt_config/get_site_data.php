<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    $sql = "SELECT 
                ms.id,
                ms.locations_id,
                ms.group_id,
                l.comment,
                l.work_schedule,
                l.tz_offset_msk,
                ms.usb_modem,
                ms.lte_router,
                ms.ip1,
                ms.gw1,
                ms.dns1,
                ms.dhcpclient1,
                ms.ip2,
                ms.gw2,
                ms.dns2,
                ms.dhcpclient2,
                ms.lan,
                ms.ip_video,
                ms.ip_ipip_salons,
                ms.ip_ipip_router1,
                ms.routerid_vpn1,
                ms.routerid_vpn2,
                ms.lo,
                ms.ip_wg,
                ms.wg_private,
                ms.wg_public,
                ms.cred_id
            FROM mt_sites ms
            LEFT JOIN locations l ON l.id = ms.locations_id
            WHERE ms.id = ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        header('Content-Type: application/json');
        echo json_encode($row);
    } else {
        echo json_encode(['error' => 'Запись не найдена']);
    }
} else {
    echo json_encode(['error' => 'ID не указан']);
}
?>
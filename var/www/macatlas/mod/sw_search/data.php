<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$searchValue = trim($_REQUEST['search']['value'] ?? '');

// Поиск идёт по портам/FDB, а их harvest.py собирает ТОЛЬКО со свичей
// (model_type_id=2) — роутеры и точки доступа в выборке не нужны

$sql = "SELECT spf.id,
               -- Формируем заголовок: Бренд Модель [Локация, Имя коммутатора]
               CONCAT(IFNULL(b.brand_name,''), ' ', IFNULL(m.model_name,''), ' [', IFNULL(l.name,''), ', ', IFNULL(h.switch_name,''), ']') AS model_full,
               l.name AS location_name,
               h.switch_name,
               h.hardware_ip,
               -- Подмена на уровне SQL
               IF(spi.port_name IS NOT NULL AND spi.port_name != '', spi.port_name, spi.port) AS port,
               spi.port_name,
               spi.mac_count,
               spf.port_mac,
               ov.vendor_name,
               INET_NTOA(ia.ip) AS arp_ip,
               ia.hostname      AS arp_host,
               phone.phone_number,
               ud.fio,
               spi.poe,
               spi.speed,
               spi.port_status,
               spi.last_active,
               spi.last_down
        FROM hardware h
        LEFT JOIN locations l          ON l.id = h.locations_id
        LEFT JOIN model m              ON m.id = h.id_model
        LEFT JOIN brand b              ON b.id = m.id_brand
        LEFT JOIN sw_port_info spi     ON spi.id_switch = h.id
        LEFT JOIN sw_port_fdb spf      ON spf.id_switch = spi.id_switch AND spf.port = spi.port AND spi.mac_count < 30
        LEFT OUTER JOIN oui_vendors ov ON ov.id = spf.vendor_id
        LEFT OUTER JOIN ipam_addresses ia ON ia.mac = spf.port_mac
        LEFT OUTER JOIN phone phone    ON phone.phone_ip = INET_NTOA(ia.ip)
        LEFT JOIN users_devices ud     ON ud.host = ia.hostname
        WHERE h.active = 1
          AND m.model_type_id = 2";

$params = [];
$types  = '';

if ($searchValue !== '') {
    $sql .= " AND (
        spf.port_mac          LIKE ? OR
        INET_NTOA(ia.ip)    LIKE ? OR
        ia.hostname         LIKE ? OR
        ud.fio              LIKE ? OR
        phone.phone_number  LIKE ? OR
        spi.port_name       LIKE ? OR
        l.name              LIKE ? OR
        CONCAT(IFNULL(b.brand_name,''), ' ', IFNULL(m.model_name,''), ' ', IFNULL(h.switch_name,'')) LIKE ?
    )";
    $like = "%$searchValue%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like]);
    $types .= 'ssssssss';
}

// СОРТИРОВКА: сначала по названию локации, потом по IP (INET_ATON), потом по порту
$sql .= " ORDER BY l.name ASC, INET_ATON(h.hardware_ip) ASC, spi.port ASC";

try {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL Prepare Error: ' . $mysqli->error);
    }

    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();

    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Выводим ошибку в формате JSON, чтобы DataTables не ругался "Invalid JSON response"
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
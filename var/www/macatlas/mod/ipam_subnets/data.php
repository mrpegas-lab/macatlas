<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

// === Исходный SQL — не трогаем ===
$sql = "SELECT ipam_subnets.id,
               ipam_subnets.vlan_ref_id,
               ipam_subnets.network,
               ipam_subnets.prefix_len,
               ipam_subnets.gateway,
               ipam_subnets.description,
               ipam_subnets.created_at,
               ipam_subnets.updated_at,
               ipam_vlans.hw_id                                  AS hw_id,
               INET_NTOA(ipam_subnets.network)                   AS network_str,
               CONCAT(INET_NTOA(ipam_subnets.network), '/', ipam_subnets.prefix_len) AS cidr,
               IFNULL(INET_NTOA(ipam_subnets.gateway), '')       AS gateway_str,
               
               CONCAT('VLAN ', ipam_vlans.vlan_id,
                      IF(ipam_vlans.name IS NULL OR ipam_vlans.name = '',
                         '', CONCAT(': ', ipam_vlans.name))                      
               ) AS vlan_label,


               CONCAT_WS(' ', NULLIF(hardware.switch_name, ''),
                IF(hardware.hardware_ip IS NULL OR hardware.hardware_ip = '',
                NULL, CONCAT('(', hardware.hardware_ip, ')'))
                ) AS hw_label,
               (SELECT COUNT(*) FROM ipam_addresses
                WHERE ipam_addresses.subnet_id = ipam_subnets.id) AS addr_count
        FROM ipam_subnets
        LEFT JOIN ipam_vlans ON ipam_vlans.id = ipam_subnets.vlan_ref_id
        LEFT JOIN hardware   ON hardware.id   = ipam_vlans.hw_id";

$countSql = "SELECT COUNT(*) AS c
             FROM ipam_subnets
             LEFT JOIN ipam_vlans ON ipam_vlans.id = ipam_subnets.vlan_ref_id
             LEFT JOIN hardware   ON hardware.id   = ipam_vlans.hw_id";

// === Перехватываем вывод dt_respond в буфер ===
ob_start();
dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0  => 'ipam_subnets.id',
        1  => 'ipam_subnets.network',
        2  => 'hw_label',
        3  => 'vlan_label',
        4  => 'ipam_subnets.gateway',
        5  => 'ipam_subnets.description',
        6  => 'addr_count',
        7  => 'ipam_subnets.network',
        8  => 'ipam_subnets.network',
        9  => 'ipam_subnets.created_at',
        10 => 'ipam_subnets.updated_at',
    ],
    [],                 // extraSearch
    [                   // searchColumns — только реальные колонки
        'ipam_subnets.description',
        'INET_NTOA(ipam_subnets.network)',   // поиск по строке IP
        'ipam_vlans.name',
        'hardware.switch_name',
        'hardware.hardware_ip',
    ]
);
$json = ob_get_clean();

// === Парсим, дополняем capacity / occupied / free ===
$resp = json_decode($json, true);
if (!is_array($resp) || !isset($resp['data'])) {
    // Если dt_respond вернул не JSON — отдаём как есть
    echo $json;
    exit;
}

// Маска «все единицы» для IPv4 (255.255.255.255)
$ALL_BITS = ip2long('255.255.255.255');   // 4294967295 на 64-bit PHP

foreach ($resp['data'] as &$row) {
    $prefix = (int)($row['prefix_len'] ?? 0);
    $netInt = (int)($row['network'] ?? 0);

    // --- Ёмкость usable-хостов по маске ---
    if ($prefix <= 30) {
        $capacity = (int)(pow(2, 32 - $prefix) - 2);
    } elseif ($prefix === 31) {
        $capacity = 2;
    } elseif ($prefix === 32) {
        $capacity = 1;
    } else {
        $capacity = 0;
    }

    // --- Шлюз занят, если задан и внутри usable-диапазона ---
    $gatewayUsed = 0;
    if (($row['gateway'] ?? null) !== null && $prefix < 31) {
        $gwInt = (int)$row['gateway'];

        // Маска подсети: старшие $prefix бит единицы
        $maskLong  = $prefix === 0 ? 0 : (($ALL_BITS << (32 - $prefix)) & $ALL_BITS);
        // Broadcast: network | инверсия маски
        $broadcast = ($netInt | (~$maskLong & $ALL_BITS)) & $ALL_BITS;

        if ($gwInt > $netInt && $gwInt < $broadcast) {
            $gatewayUsed = 1;
        }
    }

    // --- Занято / свободно ---
    $addrCount = (int)($row['addr_count'] ?? 0);
    $occupied  = $addrCount + $gatewayUsed;
    $free      = $capacity - $occupied;
    if ($free < 0) $free = 0;

    $row['capacity'] = $capacity;
    $row['occupied'] = $occupied;
    $row['free']     = $free;
}
unset($row);

// === Отдаём обратно ===
echo json_encode($resp, JSON_UNESCAPED_UNICODE);
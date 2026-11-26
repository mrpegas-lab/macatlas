<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT h.id,
               h.switch_name,
               h.hardware_ip,
               INET_ATON(h.hardware_ip) AS ip_num, -- <--- АЛИАС ДЛЯ СОРТИРОВКИ
               h.poe,
               CONCAT(IFNULL(b.brand_name,''), ' ', IFNULL(m.model_name,'')) AS model,
               l.name  AS location_name,
               lr.name AS room_name,
               (SELECT COUNT(*) FROM sw_port_info spi WHERE spi.id_switch = h.id) AS ports_total,
               (SELECT COUNT(*) FROM sw_port_info spi WHERE spi.id_switch = h.id AND spi.port_status = 'up')   AS ports_up,
               (SELECT COUNT(*) FROM sw_port_info spi WHERE spi.id_switch = h.id AND spi.port_status = 'down') AS ports_down,
               NULLIF((SELECT COUNT(*) FROM sw_port_info spi WHERE spi.id_switch = h.id AND spi.poe = 'on'), 0)  AS poe_on,
               NULLIF((SELECT COUNT(*) FROM sw_port_info spi WHERE spi.id_switch = h.id AND spi.poe = 'off'), 0) AS poe_off
        FROM hardware h
        LEFT JOIN model m            ON m.id  = h.id_model
        LEFT JOIN model_type mt      ON mt.id = m.model_type_id
        LEFT JOIN brand b            ON b.id  = m.id_brand
        LEFT JOIN locations_rooms lr ON lr.id = h.rooms_id
        LEFT JOIN locations l        ON l.id  = h.locations_id
        WHERE h.active = 1 AND mt.id = 2";

$countSql = "SELECT COUNT(*) AS c
             FROM hardware h
             LEFT JOIN model m            ON m.id  = h.id_model
             LEFT JOIN model_type mt      ON mt.id = m.model_type_id
             LEFT JOIN brand b            ON b.id  = m.id_brand
             LEFT JOIN locations_rooms lr ON lr.id = h.rooms_id
             LEFT JOIN locations l        ON l.id  = h.locations_id
             WHERE h.active = 1 AND mt.id = 2";

$extraSearch = [
    "EXISTS (SELECT 1 FROM sw_port_fdb spf
             WHERE spf.id_switch = h.id AND spf.port_mac LIKE ?)",
    "EXISTS (SELECT 1 FROM sw_port_fdb spf
             LEFT JOIN ipam_addresses a ON a.mac = spf.port_mac
             WHERE spf.id_switch = h.id AND INET_NTOA(a.ip) LIKE ?)",
    "EXISTS (SELECT 1 FROM sw_port_fdb spf
             LEFT JOIN ipam_addresses a ON a.mac = spf.port_mac
             WHERE spf.id_switch = h.id AND a.hostname LIKE ?)",
    "EXISTS (SELECT 1 FROM sw_port_fdb spf
             LEFT JOIN ipam_addresses a ON a.mac = spf.port_mac
             LEFT JOIN users_devices ud ON ud.host = a.hostname
             WHERE spf.id_switch = h.id AND ud.fio LIKE ?)"
];

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'h.switch_name',
        1 => 'l.name',
        2 => 'lr.name',
        3 => 'ip_num', // <--- СОРТИРУЕМ ПО АЛИАСУ, А НЕ ПО ФУНКЦИИ
        5 => 'h.poe',
        6 => 'ports_up',
        7 => 'ports_down',
        8 => 'poe_on',
        9 => 'poe_off',
    ],
    $extraSearch,
    [
        'h.switch_name',
        'l.name',
        'lr.name',
        'h.hardware_ip', // Поиск оставляем по строке (LIKE)
    ]
);
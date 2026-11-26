<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT hardware.id,
               hardware.id_model,
               hardware.switch_name,
               hardware.hardware_ip,
               hardware.poe,
               hardware.locations_id,
               hardware.rooms_id,
               hardware.active,
               hardware.cred_id,
               hardware.firmware_version,
               hardware.bootloader_version,
               hardware.serial_number,
               hardware.model_name,
               hardware.uptime,
               hardware.temperature,
               hardware.poe_budget,
               hardware.poe_consumption,
               CONCAT(IFNULL(brand.brand_name,''), ' ', IFNULL(model.model_name,'')) AS model_full,
               locations.name      AS locations_name,
               locations_rooms.name AS rooms_name,
               credentials.name    AS cred_name
        FROM hardware
        LEFT JOIN model            ON model.id            = hardware.id_model
        LEFT JOIN brand            ON brand.id            = model.id_brand
        LEFT JOIN locations_rooms  ON locations_rooms.id  = hardware.rooms_id
        LEFT JOIN locations        ON locations.id        = hardware.locations_id
        LEFT JOIN credentials      ON credentials.id      = hardware.cred_id";

$countSql = "SELECT COUNT(*) AS c
             FROM hardware
             LEFT JOIN model           ON model.id           = hardware.id_model
             LEFT JOIN brand           ON brand.id           = model.id_brand
             LEFT JOIN locations_rooms ON locations_rooms.id = hardware.rooms_id
             LEFT JOIN locations       ON locations.id       = hardware.locations_id
             LEFT JOIN credentials     ON credentials.id     = hardware.cred_id";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'hardware.id',
        1 => 'model_full',
        2 => 'hardware.switch_name',
        3 => 'hardware.hardware_ip',
        4 => 'hardware.poe',
        5 => 'locations.name',
        6 => 'locations_rooms.name',
        7 => 'hardware.active',
        8 => 'credentials.name',
    ],
    [],
    [
        'hardware.switch_name',
        'hardware.hardware_ip',
        'brand.brand_name',
        'model.model_name',
        'locations.name',
        'locations_rooms.name',
        'credentials.name',
    ]
);
<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT l.id,
               l.name,
               l.code,
               l.new_name,
               l.address,
               l.gps,
               l.tz_offset_msk,
               l.work_schedule,
               l.type_id,
               lt.name AS type_name,
               l.comment,
               l.created_at,
               (SELECT COUNT(*) FROM mt_sites ms WHERE ms.locations_id = l.id) AS sites_cnt,
               (SELECT GROUP_CONCAT(h.switch_name SEPARATOR ', ') FROM hardware h WHERE h.locations_id = l.id) AS hw_list,
               (SELECT GROUP_CONCAT(d.hostname SEPARATOR ', ') FROM mt_devices d WHERE d.locations_id = l.id) AS dev_list,
               (SELECT COUNT(*) FROM hardware h2 WHERE h2.locations_id = l.id) AS hw_cnt
        FROM locations l
        LEFT JOIN locations_type lt ON lt.id = l.type_id";

$countSql = "SELECT COUNT(*) AS c FROM locations l LEFT JOIN locations_type lt ON lt.id = l.type_id";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0  => 'l.id',
        1  => 'l.name',
        2  => 'l.code',
        3  => 'l.new_name',
        4  => 'l.address',
        5  => 'l.gps',
        6  => 'l.tz_offset_msk',
        7  => 'lt.sort',
        9  => 'l.id',
        10 => 'l.comment',
        11 => 'l.created_at',
        12 => 'l.work_schedule',
    ],
    [],
    [
        'l.name',
        'l.code',
        'l.new_name',
        'l.address',
        'l.gps',
        'lt.name',
        'l.comment',
    ]
);
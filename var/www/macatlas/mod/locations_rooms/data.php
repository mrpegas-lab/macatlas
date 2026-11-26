<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT locations_rooms.id,
               locations_rooms.name,
               locations_rooms.locations_id,
               locations.name AS locations_name,
               (SELECT COUNT(*) FROM hardware h WHERE h.rooms_id = locations_rooms.id) AS hw_count
        FROM locations_rooms
        LEFT JOIN locations ON locations.id = locations_rooms.locations_id";

$countSql = "SELECT COUNT(*) AS c
             FROM locations_rooms
             LEFT JOIN locations ON locations.id = locations_rooms.locations_id";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'locations_rooms.id',
        1 => 'locations.name',
        2 => 'locations_rooms.name',
        3 => 'hw_count',
    ],
    [],
    [
        'locations_rooms.name',
        'locations.name',
    ]
);
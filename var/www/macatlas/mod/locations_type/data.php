<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql      = "SELECT id, name, sort, description FROM locations_type";
$countSql = "SELECT COUNT(*) AS c FROM locations_type";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'id',
        1 => 'name',
        2 => 'sort',
        3 => 'description',
    ],
    [],
    [
        'name',
        'description',
    ]
);
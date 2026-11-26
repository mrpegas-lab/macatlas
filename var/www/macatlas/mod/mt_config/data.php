<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT ms.id,
               ms.locations_id,
               l.code,
               l.new_name,
               l.address,
               ms.ip1, 
               ms.gw1, 
               ms.lan               
        FROM mt_sites ms
        LEFT JOIN locations l ON l.id = ms.locations_id
        ";

$countSql = "SELECT COUNT(*) AS c
             FROM mt_sites ms
             LEFT JOIN locations l ON l.id = ms.locations_id";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    'ms.id DESC',       // ← сортировка по умолчанию (было '')
    [
        0 => 'ms.id',
        1 => 'l.code',
        2 => 'l.new_name',
        3 => 'l.address',
        4 => 'ms.ip1',
        5 => 'ms.gw1',
        6 => 'ms.lan'
    ],
    [],   // extraSearch
    [     // ← колонки для поиска
        'l.code',
        'l.new_name',
        'l.address',
        'ms.ip1',
        'ms.gw1',
        'ms.lan'
    ]
);
?>
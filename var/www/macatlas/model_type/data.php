<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT model_type.id,
               model_type.name,
               model_type.description,
               (SELECT COUNT(*) FROM model WHERE model_type_id = model_type.id) AS model_count
        FROM model_type";

$countSql = "SELECT COUNT(*) AS c FROM model_type";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'model_type.id',
        1 => 'model_type.name',
        2 => 'model_type.description',
        3 => 'model_count',
    ],
    [],
    [
        'model_type.name',
        'model_type.description',
    ]
);
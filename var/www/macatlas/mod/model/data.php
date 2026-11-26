<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT model.id,
               model.id_brand,
               model.model_type_id,
               model.model_name,
               model.ports,
               model.poe_ports,
               brand.brand_name,
               model_type.name AS model_type_name,
               (SELECT COUNT(*) FROM hardware h WHERE h.id_model = model.id) AS hw_count
        FROM model
        LEFT JOIN brand   ON brand.id = model.id_brand
        LEFT JOIN model_type ON model_type.id = model.model_type_id";

$countSql = "SELECT COUNT(*) AS c
             FROM model
             LEFT JOIN brand   ON brand.id   = model.id_brand
             LEFT JOIN model_type ON model_type.id = model.model_type_id";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'model.id',
        1 => 'model_type.name',
        2 => 'brand.brand_name',
        3 => 'model.model_name',
        4 => 'model.ports',
        5 => 'model.poe_ports',
        6 => 'hw_count',
    ],
    [],
    [
        'model.model_name',
        'brand.brand_name',
        'model_type.name',
    ]
);
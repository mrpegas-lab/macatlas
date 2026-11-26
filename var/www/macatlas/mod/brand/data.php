<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql      = "SELECT id, brand_name,
                    (SELECT COUNT(*) FROM model m WHERE m.id_brand = brand.id) AS model_count
             FROM brand";
$countSql = "SELECT COUNT(*) AS c FROM brand";

dt_respond(
    $sql,
    $countSql,
    [],        // параметры
    '',        // типы
    '',        // сортировка по умолчанию — берётся из POST
    [          // карта: индекс колонки в DataTables → SQL-поле
        0 => 'id',
        1 => 'brand_name',
        2 => 'model_count',
    ],
    [],        // extraSearch
    [          // searchColumns — только реальные колонки
        'brand_name',
    ]
);
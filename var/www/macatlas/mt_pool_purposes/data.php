<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT p.id,
               p.code,
               p.name,
               p.kind,
               p.default_bits,
               p.icon,
               (SELECT COUNT(*) FROM mt_pool_ranges r
                 WHERE r.purpose = p.code) AS pools_count,
               (SELECT COUNT(*) FROM mt_pool_allocations a
                  JOIN mt_pool_ranges r ON r.id = a.pool_id
                 WHERE r.purpose = p.code) AS addresses_count
        FROM mt_pool_purposes p";

$countSql = "SELECT COUNT(*) AS c FROM mt_pool_purposes";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    'p.name ASC',
    [
        0 => 'p.id',
        1 => 'p.code',
        2 => 'p.name',
        3 => 'p.kind',
        4 => 'p.default_bits',
        5 => 'p.icon'
    ],
    [],
    [
        'p.code',
        'p.name'
    ]
);
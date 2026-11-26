<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT g.id, g.code, g.name, g.sort, g.enabled,
               (SELECT COUNT(*) FROM mt_pool_ranges r WHERE r.group_id = g.id) AS pools_count,
               (SELECT COUNT(*) FROM mt_pool_allocations a
                  JOIN mt_pool_ranges r ON r.id = a.pool_id
                 WHERE r.group_id = g.id) AS addresses_count
        FROM mt_pool_groups g";

$countSql = "SELECT COUNT(*) AS c FROM mt_pool_groups";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    'g.sort ASC, g.name ASC',
    [
        0 => 'g.id',
        1 => 'g.code',
        2 => 'g.name',
        3 => 'g.sort',
        4 => 'g.enabled'
    ],
    [],
    [
        'g.code',
        'g.name'
    ]
);
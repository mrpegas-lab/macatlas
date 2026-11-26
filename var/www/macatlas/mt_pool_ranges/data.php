<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT r.id,
               r.group_id,
               r.purpose,
               r.name,
               r.cidr,
               r.kind,
               r.step_bits,
               r.priority,
               r.enabled,
               g.name AS group_name,
               p.name AS purpose_name,
               (SELECT COUNT(*) FROM mt_pool_allocations a
                 WHERE a.pool_id = r.id) AS allocations_count
        FROM mt_pool_ranges r
        LEFT JOIN mt_pool_groups g ON g.id = r.group_id
        LEFT JOIN mt_pool_purposes p ON p.code = r.purpose";

$countSql = "SELECT COUNT(*) AS c FROM mt_pool_ranges";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    'g.sort ASC, r.priority ASC, r.name ASC',
    [
        0 => 'r.id',
        1 => 'g.name',
        2 => 'p.name',
        3 => 'r.name',
        4 => 'r.cidr',
        5 => 'r.kind',
        6 => 'r.step_bits',
        7 => 'r.priority',
        8 => 'r.enabled'
    ],
    [],
    [
        'r.name',
        'r.cidr',
        'g.name',
        'p.name'
    ]
);
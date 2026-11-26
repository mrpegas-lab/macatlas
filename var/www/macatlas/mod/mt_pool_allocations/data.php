<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT a.id,
               a.pool_id,
               a.network_ip,
               a.block_bits,
               a.site_id,
               a.allocated_at,
               r.name AS pool_name,
               p.name AS purpose_name,
               l.code AS site_code,
               l.new_name AS site_name,
               (SELECT COUNT(*) FROM mt_sites WHERE id = a.site_id) AS site_exists
        FROM mt_pool_allocations a
        LEFT JOIN mt_pool_ranges r ON r.id = a.pool_id
        LEFT JOIN mt_pool_purposes p ON p.code = r.purpose
        LEFT JOIN mt_sites ms ON ms.id = a.site_id
        LEFT JOIN locations l ON l.id = ms.locations_id";

// FROM/JOIN'ы обязаны повторять основной запрос: dt_respond() дописывает
// поисковое условие (с алиасами a/r/p/l) и в count-запрос тоже
$countSql = "SELECT COUNT(*) AS c
             FROM mt_pool_allocations a
             LEFT JOIN mt_pool_ranges r ON r.id = a.pool_id
             LEFT JOIN mt_pool_purposes p ON p.code = r.purpose
             LEFT JOIN mt_sites ms ON ms.id = a.site_id
             LEFT JOIN locations l ON l.id = ms.locations_id";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    'a.id DESC', // Сначала новые
    [
        0 => 'a.id',
        1 => 'a.network_ip',
        2 => 'a.block_bits',
        3 => 'r.name',
        4 => 'p.name',
        5 => 'l.new_name',
        6 => 'a.allocated_at'
    ],
    [],
    [
        'a.network_ip',
        'r.name',
        'p.name',
        'l.new_name',
        'l.code'
    ]
);
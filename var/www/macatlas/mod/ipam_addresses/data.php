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
               a.subnet_id,
               a.ip,
               a.status,
               a.source,
               a.ip_source,
               a.hostname,
               a.mac,
               a.description,
               a.updated_at,
               INET_NTOA(a.ip) AS ip_str,
               CONCAT(INET_NTOA(s.network), '/', s.prefix_len, ' — ', v.name) AS subnet_label,
               s.vlan_ref_id,
               v.hw_id
        FROM ipam_addresses a
        JOIN ipam_subnets s ON s.id = a.subnet_id
        JOIN ipam_vlans v   ON v.id = s.vlan_ref_id";

$countSql = "SELECT COUNT(*) AS c 
             FROM ipam_addresses a
             JOIN ipam_subnets s ON s.id = a.subnet_id
             JOIN ipam_vlans v   ON v.id = s.vlan_ref_id";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'a.id',
        1 => 's.network',
        2 => 'a.ip',
        3 => 'a.status',
        4 => 'a.hostname',
        5 => 'a.mac',
        6 => 'a.description',
        7 => 'a.updated_at',
    ],
    [], // extraSearch
    [   // searchColumns
        'INET_NTOA(a.ip)', 
        'a.hostname', 
        'a.mac', 
        'a.description'        
    ]
);
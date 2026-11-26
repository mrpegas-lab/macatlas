<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql      = "SELECT c.id, c.name, c.login, c.port_ssh, c.port_api, c.port_api_ssl,
                    (SELECT COUNT(*) FROM hardware h WHERE h.cred_id = c.id) AS hw_count,
                    (SELECT COUNT(*) FROM mt_sites s WHERE s.cred_id = c.id) AS sites_count
             FROM credentials c";
$countSql = "SELECT COUNT(*) AS c FROM credentials";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'id',
        1 => 'name',
        2 => 'login',
        3 => 'port_ssh',
        4 => 'port_api',
        5 => 'port_api_ssl',
    ]
);
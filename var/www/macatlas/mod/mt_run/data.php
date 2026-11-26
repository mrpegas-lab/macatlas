<?php
// Файл: /mod/mt_run/data.php
include_once("../../loadinc.php");

if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет прав на просмотр'], JSON_UNESCAPED_UNICODE));
}

// === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
$slot = isset($_POST['slot']) ? (int)$_POST['slot'] : 0;
if ($slot < 1 || $slot > 5) $slot = 0;

if ($slot > 0) {
    $slotJoin   = "LEFT JOIN mt_run_slot_items si
                          ON si.site_id = ms.id AND si.slot = {$slot}";
    $slotSelect = "si.status AS slot_status, si.error_text AS slot_error";
} else {
    $slotJoin   = "";
    $slotSelect = "NULL AS slot_status, NULL AS slot_error";
}
// === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===

$sql = "SELECT 
            ms.id             AS site_id,
            l.code,
            l.name,
            l.new_name,           
            l.address,
            ms.ip1             AS site_ip,
            md.ip             AS device_ip,
            md.board_name
        FROM mt_sites ms
        INNER JOIN mt_devices md ON md.locations_id = ms.locations_id
        LEFT JOIN locations l    ON l.id = ms.locations_id
        WHERE l.new_name IS NOT NULL AND l.new_name <> ''";

$countSql = "SELECT COUNT(*) AS c 
             FROM mt_sites ms
             INNER JOIN mt_devices md ON md.locations_id = ms.locations_id
             LEFT JOIN locations l    ON l.id = ms.locations_id
             WHERE l.new_name IS NOT NULL AND l.new_name <> ''";

$sql_sort = "SELECT 
            ms.id             AS site_id,
            l.code,
            l.name,
            l.new_name,           
            l.address,
            COALESCE(NULLIF(md.ip, ''), ms.ip1) AS ip,
            md.board_name,
            {$slotSelect}
        FROM mt_sites ms
        INNER JOIN mt_devices md ON md.locations_id = ms.locations_id
        LEFT JOIN locations l    ON l.id = ms.locations_id
        {$slotJoin}
        WHERE l.new_name IS NOT NULL AND l.new_name <> ''";

$countSql_sort = $countSql;

dt_respond(
    $sql_sort,
    $countSql_sort,
    [],           // Параметры
    '',           // Типы
    'l.name ASC', // Сортировка по умолчанию
    [             // Колонки для сортировки
        1 => 'ms.id',
        2 => 'l.code',
        3 => 'l.name',
        4 => 'l.address',
        5 => 'ip',
        6 => 'l.new_name',
        7 => 'md.board_name'
    ]
);
?>
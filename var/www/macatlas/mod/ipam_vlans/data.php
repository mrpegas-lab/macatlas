<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT ipam_vlans.id,
               ipam_vlans.hw_id,
               ipam_vlans.vlan_id,
               ipam_vlans.name,
               ipam_vlans.description,
               ipam_vlans.created_at,
               ipam_vlans.updated_at,
               CONCAT(IFNULL(brand.brand_name,''), ' ', IFNULL(model.model_name,'')) AS model_full,
               hardware.switch_name,
               hardware.hardware_ip,
               CONCAT(
                   CONCAT(IFNULL(brand.brand_name,''), ' ', IFNULL(model.model_name,'')),
                   IF(hardware.switch_name IS NULL OR hardware.switch_name = '', '',
                      CONCAT(' — ', hardware.switch_name)),
                   IF(hardware.hardware_ip IS NULL OR hardware.hardware_ip = '', '',
                      CONCAT(' (', hardware.hardware_ip, ')'))
               ) AS hw_label,
               (SELECT COUNT(*) FROM ipam_subnets
                WHERE ipam_subnets.vlan_ref_id = ipam_vlans.id) AS subnets_count
        FROM ipam_vlans
        LEFT JOIN hardware ON hardware.id = ipam_vlans.hw_id
        LEFT JOIN model    ON model.id    = hardware.id_model
        LEFT JOIN brand    ON brand.id    = model.id_brand";

$countSql = "SELECT COUNT(*) AS c
             FROM ipam_vlans
             LEFT JOIN hardware ON hardware.id = ipam_vlans.hw_id
             LEFT JOIN model    ON model.id    = hardware.id_model
             LEFT JOIN brand    ON brand.id    = model.id_brand";

dt_respond(
    $sql,
    $countSql,
    [],
    '',
    '',
    [
        0 => 'ipam_vlans.id',
        1 => 'hw_label',
        2 => 'ipam_vlans.vlan_id',
        3 => 'ipam_vlans.name',
        4 => 'ipam_vlans.description',
        5 => 'subnets_count',
        6 => 'ipam_vlans.created_at',
        7 => 'ipam_vlans.updated_at',
    ],
    [],
    [
        'ipam_vlans.vlan_id',
        'ipam_vlans.name',
    ]
    
);;
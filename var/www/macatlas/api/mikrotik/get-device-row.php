<?php
require_once dirname(__DIR__, 2) . '/loadinc.php';

header('Content-Type: application/json');

// --- проверка прав: просмотр данных устройства ---
if (!acl('mt_update', 'view')) {
    echo json_encode(['success' => false, 'error' => 'Нет прав на просмотр']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'id required']);
    exit;
}

// та же логика, что в index.php
$fwDir = rtrim($config['FIRMWARE']['DIR'] ?? '', '/') . '/';
$targetVersion = null;
if (is_file($fwDir . 'version.txt')) {
    $targetVersion = trim(file_get_contents($fwDir . 'version.txt'));
}

$sql = "SELECT
            ms.id             AS site_id,
            l.code,
            l.name,
            l.new_name,
            l.address,
            ms.ip1             AS site_ip,
            md.ip             AS device_ip,
            md.version        AS current_version,
            md.board_name,
            md.current_firmware
        FROM mt_sites ms
        INNER JOIN mt_devices md ON md.locations_id = ms.locations_id
        LEFT JOIN locations l    ON l.id = ms.locations_id
        WHERE ms.id = $id
        LIMIT 1";

$res = $mysqli->query($sql);
$d = $res ? $res->fetch_assoc() : null;
if (!$d) {
    echo json_encode(['success' => false, 'error' => 'not found']);
    exit;
}

$d['ip'] = $d['device_ip'] ?: $d['site_ip'];
$d['is_outdated'] = false;
if ($targetVersion && $d['current_version']) {
    $clean = preg_replace('/\s*\(.*$/', '', $d['current_version']);
    $d['is_outdated'] = version_compare($clean, $targetVersion, '<');
}

$d['fw_outdated'] = false;
if ($targetVersion && $d['current_firmware']) {
    $fwClean = preg_replace('/\s*\(.*$/', '', $d['current_firmware']);
    $d['fw_outdated'] = version_compare($fwClean, $targetVersion, '<');
}

echo json_encode(['success' => true, 'device' => $d], JSON_UNESCAPED_UNICODE);
<?php
require_once dirname(__DIR__, 2) . '/loadinc.php';

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- проверка прав: перезагрузка устройств — право редактирования раздела «Конфигуратор объектов» ---
if (!acl('mt_config', 'edit')) {
    jsonResponse(['success' => false, 'error' => 'Нет прав на выполнение этого действия']);
}

$id = $_POST['id'] ?? null;
if (!$id) jsonResponse(['success' => false, 'error' => 'Device ID required']);

$id = (int)$id;
$sql = "SELECT d.id, d.hostname, d.ip, c.login, c.pas
        FROM mt_sites s
        LEFT JOIN mt_devices d  ON d.locations_id = s.locations_id
        LEFT JOIN credentials c ON c.id = s.cred_id
        WHERE s.id = $id
        LIMIT 1";
$res = $mysqli->query($sql);
$row = $res ? $res->fetch_assoc() : null;

if (!$row) jsonResponse(['success' => false, 'error' => 'Device not found']);
if (empty($row['login']) || empty($row['pas'])) {
    jsonResponse(['success' => false, 'error' => 'Credentials not found for device']);
}

$login = $row['login'];
$pass  = decryptPassword($row['pas']);
if (empty($pass)) jsonResponse(['success' => false, 'error' => 'Password decrypt failed']);

$result = mikrotikApi($row['ip'], $login, $pass, 'execute', [
    'script' => ':execute "/system reboot"'
]);

if (isset($result['error'])) {
    jsonResponse([
        'success'  => false,
        'error'    => $result['error'],
        'hostname' => $row['hostname'],
        'ip'       => $row['ip']
    ]);
}

jsonResponse([
    'success'  => true,
    'message'  => 'Router is rebooting',
    'hostname' => $row['hostname'],
    'ip'       => $row['ip']
]);
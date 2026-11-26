<?php
require_once dirname(__DIR__, 2) . '/loadinc.php';

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// --- проверка прав: обновление устройств — право «выполнение» раздела «Обновление»
//     или «редактирование» конфигуратора (кнопка в карточке устройства) ---
if (!acl('mt_update', 'exec') && !acl('mt_config', 'edit')) {
    jsonResponse(['success' => false, 'error' => 'Нет прав на выполнение этого действия']);
}

$id = $_POST['id'] ?? null;
if (!$id) jsonResponse(['success' => false, 'error' => 'Не передан ID']);
$id = (int)$id;

$sql = "SELECT d.id, d.hostname, d.ip, c.login, c.pas
        FROM mt_devices d
        LEFT JOIN mt_sites s    ON s.locations_id = d.locations_id
        LEFT JOIN credentials c ON c.id = s.cred_id
        WHERE s.id = $id
        LIMIT 1";
$row = $mysqli->query($sql)->fetch_assoc();

if (!$row) jsonResponse(['success' => false, 'error' => 'Устройство не найдено']);
if (empty($row['login']) || empty($row['pas'])) {
    jsonResponse(['success' => false, 'error' => 'Не найдены учётные данные']);
}

$login = $row['login'];
$pass  = decryptPassword($row['pas'], $config['key']['master_key']);
if (empty($pass)) jsonResponse(['success' => false, 'error' => 'Не удалось расшифровать пароль']);
$ip = $row['ip'];

// 1. Текущее состояние загрузчика
$rb = mikrotikApi($ip, $login, $pass, 'system/routerboard');
if (isset($rb['error'])) {
    jsonResponse(['success' => false, 'error' => 'Не удалось подключиться: ' . $rb['error']]);
}

$currentFw = $rb['current-firmware'] ?? 'unknown';
$upgradeFw = $rb['upgrade-firmware'] ?? 'unknown';

// 2. Обновлять нечего
if ($currentFw === $upgradeFw) {
    jsonResponse([
        'success'  => true,
        'message'  => 'Загрузчик уже актуален',
        'hostname' => $row['hostname'],
        'version'  => $currentFw
    ]);
}

// 3. Запускаем обновление через execute (команда не работает через GET)
$result = mikrotikApi($ip, $login, $pass, 'execute', [
    'script' => ':execute "/system routerboard upgrade"'
]);
if (isset($result['error'])) {
    jsonResponse(['success' => false, 'error' => 'Ошибка обновления: ' . $result['error']]);
}

// 4. Ребут применит обновление
mikrotikApi($ip, $login, $pass, 'execute', [
    'script' => ':execute "/system reboot"'
]);

jsonResponse([
    'success'  => true,
    'message'  => 'Загрузчик обновлён, роутер перезагружается',
    'hostname' => $row['hostname'],
    'from'     => $currentFw,
    'to'       => $upgradeFw
]);
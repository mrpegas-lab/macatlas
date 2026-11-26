<?php
set_time_limit(0);
ignore_user_abort(true);
require_once dirname(__DIR__, 2) . '/loadinc.php';

function jsonResponse($data)
{
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
if (!$id) {
    jsonResponse(['success' => false, 'error' => 'Не передан ID устройства']);
}
$id = (int)$id;

// ---------- Устройство и креды ----------
$sql = "SELECT d.id, d.hostname, d.ip, c.login, c.pas
        FROM mt_devices d
        LEFT JOIN mt_sites s     ON s.locations_id = d.locations_id
        LEFT JOIN credentials c  ON c.id = s.cred_id
        WHERE s.id = $id
        LIMIT 1";
$row = $mysqli->query($sql)->fetch_assoc();

if (!$row) {
    jsonResponse(['success' => false, 'error' => 'Устройство не найдено']);
}
if (empty($row['login']) || empty($row['pas'])) {
    jsonResponse(['success' => false, 'error' => 'Не найдены учётные данные']);
}

$login = $row['login'];
$pass  = decryptPassword($row['pas'], $config['key']['master_key']);
if (empty($pass)) {
    jsonResponse(['success' => false, 'error' => 'Не удалось расшифровать пароль']);
}
$ip = $row['ip'];

// ---------- Конфиг и целевая версия ----------
$fwUrl = rtrim($config['FIRMWARE']['URL'] ?? '', '/') . '/';
$fwDir = rtrim($config['FIRMWARE']['DIR'] ?? '', '/') . '/';

if (empty($config['FIRMWARE']['URL']) || empty($config['FIRMWARE']['DIR'])) {
    jsonResponse(['success' => false, 'error' => 'Не настроены параметры FIRMWARE']);
}

$versionFile = $fwDir . 'version.txt';
if (!is_file($versionFile)) {
    jsonResponse(['success' => false, 'error' => 'Не найден version.txt']);
}

$targetVersion = trim(file_get_contents($versionFile));

// ---------- Инфо о роутере ----------
$info = mikrotikApi($ip, $login, $pass, 'system/resource');
if (isset($info['error'])) {
    jsonResponse(['success' => false, 'error' => 'Не удалось подключиться: ' . $info['error']]);
}

$currentVersion = $info['version'] ?? 'unknown';
$arch           = $info['architecture-name'] ?? 'unknown';
$currentClean   = preg_replace('/\s*\(.*$/', '', $currentVersion);

if (version_compare($currentClean, $targetVersion, '>=')) {
    jsonResponse([
        'success'  => true,
        'done'     => true,
        'message'  => 'Уже актуальная версия',
        'hostname' => $row['hostname'],
    ]);
}

$fwFileName = "routeros-{$targetVersion}-{$arch}.npk";
if (!is_file($fwDir . $fwFileName)) {
    jsonResponse(['success' => false, 'error' => "Файл прошивки не найден: $fwFileName"]);
}

// ---------- Отправляем fetch (мгновенно) ----------
$fetch = mikrotikApi($ip, $login, $pass, 'tool/fetch', [
    'url'               => $fwUrl . $fwFileName,
    'dst-path'          => $fwFileName,
    'mode'              => 'http',
    'check-certificate' => 'no',
]);
if (isset($fetch['error'])) {
    jsonResponse(['success' => false, 'error' => 'Ошибка fetch: ' . $fetch['error']]);
}

// ---------- Сразу отвечаем: «команда отправлена» ----------
jsonResponse([
    'success'  => true,
    'accepted' => true,
    'message'  => 'Команда отправлена, идёт загрузка',
    'hostname' => $row['hostname'],
    'file'     => $fwFileName,
    'from'     => $currentVersion,
    'to'       => $targetVersion,
    'id'       => $id,
]);
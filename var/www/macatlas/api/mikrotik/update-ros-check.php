<?php
set_time_limit(30);
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
    jsonResponse(['success' => false, 'error' => 'Не передан ID']);
}
$id = (int)$id;

// ---------- Устройство ----------
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

$login = $row['login'];
$pass  = decryptPassword($row['pas'], $config['key']['master_key']);
$ip    = $row['ip'];

// ---------- Конфиг и версия ----------
$fwDir         = rtrim($config['FIRMWARE']['DIR'] ?? '', '/') . '/';
$targetVersion = trim(file_get_contents($fwDir . 'version.txt'));

// ---------- Архитектура ----------
$info = mikrotikApi($ip, $login, $pass, 'system/resource');
if (isset($info['error'])) {
    jsonResponse(['success' => false, 'error' => 'Нет связи с роутером: ' . $info['error']]);
}
$arch = $info['architecture-name'] ?? 'unknown';

$fwFileName   = "routeros-{$targetVersion}-{$arch}.npk";
$expectedSize = filesize($fwDir . $fwFileName);

// ---------- Проверяем файл на роутере ----------
$res   = mikrotikApi($ip, $login, $pass, 'file');
$items = [];
if (is_array($res)) {
    $items = isset($res['name']) ? [$res] : $res;
}

$found = null;
$names = [];
foreach ($items as $it) {
    if (!isset($it['name'])) {
        continue;
    }
    $names[] = $it['name'];
    if ($it['name'] === $fwFileName) {
        $found = $it;
    }
}

if (!$found) {
    jsonResponse([
        'success'         => true,
        'done'            => false,
        'message'         => 'Файл ещё загружается...',
        'expected'        => $fwFileName,
        'arch'            => $arch,
        'found_on_router' => $names,
        // === ДИАГНОСТИКА (убрать потом) ===
        'raw_res'         => $res,
        'raw_items'       => $items,
        'items_type'      => gettype($items),
        'items_count'     => is_array($items) ? count($items) : null,
    ]);
}

// Файл есть, но размер не тот — тоже ждём (MikroTik может показать неполный размер)
if (!empty($found['size']) && (int)$found['size'] !== $expectedSize) {
    jsonResponse([
        'success' => true,
        'done'    => false,
        'message' => 'Загрузка идёт: ' . (int)$found['size'] . ' / ' . $expectedSize . ' Б',
    ]);
}

// ---------- Файл на месте и размер совпал ----------
$isTest = (($config['FIRMWARE']['MODE'] ?? 'prod') === 'test');

if ($isTest) {
    jsonResponse([
        'success'  => true,
        'done'     => true,
        'dry_run'  => true,
        'message'  => 'Тест: файл загружен, перезагрузка не выполнена',
        'hostname' => $row['hostname'],
        'from'     => $info['version'] ?? '?',
        'to'       => $targetVersion,
    ]);
}

// ---------- Боевой режим: ребут + запись в БД ----------
mikrotikApi($ip, $login, $pass, 'execute', [
    'script' => ':execute "/system reboot"'
]);

$fwVerSafe = $mysqli->real_escape_string($targetVersion);
$mysqli->query("UPDATE mt_devices SET upgrade_firmware = '$fwVerSafe' WHERE id = $id");

jsonResponse([
    'success'  => true,
    'done'     => true,
    'dry_run'  => false,
    'message'  => 'Прошивка загружена, роутер перезагружается',
    'hostname' => $row['hostname'],
    'from'     => $info['version'] ?? '?',
    'to'       => $targetVersion,
]);
<?php
// Абсолютный путь: работает и из веб, и из консоли (CWD не важен)
require_once dirname(__DIR__, 2) . '/loadinc.php';

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================
// Функции MikroTik
// ============================================

function getMacAddress($ip, $login, $pass) {
    $interfaces = mikrotikApi($ip, $login, $pass, 'interface');
    if (isset($interfaces['error'])) return null;

    $mac = null;
    foreach ($interfaces as $interface) {
        if (strpos($interface['name'] ?? '', 'ether') === 0) {
            $mac = $interface['mac-address'] ?? null;
            break;
        }
    }
    if (!$mac) {
        foreach ($interfaces as $interface) {
            if (!empty($interface['mac-address'])) {
                $mac = $interface['mac-address'];
                break;
            }
        }
    }
    return $mac;
}

function checkEther2Status($ip, $login, $pass) {
    $interfaces = mikrotikApi($ip, $login, $pass, 'interface');
    if (isset($interfaces['error'])) return false;

    foreach ($interfaces as $interface) {
        if (($interface['name'] ?? '') === 'ether2') {
            $running  = $interface['running']  ?? false;
            $disabled = $interface['disabled'] ?? false;
            return ($running === true || $running === 'true')
                && ($disabled === false || $disabled === 'false');
        }
    }
    return false;
}

function checkLteModem($ip, $login, $pass) {
    $interfaces = mikrotikApi($ip, $login, $pass, 'interface');
    if (!isset($interfaces['error'])) {
        foreach ($interfaces as $interface) {
            if (stripos($interface['name'] ?? '', 'lte') === 0) return true;
        }
    }
    $lteInfo = mikrotikApi($ip, $login, $pass, 'interface/lte');
    return !isset($lteInfo['error']) && !empty($lteInfo);
}

function checkRadius($ip, $login, $pass) {
    $radius = mikrotikApi($ip, $login, $pass, 'radius');
    if (isset($radius['error'])) return false;

    foreach ($radius as $server) {
        $disabled = $server['disabled'] ?? false;
        if ($disabled === false || $disabled === 'false') return true;
    }
    return false;
}

function parseMikrotikUptime(string $uptime): int {
    $seconds = 0;
    preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $v = (int)$m[1];
        switch ($m[2]) {
            case 'w': $seconds += $v * 604800; break;
            case 'd': $seconds += $v * 86400;  break;
            case 'h': $seconds += $v * 3600;   break;
            case 'm': $seconds += $v * 60;     break;
            case 's': $seconds += $v;          break;
        }
    }
    return $seconds;
}

// --- проверка прав (только веб; CLI-запуски из cron/Ansible пропускаем) ---
// Разрешено: право «выполнение» в модуле обновления (кнопка «Собрать данные»)
// или право «редактирование» в конфигураторе объектов
if (PHP_SAPI !== 'cli' && !acl('mt_update', 'exec') && !acl('mt_config', 'edit')) {
    jsonResponse(['success' => false, 'error' => 'Нет прав на выполнение этого действия']);
}

// ============================================
// MAIN
// ============================================

// ============================================
// ВХОДНЫЕ ДАННЫЕ
// Веб:  POST/GET id (mt_sites.id) — как раньше
// CLI:  --id=123  или  --hostname=имя  (из ansible/playbook)
// ============================================
$id       = $_POST['id'] ?? $_GET['id'] ?? null;
$hostname = null;

if (PHP_SAPI === 'cli') {
    foreach ($argv as $arg) {
        if (strpos($arg, '--id=') === 0)       $id       = substr($arg, 5);
        if (strpos($arg, '--hostname=') === 0) $hostname = substr($arg, 11);
    }
}

if (!$id && !$hostname) {
    jsonResponse(['success' => false, 'error' => 'Device ID or hostname required']);
}

// 1. Устройство + учётки
if ($hostname) {
    // Поиск по имени (запуск из консоли/ansible).
    // inventory_hostname — это либо hostname из mt_devices,
    // либо new_name локации (site_mikrotik.yml от ansible.py)
    $hostname = $mysqli->real_escape_string($hostname);
    $sql = "SELECT d.id, d.locations_id, d.hostname, d.ip, c.login, c.pas
            FROM mt_devices d
            LEFT JOIN mt_sites s    ON s.locations_id = d.locations_id
            LEFT JOIN locations l   ON l.id = d.locations_id
            LEFT JOIN credentials c ON c.id = s.cred_id
            WHERE d.hostname = '$hostname'
               OR l.new_name = '$hostname'
            LIMIT 1";
} else {
    // Поиск по id записи mt_sites (запуск из веб-интерфейса)
    $id = (int)$id;
    $sql = "SELECT d.id, d.locations_id, d.hostname, d.ip, c.login, c.pas
            FROM mt_devices d
            LEFT JOIN mt_sites s    ON s.locations_id = d.locations_id
            LEFT JOIN credentials c ON c.id = s.cred_id
            WHERE s.id = $id
            LIMIT 1";
}
$res = $mysqli->query($sql);
$row = $res ? $res->fetch_assoc() : null;

if (!$row) jsonResponse(['success' => false, 'error' => 'Device not found']);
$deviceId = (int)$row['id'];   // ID из mt_devices — нужен для UPDATE

if (empty($row['login']) || empty($row['pas'])) {
    jsonResponse(['success' => false, 'error' => 'Credentials not found']);
}

$login = $row['login'];
$pass  = decryptPassword($row['pas']);
if (empty($pass)) jsonResponse(['success' => false, 'error' => 'Password decrypt failed']);

$ip          = $row['ip'];
$locationsId = (int)$row['locations_id'];

// 2. Данные с роутера
$system = mikrotikApi($ip, $login, $pass, 'system/resource');
if (isset($system['error'])) {
    $err = $mysqli->real_escape_string('System: ' . $system['error']);
    $mysqli->query("UPDATE mt_devices SET status='error', error_message='$err', last_check=NOW() WHERE id=$deviceId");
    jsonResponse(['success' => false, 'error' => 'Нет связи с роутером']);
}

$routerboard = mikrotikApi($ip, $login, $pass, 'system/routerboard');
if (isset($routerboard['error'])) {
    $err = $mysqli->real_escape_string('Routerboard: ' . $routerboard['error']);
    $mysqli->query("UPDATE mt_devices SET status='error', error_message='$err', last_check=NOW() WHERE id=$deviceId");
    jsonResponse(['success' => false, 'error' => 'Ошибка чтения routerboard']);
}

$mac = getMacAddress($ip, $login, $pass);

$lteRouter = checkEther2Status($ip, $login, $pass) ? 1 : 0;
$usbModem  = checkLteModem($ip, $login, $pass)     ? 1 : 0;
$radius    = checkRadius($ip, $login, $pass)       ? 1 : 0;

// 3. mt_sites
if ($locationsId) {
    $stmt = $mysqli->prepare("UPDATE mt_sites SET lte_router = ?, usb_modem = ? WHERE locations_id = ?");
    $stmt->bind_param('iii', $lteRouter, $usbModem, $locationsId);
    $stmt->execute();
    $stmt->close();
}

// 4. mt_devices
$boardName    = $system['board-name']    ?? null;
$version      = $system['version']       ?? null;
$cpuCount     = isset($system['cpu-count'])    ? (int)$system['cpu-count']    : null;
$cpuFrequency = $system['cpu-frequency'] ?? null;
$totalMemory  = isset($system['total-memory']) ? (int)$system['total-memory'] : null;
$freeMemory   = isset($system['free-memory'])  ? (int)$system['free-memory']  : null;
$uptime       = parseMikrotikUptime($system['uptime'] ?? '0s');

$model           = $routerboard['model']            ?? null;
$currentFirmware = $routerboard['current-firmware'] ?? null;
$upgradeFirmware = $routerboard['upgrade-firmware'] ?? null;
$serialNumber    = $routerboard['serial-number']    ?? null;

$stmt = $mysqli->prepare("
    UPDATE mt_devices SET
        mac = ?, board_name = ?, version = ?,
        cpu_count = ?, cpu_frequency = ?,
        total_memory = ?, free_memory = ?, uptime = ?,
        model = ?, current_firmware = ?, upgrade_firmware = ?, serial_number = ?,
        status = 'ok', error_message = NULL, radius = ?,
        last_check = NOW()
    WHERE id = ?
");
$stmt->bind_param(
    'ssssssssisssii',
    $mac, $boardName, $version,
    $cpuCount, $cpuFrequency,
    $totalMemory, $freeMemory, $uptime,
    $model, $currentFirmware, $upgradeFirmware, $serialNumber,
    $radius,
    $deviceId
);

if (!$stmt->execute()) {
    jsonResponse(['success' => false, 'error' => 'DB update failed']);
}
$stmt->close();

jsonResponse(['success' => true]);
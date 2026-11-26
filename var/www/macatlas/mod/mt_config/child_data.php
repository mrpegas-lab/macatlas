<?php
include_once("../../loadinc.php");

// Получаем ID салона
$id = isset($_GET['parent_id']) ? intval($_GET['parent_id']) : 0;

if (!$id) {
    echo '<div class="alert alert-warning">⚠️ Не передан ID салона</div>';
    exit;
}

// Получаем данные MikroTik устройств
$query = "SELECT 
            ms.locations_id,
            ms.lte_router,
            ms.usb_modem,
            md.id as device_id,
            md.hostname,
            md.ip,
            md.mac, 
            md.board_name, 
            md.version, 
            md.cpu_count, 
            md.cpu_frequency, 
            md.total_memory, 
            md.free_memory, 
            md.uptime, 
            md.model, 
            md.current_firmware, 
            md.upgrade_firmware, 
            md.serial_number, 
            md.last_check, 
            md.status as device_status,
            md.error_message,
            md.radius
          FROM mt_sites ms
          LEFT JOIN locations l ON l.id = ms.locations_id
          LEFT JOIN mt_devices md ON ms.locations_id = md.locations_id
          WHERE ms.id = ?
          ";

$stmt = $mysqli->prepare($query);
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$devices = [];
while ($row = $result->fetch_assoc()) {
    $devices[] = $row;
}
$stmt->close();

// Если данных нет
if (empty($devices)) {
    echo '<div class="text-center text-muted p-3">📭 Нет данных MikroTik для этого салона</div>';
    exit;
}

// Функция форматирования байт
function formatBytes($bytes) {
    if (!$bytes || $bytes == 0) return 'N/A';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 1) . ' ' . $sizes[$i];
}


function secondsToHuman($seconds) {
    if (empty($seconds) || $seconds == 0) {
        return 'N/A';
    }
    
    $seconds = (int) $seconds;
    $days = floor($seconds / 86400);
    
    if ($days == 0) {
        return 'Меньше дня';
    }
    
    // Склонение
    $word = 'дней';
    if ($days % 10 == 1 && $days % 100 != 11) {
        $word = 'день';
    } elseif ($days % 10 >= 2 && $days % 10 <= 4 && ($days % 100 < 10 || $days % 100 >= 20)) {
        $word = 'дня';
    }
    
    return $days . ' ' . $word;
}


// ------------------------------------------------------------------
// ВСЯ ЛОГИКА ПОДГОТОВКИ ДАННЫХ — ЗДЕСЬ
// ------------------------------------------------------------------
$preparedDevices = [];

foreach ($devices as $device) {
    // Процессор
    $cpuInfo = 'N/A';
    if ($device['cpu_count'] && $device['cpu_frequency']) {
        $cpuInfo = $device['cpu_count'] . 'x ' . htmlspecialchars($device['cpu_frequency']) . ' МГц';
    }

    // Оперативная память
    $memoryInfo = 'N/A';
    if ($device['total_memory']) {
        $memoryInfo = formatBytes($device['total_memory']);
        if ($device['free_memory']) {
            $memoryInfo .= ' <span style="color: #888; font-size: 11px; font-weight: 400;">свободно ' . formatBytes($device['free_memory']) . '</span>';
        }
    }

    // Прошивка
    $firmwareInfo = 'N/A';
    if ($device['current_firmware']) {
        $firmwareInfo = htmlspecialchars($device['current_firmware']);
        if ($device['upgrade_firmware'] && $device['upgrade_firmware'] != $device['current_firmware']) {
            $firmwareInfo .= ' <span class="badge bg-warning text-dark" style="font-size: 10px;">⬆️ ' . htmlspecialchars($device['upgrade_firmware']) . '</span>';
        }
    }

    // Время проверки
    $lastCheckFormatted = date('d.m.Y H:i:s', strtotime($device['last_check']));
    $seconds = secondsToHuman($device['uptime']);

    // Статус LTE роутера (ether2 линкует)
    $lteRouterStatus = ($device['lte_router'] == 1) ? '✅ Да' : '❌ Нет';
    
    // Статус USB модема
    $usbModemStatus = ($device['usb_modem'] == 1) ? '✅ Да' : '❌ Нет';
    
    // Статус RADIUS
    $radiusStatus = ($device['radius'] == 1) ? '✅ Да' : '❌ Нет';

    $preparedDevices[] = [
        'id'                  => $device['id'],
        'model'               => htmlspecialchars($device['model'] ?? 'Неизвестная модель'),
        'hostname'            => htmlspecialchars($device['hostname'] ?? ''),
        'ip'                  => htmlspecialchars($device['ip'] ?? ''),
        'version'             => htmlspecialchars($device['version'] ?? 'N/A'),
        'mac'                 => htmlspecialchars($device['mac'] ?? 'N/A'),
        'serial_number'       => htmlspecialchars($device['serial_number'] ?? 'N/A'),
        'board_name'          => htmlspecialchars($device['board_name'] ?? 'N/A'),
        'uptime'              => $seconds,
        'cpuInfo'             => $cpuInfo,
        'memoryInfo'          => $memoryInfo,
        'firmwareInfo'        => $firmwareInfo,
        'lastCheckFormatted'  => $lastCheckFormatted,
        'hasError'            => ($device['device_status'] == 'error' && !empty($device['error_message'])),
        'errorMessage'        => htmlspecialchars($device['error_message'] ?? ''),
        'lteRouterStatus'     => $lteRouterStatus,
        'usbModemStatus'      => $usbModemStatus,
        'radiusStatus'        => $radiusStatus,
    ];
}
?>


<div style="padding: 8px;">
    <?php foreach ($preparedDevices as $device): ?>
    <div class="mt-device-card">
        <!-- Шапка -->
        <div class="mt-device-header">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="mt-device-model"><?= $device['model'] ?></span>
                <?php if ($device['hostname']): ?>
                <span class="mt-device-hostname"><?= $device['hostname'] ?></span>
                <?php endif; ?>
                <?php if ($device['ip']): ?>
                <span class="mt-device-ip"><?= $device['ip'] ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Тело -->
       <div class="mt-device-body">
    <div class="row">
        <div class="col-sm-6">
            <div class="mt-stat-item">
                <span class="mt-stat-label">Модель</span>
                <span class="mt-stat-value"><?= $device['board_name'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">Версия ROS</span>
                <span class="mt-stat-value font-monospace"><?= $device['version'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">Процессор</span>
                <span class="mt-stat-value"><?= $device['cpuInfo'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">Оперативная память</span>
                <span class="mt-stat-value"><?= $device['memoryInfo'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">Прошивка</span>
                <span class="mt-stat-value"><?= $device['firmwareInfo'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">MAC адрес</span>
                <span class="mt-stat-value font-monospace"><?= $device['mac'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">Серийный номер</span>
                <span class="mt-stat-value font-monospace"><?= $device['serial_number'] ?></span>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="mt-stat-item">
                <span class="mt-stat-label">Время работы</span>
                <span class="mt-stat-value"><?= $device['uptime'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">🔌 LTE роутер (ether2)</span>
                <span class="mt-stat-value"><?= $device['lteRouterStatus'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">📶 USB модем</span>
                <span class="mt-stat-value"><?= $device['usbModemStatus'] ?></span>
            </div>
            <div class="mt-stat-item">
                <span class="mt-stat-label">🔐 RADIUS</span>
                <span class="mt-stat-value"><?= $device['radiusStatus'] ?></span>
            </div>
        </div>
    </div>

    <!-- Ошибка -->
    <?php if ($device['hasError']): ?>
    <div class="mt-error-box">
        ❌ <?= $device['errorMessage'] ?>
    </div>
    <?php endif; ?>
</div>

        <!-- Футер с кнопками -->
    <div class="mt-device-footer">
        <span>🕐 Проверка: <?= $device['lastCheckFormatted'] ?></span>
        <?php
        // Действия на устройстве (сбор инфо, перезагрузка, обновления) — право «редактирование»
        $dis_actions = $can_edit ? '' : 'disabled title="Нет прав на выполнение действий с устройством"';
        ?>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-info btn-sm mt-action-btn" data-action="collect-info" data-id="<?= (int)$id  ?>" data-hostname="<?= htmlspecialchars($device['hostname']) ?>" <?= $dis_actions ?>>
            🔍 Собрать инфо
            </button>
            <button class="btn btn-outline-warning btn-sm mt-action-btn" data-action="reboot"  data-id="<?= (int)$id ?>"  data-hostname="<?= htmlspecialchars($device['hostname']) ?>" <?= $dis_actions ?>>
            🔄 Перезагрузить
            </button>
            <button class="btn btn-outline-primary btn-sm mt-action-btn" data-action="update-ros" data-id="<?= (int)$id ?>" data-hostname="<?= htmlspecialchars($device['hostname']) ?>" <?= $dis_actions ?>>
            ⬆️ Обновить ROS
            </button>
            <button class="btn btn-outline-secondary btn-sm mt-action-btn" data-action="update-firmware" data-id="<?= (int)$id ?>" data-hostname="<?= htmlspecialchars($device['hostname']) ?>" <?= $dis_actions ?>>
            📦 Обновить Firmware
            </button>
        </div>
    <span>ID: <?= (int)$id ?></span>
</div>
    </div>
    <?php endforeach; ?>
</div>
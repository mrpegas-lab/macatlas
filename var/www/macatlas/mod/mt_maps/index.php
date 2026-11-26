<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23)
if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}



// Получаем данные салонов
$query = "SELECT ms.id, l.code, l.name, l.new_name, l.address, l.gps, ms.ip1, ms.lan, ms.lte_router, ms.usb_modem, l.comment 
FROM mt_sites ms
LEFT JOIN locations l ON l.id = ms.locations_id
WHERE l.gps IS NOT NULL AND l.gps != ''";
$result = $mysqli->query($query);

$salons = [];
while ($row = $result->fetch_assoc()) {
    list($lat, $lng) = explode(',', $row['gps']);
    
    // Получаем данные из mt_devices для этого салона
    $mikrotik_query = "SELECT board_name, version, mac, cpu_count, cpu_frequency, total_memory, free_memory, uptime, model, current_firmware, upgrade_firmware, serial_number, last_check, status as device_status, error_message 
                       FROM mt_devices 
                       WHERE locations_id = ? 
                       ORDER BY last_check DESC 
                       LIMIT 1";
    
    $stmt = $mysqli->prepare($mikrotik_query);
    $stmt->bind_param('i', $row['id']);
    $stmt->execute();
    $mikrotik_result = $stmt->get_result();
    $mikrotik_data = $mikrotik_result->fetch_assoc();
    $stmt->close();
    
    $salons[] = [
        'id' => $row['id'],
        'lat' => floatval($lat),
        'lng' => floatval($lng),
        'ip1' => $row['ip1'],
        'WAN_IP_NOMASK' => explode('/', $row['ip1'])[0],
        'lan' => $row['lan'],
        'lte_router' => $row['lte_router'],
        'usb_modem' => $row['usb_modem'],
        'code' => $row['code'],
        'new_name' => $row['new_name'],
        'name' => $row['name'],
        'address' => $row['address'],
        'comment' => $row['comment'],
        'mikrotik' => $mikrotik_data // Добавляем данные MikroTik
    ];
}
?>

<div class="row g-0">
    <div class="col-md-10">
        <div id="map"></div>
    </div>
    <div class="col-md-2 ps-2">
        <input type="text" id="searchInput" class="form-control form-control-sm mb-2" placeholder="Поиск...">
        <div id="salonList" style="height: calc(100vh - 165px); overflow-y: auto;">
            <?php foreach ($salons as $index => $salon): ?>
            <div class="salon-item p-1 border-bottom"
                 data-id="<?= $salon['id'] ?>"
                 data-lat="<?= $salon['lat'] ?>"
                 data-lng="<?= $salon['lng'] ?>"
                 data-name="<?= htmlspecialchars(mb_strtolower($salon['code'] . ' ' . ($salon['name'] .' '. $salon['new_name'] ?? ''))) ?>">
                <div class="fw-bold small"><?= htmlspecialchars($salon['code'] . ' ' . $salon['name']) ?></div>
                <div class="small"><?= htmlspecialchars($salon['new_name'] ?? 'Без названия') ?></div>
                <div class="small text-muted"><?= htmlspecialchars(mb_substr($salon['address'], 0, 50)) ?></div>
                <?php if ($salon['mikrotik'] && $salon['mikrotik']['device_status'] == 'ok'): ?>
                <div class="small text-muted mt-1">
                    <i class="bi bi-router"></i> <?= htmlspecialchars($salon['mikrotik']['model'] ?? 'N/A') ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-1 small text-muted">
            Всего: <span id="countTotal">0</span>
        </div>
    </div>
</div>

<script>
var map;
var markers = [];
var allSalons = <?= json_encode($salons) ?>;
var currentSearch = '';

function buildPopupContent(salon) {
    var html = '<div style="min-width:280px">' +
        '<div class="fw-bold">' + escapeHtml(salon.code) + ' ' + escapeHtml(salon.name) + '</div>' +
        '<div class="small ">' + (salon.new_name ? escapeHtml(salon.new_name) : 'Без названия') + '</div>' +
        '<div class="small  mb-1">' + (salon.address ? escapeHtml(salon.address) : '') + '</div>';

    // Блок с сетевой информацией
    var hasNetwork = salon.ip1 || salon.lan || salon.lte_router || salon.usb_modem || salon.comment;
    if (hasNetwork) {
        html += '<hr class="my-2">';

        if (salon.ip1) {
            html += '<div class="d-flex justify-content-between small mb-1"><span>🌐 IP:&nbsp</span><span class="fw-medium">' + escapeHtml(salon.ip1) + '</span></div>';
        }
        if (salon.mikrotik && salon.mikrotik.device_status) {
            html += '<div class="d-flex justify-content-between small mb-1"><span>🌐 MAC:&nbsp</span><span class="fw-medium">' + escapeHtml(salon.mikrotik.mac || 'N/A') + '</span></div>';
        }

        if (salon.lan) {
            html += '<div class="d-flex justify-content-between small mb-1"><span>🔌 LAN:&nbsp</span><span class="fw-medium">' + escapeHtml(salon.lan) + '</span></div>';
        }

        if (salon.lte_router && salon.lte_router != '0' && salon.lte_router != '') {
            html += '<div class="d-flex justify-content-between small mb-1"><span>📶 LTE-роутер:&nbsp</span><span class="fw-medium">Есть</span></div>';
        }

        if (salon.usb_modem && salon.usb_modem != '0' && salon.usb_modem != '') {
            html += '<div class="d-flex justify-content-between small mb-1"><span>🔑 USB-модем:&nbsp</span><span class="fw-medium">Есть</span></div>';
        }
        
        // Комментарий
        if (salon.comment) {
            html += '<div class="small mt-1 p-2 bg-light rounded"><span class="me-1">💬</span>' + escapeHtml(salon.comment) + '</div>';
        }
    }

    // Блок с данными MikroTik (если есть)
    if (salon.mikrotik && salon.mikrotik.device_status) {
        html += '<div class="mikrotik-info">';
        html += '<div class="fw-bold mb-1"><i class="bi bi-router"></i> MikroTik устройство</div>';
        html += '<div><span class="label">Модель:</span><span class="value">' + escapeHtml(salon.mikrotik.model || 'N/A') + '</span></div>';
        html += '<div><span class="label">Board:</span><span class="value">' + escapeHtml(salon.mikrotik.board_name || 'N/A') + '</span></div>';
        html += '<div><span class="label">Версия ROS:</span><span class="value">' + escapeHtml(salon.mikrotik.version || 'N/A') + '</span></div>';
        html += '<div><span class="label">Прошивка:</span><span class="value">' + escapeHtml(salon.mikrotik.current_firmware || 'N/A') + '</span></div>';
        html += '<div><span class="label">CPU:</span><span class="value">' + escapeHtml(salon.mikrotik.cpu_count || 'N/A') + ' x ' + escapeHtml(salon.mikrotik.cpu_frequency || 'N/A') + ' MHz</span></div>';
        html += '<div><span class="label">Память:</span><span class="value">' + formatBytes(salon.mikrotik.total_memory) + ' (свободно: ' + formatBytes(salon.mikrotik.free_memory) + ')</span></div>';
        html += '<div><span class="label">Uptime:</span><span class="value">' + escapeHtml(salon.mikrotik.uptime || 'N/A') + '</span></div>';
        html += '<div><span class="label">Serial:</span><span class="value small">' + escapeHtml(salon.mikrotik.serial_number || 'N/A') + '</span></div>';
        html += '<hr>';
        html += '<div><span class="label">Последний сбор:</span><span class="value small">' + escapeHtml(salon.mikrotik.last_check || 'N/A') + '</span></div>';

        if (salon.mikrotik.device_status == 'error') {
            html += '<div class="device-status-error">❌ Ошибка: ' + escapeHtml(salon.mikrotik.error_message || 'Неизвестная ошибка') + '</div>';
        }
        
        html += '</div>';
    } else if (salon.mikrotik && salon.mikrotik.device_status === undefined) {
        html += '<div class="mikrotik-info text-muted">';
        html += '<i class="bi bi-info-circle"></i> Данные MikroTik не собраны';
        html += '</div>';
    }

    html += '</div>';
    return html;
}

function formatBytes(bytes) {
    if (!bytes || bytes == 0) return 'N/A';
    var k = 1024;
    var sizes = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// escapeHtml — общая реализация в /plugins/crud.js (аудит 2026-11-23, Н1)
function initMap() {
    var mapEl = document.getElementById('map');
    if (!mapEl || typeof L === 'undefined') return;

    map = L.map('map', {
        attributionControl: false
    }).setView([55.751574, 37.573856], 6);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19
    }).addTo(map);

    setTimeout(function() { map.invalidateSize(); }, 300);

    // Все салоны переведены — маркеры единого цвета
    allSalons.forEach(function(salon, index) {
        var marker = L.marker([salon.lat, salon.lng], {
            icon: L.divIcon({
                html: '<div style="background:#0d6efd;width:12px;height:12px;border-radius:50%;border:2px solid white;"></div>',
                iconSize: [12, 12],
                iconAnchor: [6, 6],
                className: ''
            })
        }).bindPopup(buildPopupContent(salon)).addTo(map);
        markers.push(marker);
    });

    // Клик по списку
    document.querySelectorAll('.salon-item').forEach(function(item, idx) {
        item.addEventListener('click', function() {
            map.setView([parseFloat(item.dataset.lat), parseFloat(item.dataset.lng)], 15);
            if (markers[idx]) markers[idx].openPopup();
        });
    });

    // Поиск
    document.getElementById('searchInput').addEventListener('input', function() {
        currentSearch = this.value.toLowerCase();
        applyFilter();
    });

    applyFilter();
}

function applyFilter() {
    var items = document.querySelectorAll('.salon-item');
    var visible = 0;

    items.forEach(function(item, idx) {
        var name = item.dataset.name || '';
        var show = currentSearch === '' || name.includes(currentSearch);

        item.style.display = show ? '' : 'none';

        if (markers[idx]) {
            if (show) {
                markers[idx].addTo(map);
            } else {
                markers[idx].remove();
            }
        }

        if (show) visible++;
    });

    document.getElementById('countTotal').textContent = visible;
}

initMap();
</script>

<?php
// Данные карты сети: узлы и линки (JSON для vis-network)
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

// список файлов иконок из custom/
require_once __DIR__ . '/icons_lib.php';

// Список всех карт + текущая карта из запроса
$maps = [];
$res = $mysqli->query("SELECT id, name FROM maps ORDER BY id");
while ($r = $res->fetch_assoc()) {
    $maps[] = ['id' => (int)$r['id'], 'name' => $r['name']];
}
$MAP_ID = intval($_GET['map_id'] ?? 0);
if ($MAP_ID <= 0 || !in_array($MAP_ID, array_column($maps, 'id'), true)) {
    $MAP_ID = $maps[0]['id'] ?? 1;
}

// Настройки отображения этой карты (если таблицы/записи нет — значения по умолчанию)
$settings = ['font_size' => 13, 'icon_size' => 24, 'icon_img_max' => 96, 'show_model' => 1, 'show_ip' => 0,
             'show_firmware' => 0, 'show_uptime' => 0, 'show_temp' => 0, 'show_comment' => 0];
$stmt = $mysqli->prepare("SELECT font_size, icon_size, icon_img_max, show_model, show_ip, show_firmware, show_uptime, show_temp, show_comment, style FROM map_settings WHERE map_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $MAP_ID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $settings = [
            'font_size'     => (int)$row['font_size'],
            'icon_size'     => (int)$row['icon_size'],
            'icon_img_max'  => (int)$row['icon_img_max'],
            'show_model'    => (int)$row['show_model'],
            'show_ip'       => (int)$row['show_ip'],
            'show_firmware' => (int)$row['show_firmware'],
            'show_uptime'   => (int)$row['show_uptime'],
            'show_temp'     => (int)$row['show_temp'],
            'show_comment'  => (int)($row['show_comment'] ?? 0),
            'style'         => $row['style'] ? json_decode($row['style'], true) : null,
        ];
    }
}

// Глобальные настройки: флаг мониторинга — служебный (app_settings),
// период обновления карты — ручная настройка (settings)
$monEnabled = true;
$monEnabledAt = 0;   // момент последнего включения мониторинга (unixtime)
$refreshSec = 60;
$chk = $mysqli->query("SHOW TABLES LIKE 'app_settings'");
if ($chk && $chk->num_rows) {
    $res = $mysqli->query("SELECT value FROM app_settings WHERE name = 'monitoring_enabled'");
    if ($res && ($r = $res->fetch_assoc())) {
        $monEnabled = trim((string)$r['value']) !== '0';
    }
    $res = $mysqli->query("SELECT value FROM app_settings WHERE name = 'monitoring_enabled_at'");
    if ($res && ($r = $res->fetch_assoc())) {
        $monEnabledAt = (int)$r['value'];
    }
}
$chk = $mysqli->query("SHOW TABLES LIKE 'settings'");
if ($chk && $chk->num_rows) {
    $res = $mysqli->query("SELECT value FROM settings WHERE `key` = 'maps_refresh_sec'");
    if ($res && ($r = $res->fetch_assoc())) {
        $refreshSec = max(15, min(600, (int)$r['value']));
    }
}
// состояние старше этого срока считаем неизвестным (мониторинг не бегает)
define('MAP_STATE_STALE_SEC', 300);

// Узлы: из map_nodes + подтягиваем данные железа (модель, IP) и живое состояние (ping)
$nodes = [];
$stmt = $mysqli->prepare("
    SELECT n.id, n.hardware_id, n.label, n.comment, n.role, n.icon, n.check_type, n.check_port, n.x, n.y,
           h.switch_name, h.hardware_ip, h.model_name, h.active,
           h.firmware_version, h.uptime, h.temperature,
           m.model_name AS model_ref,
           m.model_type_id AS mtype,
           p.is_up AS ping_up, p.checked_at AS ping_checked_at
    FROM map_nodes n
    LEFT JOIN hardware h ON h.id = n.hardware_id
    LEFT JOIN model m    ON m.id = h.id_model
    LEFT JOIN hw_ping_state p ON p.hardware_id = n.hardware_id
    WHERE n.map_id = ?
");
$stmt->bind_param("i", $MAP_ID);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    // Вторая строка подписи — модель (мелким, как на drawio-схеме)
    $model = $r['model_name'] ?: ($r['model_ref'] ?? '');
    $nodes[] = [
        'id'          => (int)$r['id'],
        'hardware_id' => $r['hardware_id'] !== null ? (int)$r['hardware_id'] : null,
        'label'       => $r['label'],
        'comment'     => $r['comment'] ?? '',
        'sub'         => $model ?: '',
        'mtype'       => $r['mtype'] !== null ? (int)$r['mtype'] : null,
        'ip'          => $r['hardware_ip'] ?? '',
        'firmware'    => $r['firmware_version'] ?? '',
        'uptime'      => $r['uptime'] ?? '',
        'temperature' => $r['temperature'] !== null ? (float)$r['temperature'] : null,
        'check_type'  => $r['check_type'] ?? 'ping',
        'check_port'  => $r['check_port'] !== null ? (int)$r['check_port'] : null,
        'role'        => $r['role'],
        'icon'        => $r['icon'] ?? '',
        'x'           => $r['x'] !== null ? (float)$r['x'] : null,
        'y'           => $r['y'] !== null ? (float)$r['y'] : null,
        'active'      => $r['active'] !== null ? (int)$r['active'] : null,
        // живое состояние: true/false; null — мониторинг выключен, данных нет,
        // они протухли (старше MAP_STATE_STALE_SEC) или собраны ДО последнего
        // включения мониторинга (ждём свежий цикл ping_monitor)
        'state'       => (!$monEnabled || $r['ping_up'] === null
                          || $r['ping_checked_at'] === null
                          || strtotime($r['ping_checked_at']) < time() - MAP_STATE_STALE_SEC
                          || strtotime($r['ping_checked_at']) < $monEnabledAt)
                         ? null : ((int)$r['ping_up'] === 1),
    ];
}

// Линки
$links = [];
$stmt = $mysqli->prepare("
    SELECT id, src_node, dst_node, src_port, dst_port, speed, label, color, line
    FROM map_links
    WHERE map_id = ?
");
$stmt->bind_param("i", $MAP_ID);
$stmt->execute();
$res = $stmt->get_result();
$rawLinks = [];
while ($r = $res->fetch_assoc()) {
    $rawLinks[] = $r;
}

// Точки излома всех линков одним запросом
$points = [];
$res = $mysqli->query("SELECT id, link_id, seq, x, y FROM map_link_points ORDER BY link_id, seq");
while ($r = $res->fetch_assoc()) {
    $points[$r['link_id']][] = [
        'id' => (int)$r['id'],
        'x'  => $r['x'] !== null ? (float)$r['x'] : 0.0,
        'y'  => $r['y'] !== null ? (float)$r['y'] : 0.0,
    ];
}

// --- Живой статус линка по sw_port_info ---
// Из строки порта ('sfp28-15', 'P48', 'ether5') достаём номер
function map_port_num($s) {
    if (preg_match('/(\d+)\s*$/', trim((string)$s), $m)) return (int)$m[1];
    return null;
}

$nodeHw = [];
$nodeState = [];
foreach ($nodes as $n) {
    $nodeHw[$n['id']] = $n['hardware_id'];
    $nodeState[$n['id']] = $n['state'];
}

// --- VLAN из конфигов свичей (sw_cfg_port, наполняет cfg_vlan_parse.py) ---
$cfgByName = [];   // hw -> ['sfp28-1' => row]
$cfgByNum  = [];   // hw -> [num => row]
$chkCfg = $mysqli->query("SHOW TABLES LIKE 'sw_cfg_port'");
if ($chkCfg && $chkCfg->num_rows) {
    $hwIdsCfg = array_values(array_unique(array_filter(array_column($nodes, 'hardware_id'))));
    if ($hwIdsCfg) {
        $in = implode(',', array_fill(0, count($hwIdsCfg), '?'));
        $stmt = $mysqli->prepare("SELECT hardware_id, port, port_num, mode, pvid, tagged, untagged, descr FROM sw_cfg_port WHERE hardware_id IN ($in)");
        $stmt->bind_param(str_repeat('i', count($hwIdsCfg)), ...$hwIdsCfg);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $hw = (int)$r['hardware_id'];
            $row = [
                'port'     => $r['port'],
                'mode'     => $r['mode'],
                'pvid'     => $r['pvid'] !== null ? (int)$r['pvid'] : null,
                'tagged'   => $r['tagged'] === 'ALL' ? 'ALL'
                            : array_values(array_filter(array_map('intval', explode(',', (string)$r['tagged'])))),
                'untagged' => array_values(array_filter(array_map('intval', explode(',', (string)$r['untagged'])))),
                'descr'    => $r['descr'] ?? '',
            ];
            $cfgByName[$hw][mb_strtolower($r['port'])] = $row;
            $num = $r['port_num'] !== null ? (int)$r['port_num'] : map_port_num($r['port']);
            if ($num !== null && !isset($cfgByNum[$hw][$num])) $cfgByNum[$hw][$num] = $row;
        }
    }
}

// Порт линка -> строка sw_cfg_port (сначала точное имя, потом числовой номер)
$cfgPort = function($hwId, $portStr) use (&$cfgByName, &$cfgByNum) {
    if (!$hwId) return null;
    $key = mb_strtolower(trim((string)$portStr));
    if ($key !== '' && isset($cfgByName[$hwId][$key])) return $cfgByName[$hwId][$key];
    $num = map_port_num($portStr);
    if ($num !== null && isset($cfgByNum[$hwId][$num])) return $cfgByNum[$hwId][$num];
    return null;
};

// --- LLDP/CDP-соседи (sw_port_neighbor, наполняет harvest.py) ---
$nbByName = [];
$nbByNum  = [];
$chkNb = $mysqli->query("SHOW TABLES LIKE 'sw_port_neighbor'");
if ($chkNb && $chkNb->num_rows) {
    $hwIdsNb = array_values(array_unique(array_filter(array_column($nodes, 'hardware_id'))));
    if ($hwIdsNb) {
        $in = implode(',', array_fill(0, count($hwIdsNb), '?'));
        $stmt = $mysqli->prepare("SELECT hardware_id, port, port_num, neighbor_name, neighbor_port, neighbor_mac FROM sw_port_neighbor WHERE hardware_id IN ($in)");
        $stmt->bind_param(str_repeat('i', count($hwIdsNb)), ...$hwIdsNb);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $hw = (int)$r['hardware_id'];
            $row = [
                'port'     => $r['port'],
                'name'     => $r['neighbor_name'] ?? '',
                'nport'    => $r['neighbor_port'] ?? '',
                'mac'      => $r['neighbor_mac'] ?? '',
            ];
            $nbByName[$hw][mb_strtolower($r['port'])] = $row;
            if ($r['port_num'] !== null) $nbByNum[$hw][(int)$r['port_num']] = $row;
        }
    }
}

$lldpPort = function($hwId, $portStr) use (&$nbByName, &$nbByNum) {
    if (!$hwId) return null;
    $key = mb_strtolower(trim((string)$portStr));
    if ($key !== '' && isset($nbByName[$hwId][$key])) return $nbByName[$hwId][$key];
    $num = map_port_num($portStr);
    if ($num !== null && isset($nbByNum[$hwId][$num])) return $nbByNum[$hwId][$num];
    return null;
};

// --- Статусы портов (sw_port_info, наполняет harvest.py) ---
// ВАЖНО: сначала точное имя порта, потом числовой номер (как cfgPort/lldpPort).
// Только по номеру искать НЕЛЬЗЯ: у MikroTik harvest port_num — сквозной счётчик
// (ether1, sfp28-1, qsfp28-1-1...), а не число из имени интерфейса — «sfp28-14»
// это НЕ port 14, по номеру находился чужой (часто пустой) порт → ложный «линк down».
$piByName = [];
$piByNum  = [];
$hwIdsPi  = array_values(array_unique(array_filter(array_column($nodes, 'hardware_id'))));
if ($hwIdsPi) {
    $in = implode(',', array_fill(0, count($hwIdsPi), '?'));
    $stmt = $mysqli->prepare("SELECT id_switch, port, port_name, port_status FROM sw_port_info WHERE id_switch IN ($in)");
    $stmt->bind_param(str_repeat('i', count($hwIdsPi)), ...$hwIdsPi);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $hw = (int)$r['id_switch'];
        if (($r['port_name'] ?? '') !== '') $piByName[$hw][mb_strtolower($r['port_name'])] = $r['port_status'];
        $piByNum[$hw][(int)$r['port']] = $r['port_status'];
    }
}
$portState = function($hwId, $portStr) use (&$piByName, &$piByNum) {
    if (!$hwId) return null;
    $key = mb_strtolower(trim((string)$portStr));
    if ($key !== '' && isset($piByName[$hwId][$key])) return $piByName[$hwId][$key];
    $num = map_port_num($portStr);
    if ($num !== null && isset($piByNum[$hwId][$num])) return $piByNum[$hwId][$num];
    return null;
};

foreach ($rawLinks as $r) {
    $srcHw = $nodeHw[(int)$r['src_node']] ?? null;
    $dstHw = $nodeHw[(int)$r['dst_node']] ?? null;

    // VLAN на концах линка (sw_cfg_port из harvest); null — данных нет
    $srcVlan = $cfgPort($srcHw, $r['src_port']);
    $dstVlan = $cfgPort($dstHw, $r['dst_port']);
    // LLDP/CDP-сосед на концах линка
    $srcLldp = $lldpPort($srcHw, $r['src_port']);
    $dstLldp = $lldpPort($dstHw, $r['dst_port']);

    // мониторинг выключен глобально — статус линка не показываем
    if (!$monEnabled) {
        $links[] = [
            'id'       => (int)$r['id'],
            'src'      => (int)$r['src_node'],
            'dst'      => (int)$r['dst_node'],
            'src_port' => $r['src_port'] ?? '',
            'dst_port' => $r['dst_port'] ?? '',
            'speed'    => $r['speed'] ?? '',
            'label'    => $r['label'] ?? '',
            'color'    => $r['color'] ?? '',
            'line'     => $r['line'] ?? 'smooth',
            'status'   => 'unknown',
            'points'   => $points[$r['id']] ?? [],
            'src_vlan' => $srcVlan,
            'dst_vlan' => $dstVlan,
            'src_lldp' => $srcLldp,
            'dst_lldp' => $dstLldp,
        ];
        continue;
    }

    // статус: up — все найденные порты up; down — хотя бы один down; unknown — данных нет
    $found = 0; $down = 0;
    foreach ([[$srcHw, $r['src_port']], [$dstHw, $r['dst_port']]] as $ep) {
        $st = $portState($ep[0], $ep[1]);
        if ($st !== null) { $found++; if ($st === 'down') $down++; }
    }
    $status = 'unknown';
    if ($found > 0) $status = $down > 0 ? 'down' : 'up';

    // если само устройство на конце недоступно по ping — линк down в любом случае
    $srcState = $nodeState[(int)$r['src_node']] ?? null;
    $dstState = $nodeState[(int)$r['dst_node']] ?? null;
    if ($srcState === false || $dstState === false) $status = 'down';

    $links[] = [
        'id'       => (int)$r['id'],
        'src'      => (int)$r['src_node'],
        'dst'      => (int)$r['dst_node'],
        'src_port' => $r['src_port'] ?? '',
        'dst_port' => $r['dst_port'] ?? '',
        'speed'    => $r['speed'] ?? '',
        'label'    => $r['label'] ?? '',
        'color'    => $r['color'] ?? '',
        'line'     => $r['line'] ?? 'smooth',
        'status'   => $status,
        'points'   => $points[$r['id']] ?? [],
        'src_vlan' => $srcVlan,
        'dst_vlan' => $dstVlan,
        'src_lldp' => $srcLldp,
        'dst_lldp' => $dstLldp,
    ];
}

// Список VLAN, встречающихся на линках карты (для селектора подсветки)
$vlanIds = [];
foreach ($links as $l) {
    foreach (['src_vlan', 'dst_vlan'] as $ek) {
        $ep = $l[$ek] ?? null;
        if (!$ep) continue;
        if ($ep['pvid']) $vlanIds[$ep['pvid']] = true;
        if ($ep['tagged'] !== 'ALL') foreach ($ep['tagged'] as $v) $vlanIds[$v] = true;
        foreach ($ep['untagged'] as $v) $vlanIds[$v] = true;
    }
}
$vlans = [];
if ($vlanIds) {
    $ids = array_map('intval', array_keys($vlanIds));
    sort($ids);
    $names = [];
    $res = $mysqli->query("SELECT vlan_id, MIN(name) AS name FROM ipam_vlans WHERE vlan_id IN (" . implode(',', $ids) . ") GROUP BY vlan_id");
    if ($res) while ($r = $res->fetch_assoc()) $names[(int)$r['vlan_id']] = $r['name'];
    foreach ($ids as $v) $vlans[] = ['id' => $v, 'name' => $names[$v] ?? ''];
}

// Порты устройств карты (для подсказок-списков в редакторе линка):
// hardware_id -> список имён портов из sw_port_info
$ports = [];
$hwIds = array_values(array_unique(array_filter(array_column($nodes, 'hardware_id'))));
if ($hwIds) {
    $in = implode(',', array_fill(0, count($hwIds), '?'));
    $types = str_repeat('i', count($hwIds));
    $stmt = $mysqli->prepare("SELECT id_switch, port, port_name FROM sw_port_info WHERE id_switch IN ($in) ORDER BY id_switch, port");
    $stmt->bind_param($types, ...$hwIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $pname = trim((string)($r['port_name'] ?? ''));
        if ($pname === '') $pname = 'Port ' . (int)$r['port'];
        $ports[(int)$r['id_switch']][] = $pname;
    }
}

// Блоки-контейнеры (комнаты, кроссовые, шкафы)
$groups = [];
$stmt = $mysqli->prepare("SELECT id, label, x, y, w, h FROM map_groups WHERE map_id = ?");
$stmt->bind_param("i", $MAP_ID);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $groups[] = [
        'id'    => (int)$r['id'],
        'label' => $r['label'],
        'x'     => (float)$r['x'],
        'y'     => (float)$r['y'],
        'w'     => (float)$r['w'],
        'h'     => (float)$r['h'],
    ];
}

// Железо, которого ещё нет на карте — для списка добавления
$avail = [];
$stmt = $mysqli->prepare("
    SELECT h.id, h.switch_name, h.hardware_ip, h.model_name, m.model_name AS model_ref
    FROM hardware h
    LEFT JOIN model m ON m.id = h.id_model
    LEFT JOIN map_nodes n ON n.hardware_id = h.id AND n.map_id = ?
    WHERE n.id IS NULL
    ORDER BY h.switch_name
");
$stmt->bind_param("i", $MAP_ID);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $avail[] = [
        'id'    => (int)$r['id'],
        'name'  => $r['switch_name'] ?: ('hw-' . $r['id']),
        'ip'    => $r['hardware_ip'],
        'model' => $r['model_name'] ?: ($r['model_ref'] ?? ''),
    ];
}

echo json_encode([
    'map_id' => $MAP_ID,
    'maps'   => $maps,
    'monitoring' => $monEnabled,
    'refresh_sec' => $refreshSec,
    'settings' => $settings,
    'icons'  => maps_icon_files(),
    'backgrounds' => maps_background_files(),
    'nodes' => $nodes,
    'links' => $links,
    'vlans' => $vlans,
    'ports' => $ports,
    'groups' => $groups,
    'avail' => $avail,
], JSON_UNESCAPED_UNICODE);

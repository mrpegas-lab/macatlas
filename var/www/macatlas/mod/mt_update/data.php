<?php
include_once("../../loadinc.php");

if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'stats' => ['total' => 0, 'old' => 0, 'ok' => 0], 'error' => 'Нет прав на просмотр'], JSON_UNESCAPED_UNICODE));
}

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
            l.work_schedule,
            l.tz_offset_msk,
            ms.ip1             AS wan_ip,
            ms.lan             AS lan_raw,
            md.id             AS device_id,
            md.version        AS current_version,
            md.board_name,
            md.current_firmware,
            md.upgrade_firmware
        FROM mt_sites ms
        INNER JOIN mt_devices md ON md.locations_id = ms.locations_id
        LEFT JOIN locations l    ON l.id = ms.locations_id
        WHERE l.new_name IS NOT NULL AND l.new_name <> ''
        ORDER BY l.new_name, l.code";

function shiftTime($hhmm, $offset) {
    if (!$hhmm || strpos($hhmm, ':') === false) return $hhmm;
    list($h, $m) = array_map('intval', explode(':', $hhmm));
    $total = $h * 60 + $m - $offset * 60;
    $total = (($total % 1440) + 1440) % 1440;
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}

function formatScheduleMsk($jsonStr, $offset) {
    if (!$jsonStr || $offset == 0) return null;
    $data = json_decode($jsonStr, true);
    if (!is_array($data)) return null;
    $labels = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];
    $parts = [];
    foreach ($data as $key => $intervals) {
        if (strpos($key, '-') !== false) {
            $keys = explode('-', $key);
            $label = implode('-', array_map(function ($k) use ($labels) { return $labels[$k] ?? $k; }, $keys));
        } else {
            $label = $labels[$key] ?? $key;
        }
        if (is_array($intervals) && count($intervals)) {
            $ivs = array_map(function ($iv) use ($offset) { return shiftTime($iv[0], $offset) . '-' . shiftTime($iv[1], $offset); }, $intervals);
            $text = implode(', ', $ivs);
        } else { $text = 'вых'; }
        $parts[] = $label . ': ' . $text;
    }
    return implode('; ', $parts);
}

function formatSchedule($jsonStr) {
    if (!$jsonStr) return '—';
    $data = json_decode($jsonStr, true);
    if (!is_array($data)) return $jsonStr;
    $labels = ['mon' => 'Пн', 'tue' => 'Вт', 'wed' => 'Ср', 'thu' => 'Чт', 'fri' => 'Пт', 'sat' => 'Сб', 'sun' => 'Вс'];
    $parts = [];
    foreach ($data as $key => $intervals) {
        if (strpos($key, '-') !== false) {
            $keys = explode('-', $key);
            $label = implode('-', array_map(function ($k) use ($labels) { return $labels[$k] ?? $k; }, $keys));
        } else {
            $label = $labels[$key] ?? $key;
        }
        if (is_array($intervals) && count($intervals)) {
            $ivs = array_map(function ($iv) { return $iv[0] . '-' . $iv[1]; }, $intervals);
            $text = implode(', ', $ivs);
        } else { $text = 'вых'; }
        $parts[] = $label . ': ' . $text;
    }
    return implode('; ', $parts);
}

$res = $mysqli->query($sql);
$devices = [];
$statOld = 0;

while ($r = $res->fetch_assoc()) {
    // LAN: убираем маску /24 (и любую другую) в конце
    $lan = trim((string)($r['lan_raw'] ?? ''));
    if ($lan !== '') {
        // отрезаем "/24", "/25" и т.п.
        $lan = preg_replace('#/\d+$#', '', $lan);
        // на всякий случай убираем пробелы
        $lan = trim($lan);
    }
    $r['lan_ip'] = $lan;
    $r['wan_ip'] = $r['wan_ip'] ?: null;

    // Основной "рабочий" IP для проверок/команд — теперь LAN
    // (если LAN пуст — fallback на WAN, чтобы не ломать существующую логику)
    $r['ip'] = $r['lan_ip'] ?: $r['wan_ip'];


    $r['is_outdated'] = false;
    if ($targetVersion && $r['current_version']) {
        $clean = preg_replace('/\s*\(.*$/', '', $r['current_version']);
        $r['is_outdated'] = version_compare($clean, $targetVersion, '<');
    }
    $fwCurrent = $r['current_firmware'] ?? '';
    $fwOutdated = false;
    if ($targetVersion && $fwCurrent) {
        $fwClean = preg_replace('/\s*\(.*$/', '', $fwCurrent);
        $fwOutdated = version_compare($fwClean, $targetVersion, '<');
    }

    $r['work_schedule_text'] = formatSchedule($r['work_schedule'] ?? '');
    $r['work_schedule_msk']  = formatScheduleMsk($r['work_schedule'] ?? '', (int)($r['tz_offset_msk'] ?? 0));

    if ($r['is_outdated']) $statOld++;

    // --- ДОБАВЛЯЕМ HTML БЕЙДЖИ ---
    $r['ros_badge'] = $r['current_version']
        ? '<span class="badge badge-version ' . ($r['is_outdated'] ? 'badge-old' : 'badge-ok') . '">' . htmlspecialchars($r['current_version']) . '</span>'
        : '<span class="text-muted">—</span>';
        
    $r['fw_badge'] = $fwCurrent
        ? '<span class="badge badge-version ' . ($fwOutdated ? 'badge-old' : 'badge-ok') . '">' . htmlspecialchars($fwCurrent) . '</span>'
        : '<span class="text-muted">—</span>';

    $devices[] = $r;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'data' => $devices,
    'stats' => [
        'total' => count($devices),
        'old' => $statOld,
        'ok' => count($devices) - $statOld
    ],
    'target_version' => $targetVersion
], JSON_UNESCAPED_UNICODE);
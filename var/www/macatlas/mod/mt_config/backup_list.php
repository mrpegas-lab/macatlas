<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}

header('Content-Type: application/json');

$locId = (int)($_GET['locations_id'] ?? 0);
if (!$locId) { echo json_encode(['error' => 'no locations_id']); exit; }

$loc = $mysqli->query("SELECT new_name FROM locations WHERE id=$locId")->fetch_assoc();
if (!$loc) { echo json_encode(['error' => 'not found']); exit; }
$locName = $loc['new_name'];

$r = $mysqli->query("SELECT id, file_type, file_name, file_size, file_mtime, file_path,
                            prev_id, changed_vs_prev
                     FROM mt_backups WHERE locations_id=$locId
                     ORDER BY file_mtime DESC");

$rsc = []; $backup = [];

// --- НОВОЕ: Переменные для контроля свежести ---
$latest_rsc_time = null;    // последний текстовый (.rsc или .cfg)
$latest_backup_time = null;
$now = time();

$BACKUP_ROOT = rtrim($config['backup_root'] ?? '/mnt/backups', '/');

// Имя устройства из пути файла бэкапа (старая структура с mikrotik/ и новая без неё).
// '' = бэкап самого объекта (салон/роутер). Логика — как в mod/backups/data.php.
function bkp_device($path, $root, $locName) {
    $rel  = substr($path, strlen($root));
    $dirs = explode('/', trim(dirname($rel), '/'));
    if (end($dirs) === 'mikrotik') array_pop($dirs);
    if (count($dirs) >= 2) {
        return $dirs[0] === $locName ? $dirs[1] : '';
    }
    return '';
}

while ($x = $r->fetch_assoc()) {
    $device = bkp_device($x['file_path'], $BACKUP_ROOT, $locName);
    if ($device === '') $device = null;

    $item = [
        'id'        => (int)$x['id'],
        'name'      => $x['file_name'],
        'type'      => $x['file_type'],
        'size'      => (int)$x['file_size'],
        'mtime_fmt' => $x['file_mtime'],
        'device'    => $device,
        'prev_id'   => $x['prev_id'] ? (int)$x['prev_id'] : null,
        'changed_vs_prev' => $x['changed_vs_prev'] === null ? null : (bool)$x['changed_vs_prev'],
    ];

    $mtime_ts = strtotime($x['file_mtime']);

    if ($x['file_type'] === 'rsc' || $x['file_type'] === 'cfg') {
        $rsc[] = $item;   // текстовые конфиги (rsc — MikroTik, cfg — свичи)
        if ($latest_rsc_time === null) $latest_rsc_time = $mtime_ts;
    } else {
        $backup[] = $item;
        if ($latest_backup_time === null) $latest_backup_time = $mtime_ts;
    }
}

// --- НОВОЕ: Расчет статуса (ок, warning, error) ---
$health = [
    'status' => 'ok',
    'message' => '',
    'rsc_age_hours' => null,
    'backup_age_hours' => null
];

if (!$latest_rsc_time) {
    $health['status'] = 'error';
    $health['message'] = 'Нет ни одного текстового бэкапа (.rsc/.cfg)!';
} else {
    $age_sec = $now - $latest_rsc_time;
    $health['rsc_age_hours'] = round($age_sec / 3600);
    
    if ($age_sec > 86400 * 2) { // Больше 48 часов
        $health['status'] = 'error';
        $health['message'] = 'Последний бэкап был более 48 часов назад!';
    } elseif ($age_sec > 86400) { // Больше 24 часов
        $health['status'] = 'warning';
        $health['message'] = 'Последний бэкап был более 24 часов назад.';
    }
}

if ($latest_backup_time) {
    $age_sec = $now - $latest_backup_time;
    $health['backup_age_hours'] = round($age_sec / 3600);
}

// Если текстовый есть, а бинарного нет, или он старый — тоже предупредим
if ($health['status'] == 'ok' && $latest_backup_time && (($now - $latest_backup_time) > 86400)) {
    $health['status'] = 'warning';
    $health['message'] = 'Бинарный бэкап (.backup) старше 24 часов.';
}

echo json_encode([
    'error'  => null,
    'name'   => $loc['new_name'],
    'rsc'    => $rsc,
    'backup' => $backup,
    'health' => $health // <-- Передаем статус на фронтенд
]);
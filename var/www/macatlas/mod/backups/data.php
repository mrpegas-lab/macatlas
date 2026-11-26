<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

// ---------- 1. Локации ----------
$locs = [];
$r = $mysqli->query("SELECT l.id, l.new_name, l.type_id, lt.name AS type_name
                     FROM locations l
                     LEFT JOIN locations_type lt ON lt.id = l.type_id
                     ORDER BY lt.sort IS NULL, lt.sort, l.new_name");
while ($x = $r->fetch_assoc()) {
    $locs[(int)$x['id']] = [
        'id'        => (int)$x['id'],
        'new_name'  => $x['new_name'],
        'type_name' => $x['type_name'],
        'devices'   => [],   // device => агрегаты
    ];
}

// ---------- 2. Все бэкапы одним запросом, раскладываем по локациям и устройствам ----------
$BACKUP_ROOT = rtrim($config['backup_root'] ?? '/mnt/backups', '/');

// Имя устройства из пути файла бэкапа. Поддерживаются обе структуры:
//   старая (ansible): <root>/<loc>/mikrotik/f, <root>/<loc>/<dev>/mikrotik/f,
//                     <root>/<тип>/<loc>/mikrotik/f
//   новая (backup.py): <root>/<loc>/<dev>/f  и  <root>/<тип(напр. salon)>/<loc>/f
// '' = бэкап самого объекта (салон/роутер), без отдельного устройства.
function bkp_device($path, $root, $locName) {
    $rel  = substr($path, strlen($root));
    $dirs = explode('/', trim(dirname($rel), '/'));
    if (end($dirs) === 'mikrotik') array_pop($dirs);     // старая структура
    if (count($dirs) >= 2) {
        // <loc>/<dev> — вложенное устройство; <group>/<loc> — сам объект
        return $dirs[0] === $locName ? $dirs[1] : '';
    }
    return '';
}

$b = $mysqli->query("SELECT locations_id, file_path, file_type, file_mtime, changed_vs_prev
                     FROM mt_backups");

while ($x = $b->fetch_assoc()) {
    $lid = (int)$x['locations_id'];
    if (!isset($locs[$lid])) continue;

    $dev = bkp_device($x['file_path'], $BACKUP_ROOT, $locs[$lid]['new_name']);

    if (!isset($locs[$lid]['devices'][$dev])) {
        $locs[$lid]['devices'][$dev] = [
            'files_cnt'    => 0,
            'last_rsc'     => null,
            'last_changed' => null,
        ];
    }
    $d = &$locs[$lid]['devices'][$dev];
    $d['files_cnt']++;

    // по текстовым бэкапам (.rsc MikroTik, .cfg свичи) запоминаем
    // самый свежий и его признак изменения
    if (in_array($x['file_type'], ['rsc', 'cfg'], true)) {
        if ($d['last_rsc'] === null || $x['file_mtime'] > $d['last_rsc']) {
            $d['last_rsc']     = $x['file_mtime'];
            $d['last_changed'] = $x['changed_vs_prev'] === null ? null : (bool)$x['changed_vs_prev'];
        }
    }
    unset($d);
}

// ---------- 3. Статусы и сборка результата ----------
$now = time();

// статус свежести: none / error / warning / ok
$mkStatus = function ($filesCnt, $ageHours) {
    if ($filesCnt === 0 || $ageHours === null) return 'none';
    if ($ageHours > 48) return 'error';
    if ($ageHours > 24) return 'warning';
    return 'ok';
};

$mkRow = function ($filesCnt, $lastRsc, $lastChanged) use ($now, $mkStatus) {
    $age = $lastRsc ? (int)round(($now - strtotime($lastRsc)) / 3600) : null;
    return [
        'files_cnt'    => $filesCnt,
        'last_rsc'     => $lastRsc,
        'age_hours'    => $age,
        'status'       => $mkStatus($filesCnt, $age),
        'last_changed' => $lastChanged,
    ];
};

$rows = [];
$dash = ['total' => 0, 'ok' => 0, 'stale' => 0, 'none' => 0, 'changed' => 0,
         'dev_total' => 0, 'dev_fresh' => 0, 'dev_none' => 0];

foreach ($locs as $loc) {
    // Агрегаты самой локации = сумма по всем её устройствам
    $totFiles = 0; $lastRsc = null; $lastChanged = null;
    foreach ($loc['devices'] as $d) {
        $totFiles += $d['files_cnt'];
        if ($d['last_rsc'] !== null && ($lastRsc === null || $d['last_rsc'] > $lastRsc)) {
            $lastRsc     = $d['last_rsc'];
            $lastChanged = $d['last_changed'];
        }
    }

    $row = array_merge([
        'id'        => $loc['id'],
        'new_name'  => $loc['new_name'],
        'type_name' => $loc['type_name'],
    ], $mkRow($totFiles, $lastRsc, $lastChanged));

    $row['fkey'] = $row['status'] . ($row['last_changed'] ? ' changed' : '');

    // Устройства (только если их несколько — тогда локация раскрывается)
    $devs = [];
    $named = array_filter(array_keys($loc['devices']), fn($k) => $k !== '');
    if (count($loc['devices']) > 1 || (count($loc['devices']) === 1 && $named)) {
        foreach ($loc['devices'] as $devName => $d) {
            if ($devName === '' && count($loc['devices']) > 1) continue;
            $devs[] = array_merge(['device' => $devName], $mkRow($d['files_cnt'], $d['last_rsc'], $d['last_changed']));
        }
        usort($devs, fn($a, $b) => strcmp($a['device'], $b['device']));
    }
    $row['devices'] = $devs;

    $dash['total']++;
    if ($row['status'] === 'ok') $dash['ok']++;
    if (in_array($row['status'], ['warning', 'error'], true)) $dash['stale']++;
    if ($row['status'] === 'none') $dash['none']++;
    if ($row['last_changed']) $dash['changed']++;

    $rows[] = $row;
}

// Порядок уже задан в SQL (ORDER BY type_id, new_name) — дополнительной сортировки не нужно

// ---------- 4. Сводка по УСТРОЙСТВАМ (масштаб: в локации может быть много железа) ----------
// Множество = то же, что бэкапит backup.py (как в «Здоровье сети»):
//   hardware (active, роутеры/свичи, есть креды) + mt_sites (lan + креды).
// Свежесть — по последнему .rsc/.cfg (имя файла = имя устройства + _YYYYMMDDHHMMSS).
$sqlDevs = "
    SELECT name FROM (
        SELECT h.switch_name AS name
        FROM hardware h
        JOIN model m ON m.id = h.id_model
        JOIN credentials c ON c.id = h.cred_id
        WHERE h.active = 1 AND m.model_type_id IN (1, 2)
          AND h.switch_name IS NOT NULL AND h.switch_name <> ''
          AND c.login IS NOT NULL AND c.login <> ''
          AND c.pas   IS NOT NULL AND c.pas   <> ''
        UNION
        SELECT l.new_name AS name
        FROM mt_sites ms
        JOIN locations l ON l.id = ms.locations_id
        JOIN credentials c ON c.id = ms.cred_id
        WHERE ms.lan IS NOT NULL AND ms.lan <> ''
          AND l.new_name IS NOT NULL AND l.new_name <> ''
          AND c.login IS NOT NULL AND c.login <> ''
          AND c.pas   IS NOT NULL AND c.pas   <> ''
    ) t";
$devNames = [];
$res = $mysqli->query($sqlDevs);
if ($res) {
    while ($r = $res->fetch_assoc()) $devNames[] = $r['name'];
}

// последний текстовый бэкап каждого устройства
$lastBk = [];
$res = $mysqli->query(
    "SELECT file_name, file_mtime FROM mt_backups WHERE file_type IN ('rsc','cfg')");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        // срезаем ТОЛЬКО метку времени в конце (как device_key в backup_indexer.py)
        $dev = preg_replace('/_\d{14}\.(rsc|cfg)$/i', '', $r['file_name']);
        if (!isset($lastBk[$dev]) || $r['file_mtime'] > $lastBk[$dev]) {
            $lastBk[$dev] = $r['file_mtime'];
        }
    }
}

$dash['dev_total'] = count($devNames);
foreach ($devNames as $name) {
    $lb = $lastBk[$name] ?? null;
    if (!$lb) {
        $dash['dev_none']++;
    } elseif (strtotime($lb) >= $now - 24 * 3600) {
        $dash['dev_fresh']++;
    }
}

echo json_encode(['data' => $rows, 'dash' => $dash], JSON_UNESCAPED_UNICODE);

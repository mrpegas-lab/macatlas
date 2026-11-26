<?php
// Здоровье сети — независимая оценка состояния сети (0–100) + журнал инцидентов.
// Оценка = взвешенная сумма 4 составляющих (веса в settings, health_w_*).
// «Красная зона»: открытый инцидент на маршрутизаторе или свежих бэкапов < 50%
//   — потолок общей оценки 49, чтобы не было «всё хорошо» при серьёзной проблеме.
// Все изменения (причина/комментарий инцидента) — AJAX в dataAED.php.
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// ---------- Настройки ----------
$st = [];
$res = $mysqli->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'health_%'");
if ($res) while ($r = $res->fetch_assoc()) $st[$r['key']] = $r['value'];
$wAvail = (float)($st['health_w_avail'] ?? 40);
$wBackup = (float)($st['health_w_backup'] ?? 25);
$wFw = (float)($st['health_w_fw'] ?? 20);
$wInc = (float)($st['health_w_inc'] ?? 15);
$backupHrs = (int)($st['health_backup_hrs'] ?? 48);
$incMinMin = (int)($st['health_incident_min_min'] ?? 2);
// Штраф за инциденты — пропорционально доле парка (2026-11-25):
// score = 100 - (Σ весов затронутых устройств / Σ весов парка) × 100 × K.
$incK = (float)($st['health_inc_k'] ?? 2);
$incW = [
    1 => (float)($st['health_inc_w_router'] ?? 1),   // роутер
    2 => (float)($st['health_inc_w_switch'] ?? 1),   // коммутатор
    3 => (float)($st['health_inc_w_ap'] ?? 0.3),     // точка доступа
    0 => (float)($st['health_inc_w_other'] ?? 0.3),  // прочее / без модели
];

$CAUSES = [
    ''         => '— не указана —',
    'power'    => '🔌 Питание / скачок напряжения',
    'access'   => '🔑 Нет доступа в помещение',
    'provider' => '🌐 Провайдер / канал связи',
    'hardware' => '🔧 Отказ железа',
    'config'   => '⚙️ Конфигурация / человеческий фактор',
    'other'    => '❓ Другое',
];
// причины, которые НЕ считаются виной сети/сетевика (организационные)
$ORG_CAUSES = ['power', 'access', 'provider'];

// ---------- 1. Доступность (аптайм за 24ч из hw_ping_log; иначе — текущее состояние) ----------
$hasLog = false;
$chk = $mysqli->query("SHOW TABLES LIKE 'hw_ping_log'");
if ($chk && $chk->num_rows) $hasLog = true;

$avail = null; $availNote = '';
if ($hasLog) {
    $res = $mysqli->query(
        "SELECT AVG(is_up) * 100 AS a FROM hw_ping_log WHERE checked_at >= NOW() - INTERVAL 24 HOUR");
    $row = $res ? $res->fetch_assoc() : null;
    if ($row && $row['a'] !== null) {
        $avail = round((float)$row['a'], 1);
        $availNote = 'средний аптайм всех устройств за 24 часа';
    }
}
if ($avail === null) {
    $res = $mysqli->query("SELECT AVG(is_up) * 100 a FROM hw_ping_state");
    $row = $res ? $res->fetch_assoc() : null;
    $avail = $row && $row['a'] !== null ? round((float)$row['a'], 1) : 100.0;
    $availNote = 'истории пока нет — по текущему состоянию';
}

// ---------- 2. Бэкапы: % устройств со свежим текстовым конфигом ----------
// Множество = то же, что бэкапит backup.py:
//   hardware (active, роутеры/свичи, есть креды) + mt_sites (lan + креды).
// Точки доступа (model_type_id=3) не считаем — их не бэкапим.
// Свежесть — по последнему .rsc или .cfg (имя файла = имя устройства).
$backupScore = null; $backupNote = ''; $backupStale = 0; $backupTotal = 0;
$sqlBackupDevs = "
    SELECT name, locations_id, kind FROM (
        SELECT h.switch_name AS name, h.locations_id, 'hw' AS kind
        FROM hardware h
        JOIN model m ON m.id = h.id_model
        JOIN credentials c ON c.id = h.cred_id
        WHERE h.active = 1 AND m.model_type_id IN (1, 2)
          AND h.switch_name IS NOT NULL AND h.switch_name <> ''
          AND c.login IS NOT NULL AND c.login <> ''
          AND c.pas   IS NOT NULL AND c.pas   <> ''
        UNION
        SELECT l.new_name AS name, ms.locations_id, 'site' AS kind
        FROM mt_sites ms
        JOIN locations l ON l.id = ms.locations_id
        JOIN credentials c ON c.id = ms.cred_id
        WHERE ms.lan IS NOT NULL AND ms.lan <> ''
          AND l.new_name IS NOT NULL AND l.new_name <> ''
          AND c.login IS NOT NULL AND c.login <> ''
          AND c.pas   IS NOT NULL AND c.pas   <> ''
    ) t";
$res = $mysqli->query($sqlBackupDevs);
$devBackup = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $devBackup[] = [
            'name' => $r['name'],
            'loc'  => (int)$r['locations_id'],
            'kind' => $r['kind'],
        ];
    }
}
// последний текстовый бэкап (.rsc/.cfg) каждого устройства — одним запросом
$lastBk = [];
// Имя устройства из имени файла: срезаем ТОЛЬКО метку времени в конце
// (_YYYYMMDDHHMMSS), как device_key() в backup_indexer.py.
// SUBSTRING_INDEX(..., '_20', 1) использовать НЕЛЬЗЯ: ломается на именах
// вида SITE_201_EXAMPLE2 (обрезает до «MSK»).
$res = $mysqli->query(
    "SELECT file_name, file_mtime FROM mt_backups WHERE file_type IN ('rsc','cfg')");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $dev = preg_replace('/_\d{14}\.(rsc|cfg)$/i', '', $r['file_name']);
        if (!isset($lastBk[$dev]) || $r['file_mtime'] > $lastBk[$dev]) {
            $lastBk[$dev] = $r['file_mtime'];
        }
    }
}
foreach ($devBackup as &$d) {
    $lb = $lastBk[$d['name']] ?? null;
    $d['last_backup'] = $lb;
    // fresh = свежий; stale = есть, но старый; none = ни одного
    $d['bk_state'] = !$lb ? 'none'
        : (strtotime($lb) >= time() - $backupHrs * 3600 ? 'fresh' : 'stale');
}
unset($d);
$backupTotal = count($devBackup);
$freshCnt = 0;
$freshHw = $totHw = $freshSite = $totSite = 0;
foreach ($devBackup as $d) {
    if ($d['bk_state'] === 'fresh') $freshCnt++; else $backupStale++;
    if ($d['kind'] === 'hw')   { $totHw++;   if ($d['bk_state'] === 'fresh') $freshHw++; }
    else                       { $totSite++; if ($d['bk_state'] === 'fresh') $freshSite++; }
}
if ($backupTotal > 0) {
    $backupScore = round($freshCnt / $backupTotal * 100, 1);
    $backupNote = "$freshCnt из $backupTotal со свежим бэкапом (≤{$backupHrs}ч): "
                . "объекты $freshSite/$totSite, оборудование $freshHw/$totHw";
}

// ---------- 3. Актуальность прошивок ----------
// MikroTik: роутеры объектов (mt_devices, эталон = самая частая upgrade_firmware)
//           + MikroTik из hardware (офис/ЦОД) — на тот же эталон.
// ubnt/tplink (hardware): эталон = самая частая версия внутри модели.
// Для каждого производителя — счёт ok/total и полный список отстающих.

// нормализация версии RouterOS: «7.23.8 (long-term)» → «7.23.8»
// (hardware хранит версию с суффиксом канала, mt_devices — без)
$fwBase = function ($v) {
    return preg_match('/^[\d.]+/', trim((string)$v), $m) ? $m[0] : trim((string)$v);
};

// эталон MikroTik — самая частая upgrade_firmware по объектам
$mtEtalon = null;
$res = $mysqli->query(
    "SELECT upgrade_firmware v, COUNT(*) c FROM mt_devices
     WHERE upgrade_firmware IS NOT NULL AND upgrade_firmware <> ''
     GROUP BY v ORDER BY c DESC, v DESC LIMIT 1");
if ($res && ($r = $res->fetch_assoc())) $mtEtalon = $fwBase($r['v']);

$mtOk = $mtTotal = 0;
$mtOut = [];      // отстающие: [имя, версия, эталон, источник]
$mtVerDist = [];  // распределение версий по парку MikroTik
if ($mtEtalon !== null) {
    $res = $mysqli->query(
        "SELECT hostname, current_firmware, upgrade_firmware FROM mt_devices
         WHERE upgrade_firmware IS NOT NULL AND upgrade_firmware <> ''");
    if ($res) while ($r = $res->fetch_assoc()) {
        $mtTotal++;
        $mtVerDist[$r['current_firmware']] = ($mtVerDist[$r['current_firmware']] ?? 0) + 1;
        if ($fwBase($r['current_firmware']) === $fwBase($r['upgrade_firmware'])) $mtOk++;
        else $mtOut[] = [$r['hostname'], $r['current_firmware'], $r['upgrade_firmware'], 'объект'];
    }
    // MikroTik из hardware (офис/ЦОД) — сравнение с тем же эталоном
    $res = $mysqli->query(
        "SELECT h.switch_name, h.firmware_version
         FROM hardware h JOIN model m ON m.id = h.id_model
         WHERE h.active = 1 AND m.id_brand = 3
           AND h.firmware_version IS NOT NULL AND h.firmware_version <> ''");
    if ($res) while ($r = $res->fetch_assoc()) {
        $mtTotal++;
        $mtVerDist[$r['firmware_version']] = ($mtVerDist[$r['firmware_version']] ?? 0) + 1;
        if ($fwBase($r['firmware_version']) === $mtEtalon) $mtOk++;
        else $mtOut[] = [$r['switch_name'], $r['firmware_version'], $mtEtalon, 'hardware'];
    }
}
$mtScore = $mtTotal > 0 ? round($mtOk / $mtTotal * 100, 1) : null;
arsort($mtVerDist);

// ubnt / tplink — по устройствам; эталон = САМАЯ НОВАЯ версия из имеющихся
// в парке у этой модели (значит, проверена на нашем железе).
// Сравнение — по числовой базе («1.11.1» == «1.11.1-lite» — одно поколение).
$swRows = [];
$res = $mysqli->query(
    "SELECT b.brand_name, m.model_name, h.switch_name, h.firmware_version
     FROM hardware h
     JOIN model m ON m.id = h.id_model
     JOIN brand b ON b.id = m.id_brand
     WHERE h.active = 1 AND m.id_brand IN (1, 2)
       AND h.firmware_version IS NOT NULL AND h.firmware_version <> ''");
if ($res) while ($r = $res->fetch_assoc()) $swRows[] = $r;

$fwVendors = [];   // brand_name => ['score','ok','total','out'=>[[имя, версия, эталон, модель]]]
$byBrandModel = [];
foreach ($swRows as $r) $byBrandModel[$r['brand_name']][$r['model_name']][] = $r;
foreach ($byBrandModel as $bname => $models) {
    $ok = $total = 0; $out = [];
    foreach ($models as $model => $rows) {
        // максимальная числовая база внутри модели
        $refBase = null;
        foreach ($rows as $r) {
            $b = $fwBase($r['firmware_version']);
            if ($refBase === null || version_compare($b, $refBase, '>')) $refBase = $b;
        }
        // как отображать эталон: самая частая реальная строка среди устройств на макс. базе
        $topCnt = [];
        foreach ($rows as $r) {
            if ($fwBase($r['firmware_version']) === $refBase) {
                $topCnt[$r['firmware_version']] = ($topCnt[$r['firmware_version']] ?? 0) + 1;
            }
        }
        arsort($topCnt);
        $refShow = (string)(array_key_first($topCnt) ?? $refBase);
        foreach ($rows as $r) {
            $total++;
            if ($fwBase($r['firmware_version']) === $refBase) $ok++;
            else $out[] = [$r['switch_name'], $r['firmware_version'], $refShow, $model];
        }
    }
    if ($total > 0) {
        $fwVendors[$bname] = [
            'score' => round($ok / $total * 100, 1),
            'ok' => $ok, 'total' => $total, 'out' => $out,
        ];
    }
}

// ---------- 3b. Бэкапы по локациям (информационный блок, в оценку не входит) ----------
// Ключ группы: тип локации, если таких локаций много (Салон → все салоны одной карточкой),
// иначе — сама локация (Офис, ЦОД…)
// Считаем по устройствам из п.2: сколько свежих / просрочено / без бэкапов.
$typeCnt = [];
$res = $mysqli->query(
    "SELECT COALESCE(lt.name, '—') t, COUNT(*) c
     FROM locations l LEFT JOIN locations_type lt ON lt.id = l.type_id
     GROUP BY t");
if ($res) while ($r = $res->fetch_assoc()) $typeCnt[$r['t']] = (int)$r['c'];

// типы локаций и имена — для группировки устройств
$locInfo = [];   // locations_id => [отображаемое имя (name), type_name]
$res = $mysqli->query(
    "SELECT l.id, l.name, l.new_name, COALESCE(lt.name, '—') AS tname
     FROM locations l LEFT JOIN locations_type lt ON lt.id = l.type_id");
if ($res) while ($r = $res->fetch_assoc()) {
    $disp = trim((string)$r['name']) !== '' ? $r['name'] : $r['new_name'];
    $locInfo[(int)$r['id']] = [$disp, $r['tname']];
}

$bkGroup = function ($locId) use ($locInfo, $typeCnt) {
    [$name, $tname] = $locInfo[$locId] ?? ['без локации', '—'];
    if ($tname !== '—' && ($typeCnt[$tname] ?? 0) > 5) {
        return $tname;   // «Салон» → «Салоны (все)»
    }
    return $name ?: 'без локации';
};

// причина последней ошибки бэкапа (пишет backup.py в backup_device_status;
// таблицы может ещё не быть — тогда просто без подсказок)
$bkErr = [];   // device => [last_ok, error, last_attempt_at]
$res = $mysqli->query("SHOW TABLES LIKE 'backup_device_status'");
if ($res && $res->num_rows) {
    $res = $mysqli->query("SELECT device, last_ok, error, last_attempt_at FROM backup_device_status");
    if ($res) while ($r = $res->fetch_assoc()) $bkErr[$r['device']] = $r;
}

$bkLocs = [];   // [группа => ['fresh','stale','none','total','problems'=>[[имя, состояние, дата, ошибка]]]]
foreach ($devBackup as $d) {
    $g = $bkGroup($d['loc']);
    if (!isset($bkLocs[$g])) {
        $bkLocs[$g] = ['fresh' => 0, 'stale' => 0, 'none' => 0, 'total' => 0, 'problems' => []];
    }
    $bkLocs[$g][$d['bk_state']]++;
    $bkLocs[$g]['total']++;
    if ($d['bk_state'] !== 'fresh') {
        $err = '';
        if (isset($bkErr[$d['name']]) && !$bkErr[$d['name']]['last_ok'] && $bkErr[$d['name']]['error']) {
            $err = 'Последняя попытка ' . date('d.m.Y H:i', strtotime($bkErr[$d['name']]['last_attempt_at']))
                 . ': ' . $bkErr[$d['name']]['error'];
        }
        $bkLocs[$g]['problems'][] = [$d['name'], $d['bk_state'], $d['last_backup'], $err];
    }
}
// проблемные: сначала совсем без бэкапов, потом просроченные (по дате — старейшие сверху)
foreach ($bkLocs as &$bl) {
    usort($bl['problems'], function ($a, $b) {
        if (($a[1] === 'none') !== ($b[1] === 'none')) return $a[1] === 'none' ? -1 : 1;
        return strcmp((string)$a[2], (string)$b[2]);
    });
}
unset($bl);
ksort($bkLocs);

// ---------- 4. Инциденты ----------
$hasInc = false;
$chk = $mysqli->query("SHOW TABLES LIKE 'incidents'");
if ($chk && $chk->num_rows) $hasInc = true;

// Вес парка — сумма весов всех активных устройств hardware по типам моделей
$fleetW = 0.0;
$res = $mysqli->query(
    "SELECT COALESCE(m.model_type_id, 0) mt, COUNT(*) c
     FROM hardware h
     LEFT JOIN model m ON m.id = h.id_model
     WHERE h.active = 1
     GROUP BY COALESCE(m.model_type_id, 0)");
if ($res) while ($r = $res->fetch_assoc()) {
    $fleetW += ($incW[(int)$r['mt']] ?? $incW[0]) * (int)$r['c'];
}

$incidents = []; $downNow = []; $netIncidents7d = 0; $orgMin7d = 0;
$affectedW = 0.0; $seenDev = [];
if ($hasInc) {
    $res = $mysqli->query(
        "SELECT i.*, h.switch_name, h.active, m.model_type_id
         FROM incidents i
         LEFT JOIN hardware h ON h.id = i.hardware_id
         LEFT JOIN model m ON m.id = h.id_model
         WHERE i.started_at >= NOW() - INTERVAL 30 DAY OR i.ended_at IS NULL
         ORDER BY i.ended_at IS NULL DESC, i.started_at DESC
         LIMIT 200");
    if ($res) $incidents = $res->fetch_all(MYSQLI_ASSOC);

    foreach ($incidents as $inc) {
        $end = $inc['ended_at'] ?: date('Y-m-d H:i:s');
        $durMin = (strtotime($end) - strtotime($inc['started_at'])) / 60;
        $recent = strtotime($inc['started_at']) >= time() - 7 * 86400;
        // в счёт оценки идут инциденты длиннее порога и не по организационным причинам;
        // каждое устройство учитывается один раз (весом по типу), неактивные — не штрафуют
        if ($recent && $durMin >= $incMinMin && !in_array($inc['cause'], $ORG_CAUSES, true)) {
            $netIncidents7d++;
            $devId = (int)$inc['hardware_id'];
            if (empty($seenDev[$devId]) && (int)($inc['active'] ?? 0) === 1) {
                $seenDev[$devId] = true;
                $mt = (int)($inc['model_type_id'] ?? 0);
                $affectedW += $incW[$mt] ?? $incW[0];
            }
        }
        if ($recent && in_array($inc['cause'], $ORG_CAUSES, true)) {
            $orgMin7d += $durMin;
        }
        if ($inc['ended_at'] === null) $downNow[] = $inc;
    }
}
// оценка: 100 минус доля затронутого парка × K (пропорционально размеру сети)
if ($fleetW > 0) {
    $incScore = max(0, round(100 - ($affectedW / $fleetW) * 100 * $incK, 1));
    $affectedCnt = count($seenDev);
    $incNote = "$affectedCnt устройств со значимыми инцидентами за 7 дней";
} else {
    $incScore = null;
    $incNote = 'нет активного парка для оценки';
}

// ---------- Красная зона: потолок оценки ----------
$redZone = [];
// открытый инцидент на маршрутизаторе (model_type_id = 1)
if ($downNow) {
    $ids = implode(',', array_map('intval', array_column($downNow, 'hardware_id')));
    $res = $mysqli->query(
        "SELECT h.switch_name FROM hardware h JOIN model m ON m.id = h.id_model
         WHERE h.id IN ($ids) AND m.model_type_id = 1");
    if ($res) while ($r = $res->fetch_assoc()) $redZone[] = "недоступен маршрутизатор «{$r['switch_name']}»";
}
if ($backupTotal > 0 && $freshCnt / $backupTotal < 0.5) {
    $redZone[] = 'свежие бэкапы есть менее чем у половины устройств';
}

// ---------- Предупреждение: сегодня бэкапы не собирались ----------
// Ночной прогон идёт ~01:00–03:00; если после 04:00 свежих .rsc за сегодня нет — тревога
$backupTodayWarn = null;
$res = $mysqli->query(
    "SELECT MAX(file_mtime) last_rsc FROM mt_backups WHERE file_type IN ('rsc','cfg')");
$lastRsc = $res ? ($res->fetch_assoc()['last_rsc'] ?? null) : null;
if ((int)date('H') >= 4 && (!$lastRsc || strtotime($lastRsc) < strtotime('today'))) {
    $backupTodayWarn = 'Сегодня бэкапы не собирались'
        . ($lastRsc ? ' (последний: ' . $lastRsc . ')' : ' (в архиве вообще пусто)')
        . ' — проверьте задачи планировщика';
}

// ---------- Итоговая оценка (взвешенная по доступным составляющим) ----------
// Прошивки — сводная карточка из трёх производителей
// (детализация и списки отстающих — панелью «Прошивки по производителям» ниже)
$fwAllOk = $mtOk; $fwAllTotal = $mtTotal;
foreach ($fwVendors as $v) {
    $fwAllOk += $v['ok'];
    $fwAllTotal += $v['total'];
}
$fwTotalScore = $fwAllTotal > 0 ? round($fwAllOk / $fwAllTotal * 100, 1) : null;
$fwTotalNote  = $fwAllTotal > 0 ? "$fwAllOk из $fwAllTotal по всему парку" : '';

// расшифровка по производителям — в подсказку карточки
$fwTitle = "Как считается:\nMikroTik — сравнение с эталоном «" . ($mtEtalon ?? '—') . "» (объекты + hardware)";
foreach ($fwVendors as $bname => $v) {
    $fwTitle .= "\n{$bname} — эталон = самая новая версия из имеющихся в парке (по модели): {$v['ok']} из {$v['total']}";
}

$parts = [
    ['label' => 'Доступность',         'score' => $avail,       'w' => $wAvail,  'note' => $availNote,  'icon' => 'bi-wifi'],
    ['label' => 'Бэкапы конфигураций', 'score' => $backupScore, 'w' => $wBackup, 'note' => $backupNote, 'icon' => 'bi-archive',
     'title' => "Свежесть последнего .rsc/.cfg ≤ {$backupHrs} ч.\nЗнаменатель — устройства, которые бэкапит backup.py:\nроутеры/свичи из hardware с кредами + объекты mt_sites (без точек доступа)."],
    ['label' => 'Прошивки',            'score' => $fwTotalScore,'w' => $wFw,     'note' => $fwTotalNote,'icon' => 'bi-cpu', 'title' => $fwTitle],
    ['label' => 'Инциденты',           'score' => $incScore,    'w' => $wInc,    'note' => $incNote,    'icon' => 'bi-exclamation-triangle'],
];
$sumW = 0; $sum = 0;
foreach ($parts as $p) {
    if ($p['score'] === null) continue;   // нет данных — составляющая исключается, веса перенормируются
    $sum += $p['score'] * $p['w'];
    $sumW += $p['w'];
}
$health = $sumW > 0 ? round($sum / $sumW, 1) : null;
$capped = false;
if ($health !== null && $redZone && $health > 49) {
    $health = 49;
    $capped = true;
}

function healthColor($v) {
    if ($v === null) return '#6c757d';
    if ($v >= 80) return '#28a745';
    if ($v >= 50) return '#ffc107';
    return '#dc3545';
}
function fmtDur($start, $end) {
    $sec = strtotime($end) - strtotime($start);
    if ($sec < 3600) return round($sec / 60) . ' мин';
    if ($sec < 86400) return round($sec / 3600, 1) . ' ч';
    return round($sec / 86400, 1) . ' дн';
}
$gaugeColor = healthColor($health);
$pct = $health ?? 0;
// SVG-дуга 180°: длина полуокружности r=80 ≈ 251.3
$arc = 251.3 * $pct / 100;

// ---------- Парк сети: масштаб инфраструктуры ----------
$fleet = [];   // тип -> количество (hardware)
$res = $mysqli->query(
    "SELECT mt.name, COUNT(*) c
     FROM hardware h
     JOIN model m ON m.id = h.id_model
     JOIN model_type mt ON mt.id = m.model_type_id
     WHERE h.active = 1
     GROUP BY mt.name ORDER BY c DESC");
if ($res) while ($r = $res->fetch_assoc()) $fleet[$r['name']] = (int)$r['c'];

$statSites = 0;
$res = $mysqli->query("SELECT COUNT(*) c FROM mt_sites");
if ($res) $statSites = (int)$res->fetch_assoc()['c'];

$statLocs = 0;
$res = $mysqli->query("SELECT COUNT(*) c FROM locations");
if ($res) $statLocs = (int)$res->fetch_assoc()['c'];

$statMon = 0;
$res = $mysqli->query("SELECT COUNT(*) c FROM hw_ping_state");
if ($res) $statMon = (int)$res->fetch_assoc()['c'];

// сколько устройств должно мониториться: активные hardware с IP (выборка ping_monitor.py)
$statMonActive = 0;
$res = $mysqli->query(
    "SELECT COUNT(*) c FROM hardware
     WHERE active = 1 AND hardware_ip IS NOT NULL AND hardware_ip <> ''");
if ($res) $statMonActive = (int)$res->fetch_assoc()['c'];

// включён ли мониторинг (флаг живёт в app_settings)
$monEnabled = true;
$res = $mysqli->query("SELECT value FROM app_settings WHERE name = 'monitoring_enabled'");
if ($res && ($r = $res->fetch_assoc())) $monEnabled = trim((string)$r['value']) !== '0';

$statBkCnt = 0; $statBkGb = 0;
$res = $mysqli->query("SELECT COUNT(*) c, COALESCE(SUM(file_size),0) s FROM mt_backups");
if ($res) {
    $row = $res->fetch_assoc();
    $statBkCnt = (int)$row['c'];
    $statBkGb = round($row['s'] / 1073741824, 1);
}
?>

<div class="container-fluid py-3">
    <div class="panel mb-2" style="padding: 8px 12px;">
        <h5 class="mb-0 fw-bold">💚 Здоровье сети</h5>
    </div>

    <?php if ($redZone): ?>
        <div class="alert alert-danger py-2">
            <b>Красная зона:</b> <?= htmlspecialchars(implode('; ', $redZone)) ?>.
            <?= $capped ? 'Общая оценка ограничена 49, пока проблема не устранена.' : '' ?>
        </div>
    <?php endif; ?>

    <?php if ($backupTodayWarn): ?>
        <div class="alert alert-warning py-2">
            <i class="bi bi-exclamation-triangle"></i> <b>Внимание:</b> <?= htmlspecialchars($backupTodayWarn) ?>.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <!-- Спидометр -->
        <div class="col-xl-3 col-lg-4">
            <div class="panel p-3 text-center h-100">
                <svg viewBox="0 0 200 115" style="max-width: 260px; width: 100%;">
                    <path d="M 20 105 A 80 80 0 0 1 180 105" fill="none" stroke="#343a40" stroke-width="16" stroke-linecap="round"/>
                    <path d="M 20 105 A 80 80 0 0 1 180 105" fill="none" stroke="<?= $gaugeColor ?>"
                          stroke-width="16" stroke-linecap="round"
                          stroke-dasharray="<?= $arc ?> 251.3"/>
                    <text x="100" y="88" text-anchor="middle" fill="<?= $gaugeColor ?>"
                          font-size="34" font-weight="700"><?= $health === null ? '—' : number_format($health, 1, '.', '') ?></text>
                    <text x="100" y="108" text-anchor="middle" fill="#adb5bd" font-size="11">из 100</text>
                </svg>
                <div class="fw-semibold"><?=
                    $health === null ? 'Нет данных' :
                    ($health >= 80 ? 'Сеть в порядке' : ($health >= 50 ? 'Есть замечания' : 'Требуется внимание'))
                ?></div>
                <?php if ($orgMin7d > 0): ?>
                    <div class="small text-muted mt-1">
                        Простой по организационным причинам за 7 дней: <?= round($orgMin7d) ?> мин
                        (в оценку не входит)
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Составляющие -->
        <div class="col-xl-9 col-lg-8">
            <div class="row g-3">
                <?php foreach ($parts as $p): $c = healthColor($p['score']); ?>
                <div class="col-xl col-md-6 col-lg-4">
                    <div class="panel p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold"
                                  title="Вклад в общую оценку: <?= (int)$p['w'] ?>%<?= !empty($p['title']) ? '&#10;' . htmlspecialchars($p['title']) : '' ?>">
                                <i class="bi <?= $p['icon'] ?>"></i> <?= $p['label'] ?>
                            </span>
                            <span class="fw-bold" style="color: <?= $c ?>"><?= $p['score'] === null ? 'н/д' : number_format($p['score'], 1, '.', '') ?></span>
                        </div>
                        <div class="progress mb-1" style="height: 6px; background: #343a40;">
                            <div class="progress-bar" style="width: <?= $p['score'] ?? 0 ?>%; background: <?= $c ?>;"></div>
                        </div>
                        <div class="small text-muted"><?= htmlspecialchars($p['note'] ?: 'нет данных') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Сейчас не в сети -->
            <?php if ($downNow): ?>
            <div class="panel p-3 mt-3 border border-danger">
                <div class="fw-semibold text-danger mb-2">
                    <i class="bi bi-arrow-down-circle"></i> Сейчас не в сети: <?= count($downNow) ?>
                </div>
                <?php foreach ($downNow as $d): ?>
                    <span class="badge bg-danger me-2 mb-1" style="font-size: 0.9rem;">
                        <?= htmlspecialchars($d['switch_name'] ?? "ID {$d['hardware_id']}") ?>
                        · с <?= htmlspecialchars($d['started_at']) ?>
                        (<?= fmtDur($d['started_at'], date('Y-m-d H:i:s')) ?>)
                    </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Бэкапы по локациям -->
    <?php if ($bkLocs): ?>
    <div class="panel p-3 mb-3">
        <div class="fw-semibold mb-2 border-bottom pb-1">
            <i class="bi bi-archive"></i> Бэкапы по локациям
            <span class="text-muted small fw-normal">— свежие (≤<?= $backupHrs ?> ч) / просроченные / отсутствуют</span>
        </div>
        <div class="row g-3">
            <?php foreach ($bkLocs as $loc => $d):
                $sc = $d['total'] > 0 ? round($d['fresh'] / $d['total'] * 100, 1) : null;
                $c = healthColor($sc);
                $cid = 'bkLoc' . md5($loc);
            ?>
            <div class="col-xl-2 col-lg-3 col-md-4">
                <div class="panel p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-semibold text-truncate" title="<?= htmlspecialchars($loc) ?>">
                            <?= htmlspecialchars($loc) ?>
                        </span>
                        <span class="fw-bold" style="color: <?= $c ?>"><?= $sc === null ? 'н/д' : number_format($sc, 1, '.', '') ?></span>
                    </div>
                    <div class="progress mb-1" style="height: 6px; background: #343a40;">
                        <div class="progress-bar" style="width: <?= $sc ?? 0 ?>%; background: <?= $c ?>;"></div>
                    </div>
                    <div class="small">
                        <span class="text-success"><?= $d['fresh'] ?> свежих</span>
                        <?php if ($d['stale']): ?> · <span class="text-warning"><?= $d['stale'] ?> просрочено</span><?php endif; ?>
                        <?php if ($d['none']):  ?> · <span class="text-danger"><?= $d['none'] ?> нет</span><?php endif; ?>
                        <span class="text-muted"> из <?= $d['total'] ?></span>
                    </div>
                    <?php if ($d['problems']): ?>
                        <a class="small text-decoration-none" data-bs-toggle="collapse"
                           href="#<?= $cid ?>" role="button" aria-expanded="false">
                            <i class="bi bi-chevron-down"></i> Проблемные: <?= count($d['problems']) ?>
                        </a>
                        <div class="collapse" id="<?= $cid ?>">
                            <div style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-sm mb-0 mt-1">
                                <?php foreach ($d['problems'] as $p): ?>
                                <tr<?= !empty($p[3]) ? ' title="' . htmlspecialchars($p[3]) . '"' : '' ?>>
                                    <td class="text-truncate" style="max-width: 150px; <?= !empty($p[3]) ? 'cursor: help; text-decoration: underline dotted;' : '' ?>"
                                        title="<?= htmlspecialchars(!empty($p[3]) ? $p[3] : $p[0]) ?>"><?= htmlspecialchars($p[0]) ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($p[1] === 'none'): ?>
                                            <span class="badge bg-danger">нет</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">просрочен</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted text-nowrap small">
                                        <?= $p[2] ? htmlspecialchars(date('d.m H:i', strtotime($p[2]))) : '—' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Прошивки по производителям -->
    <?php
    $fwCards = [];
    if ($mtScore !== null) {
        $fwCards[] = [
            'label' => 'MikroTik',
            'icon'  => 'bi-router',
            'score' => $mtScore, 'ok' => $mtOk, 'total' => $mtTotal,
            'hint'  => 'Эталон: RouterOS ' . ($mtEtalon ?? '—') . ' (объекты + hardware)',
            'out'   => $mtOut,
        ];
    }
    foreach ($fwVendors as $bname => $v) {
        $fwCards[] = [
            'label' => $bname,
            'icon'  => 'bi-hdd-network',
            'score' => $v['score'], 'ok' => $v['ok'], 'total' => $v['total'],
            'hint'  => $bname === 'tplink'
                ? 'Эталон — самая новая версия из имеющихся в парке этой модели (у каждой модели своя прошивка + hardware-ревизия, оценка приблизительная)'
                : 'Эталон — самая новая версия из имеющихся в парке этой модели',
            'out'   => $v['out'],
        ];
    }
    ?>
    <?php if ($fwCards): ?>
    <div class="panel p-3 mb-3">
        <div class="fw-semibold mb-2 border-bottom pb-1">
            <i class="bi bi-cpu"></i> Прошивки по производителям
            <span class="text-muted small fw-normal">— кто отстал от эталона</span>
        </div>
        <div class="row g-3">
            <?php foreach ($fwCards as $i => $cd): $c = healthColor($cd['score']); ?>
            <div class="col-xl-4 col-lg-6">
                <div class="panel p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-semibold" title="<?= htmlspecialchars($cd['hint']) ?>">
                            <i class="bi <?= $cd['icon'] ?>"></i> <?= htmlspecialchars($cd['label']) ?>
                        </span>
                        <span class="fw-bold" style="color: <?= $c ?>"><?= number_format($cd['score'], 1, '.', '') ?></span>
                    </div>
                    <div class="progress mb-1" style="height: 6px; background: #343a40;">
                        <div class="progress-bar" style="width: <?= $cd['score'] ?>%; background: <?= $c ?>;"></div>
                    </div>
                    <div class="small text-muted mb-1"><?= $cd['ok'] ?> из <?= $cd['total'] ?> на эталонной версии</div>
                    <?php if ($cd['out']): ?>
                        <a class="small text-decoration-none" data-bs-toggle="collapse"
                           href="#fwOut<?= $i ?>" role="button" aria-expanded="false">
                            <i class="bi bi-chevron-down"></i> Отстающие: <?= count($cd['out']) ?>
                        </a>
                        <div class="collapse" id="fwOut<?= $i ?>">
                            <div class="small text-muted mt-1 mb-1"><?= htmlspecialchars($cd['hint']) ?></div>
                            <div style="max-height: 260px; overflow-y: auto;">
                            <table class="table table-sm mb-0">
                                <?php foreach ($cd['out'] as $o): ?>
                                <tr>
                                    <td class="text-truncate" style="max-width: 180px;"
                                        title="<?= htmlspecialchars($o[3]) ?>"><?= htmlspecialchars($o[0]) ?></td>
                                    <td class="text-danger text-nowrap"><?= htmlspecialchars($o[1]) ?></td>
                                    <td class="text-muted text-nowrap">→ <?= htmlspecialchars($o[2]) ?></td>
                                    <td class="text-muted small text-nowrap"><?= htmlspecialchars($o[3]) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="small text-success">Все на эталонной версии ✓</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Парк сети -->
    <div class="panel p-3 mb-3">
        <div class="fw-semibold mb-2 border-bottom pb-1">
            <i class="bi bi-hdd-network"></i> Парк сети
        </div>
        <div class="d-flex flex-wrap gap-4">
            <?php foreach ($fleet as $tname => $cnt): ?>
                <div><span class="fs-5 fw-bold"><?= $cnt ?></span>
                    <span class="text-muted"><?= htmlspecialchars($tname) ?></span></div>
            <?php endforeach; ?>
            <div><span class="fs-5 fw-bold"><?= $statSites ?></span>
                <span class="text-muted">площадок MikroTik (mt_sites)</span></div>
            <div><span class="fs-5 fw-bold"><?= $statLocs ?></span>
                <span class="text-muted">локаций</span></div>
            <div><span class="fs-5 fw-bold"><?= $statMon ?></span>
                <span class="text-muted">в мониторинге (из <?= $statMonActive ?> активных)</span>
                <?php if (!$monEnabled): ?>
                    <span class="badge bg-warning text-dark">мониторинг выключен</span>
                <?php endif; ?></div>
            <div><span class="fs-5 fw-bold"><?= $statBkCnt ?></span>
                <span class="text-muted">файлов бэкапов в архиве (<?= $statBkGb ?> ГБ)</span></div>
        </div>
    </div>

    <!-- Точки доступа вне контроллера (ctrl_monitor.py) -->
    <?php
    $apBad = [];
    $chk = $mysqli->query("SHOW TABLES LIKE 'ap_ctrl_state'");
    if ($chk && $chk->num_rows) {
        $res = $mysqli->query("
            SELECT s.state, s.name_in_ctrl, s.last_ok_at,
                   h.switch_name, h.hardware_ip
            FROM ap_ctrl_state s
            JOIN hardware h ON h.id = s.hardware_id
            WHERE s.state <> 'adopted'
            ORDER BY s.state, h.switch_name");
        if ($res) while ($r = $res->fetch_assoc()) $apBad[] = $r;
    }
    ?>
    <?php if ($apBad): ?>
    <div class="panel p-3 mb-3 border border-danger">
        <div class="fw-semibold mb-2 border-bottom pb-1">
            <i class="bi bi-router"></i> Точки доступа вне контроллера: <?= count($apBad) ?>
        </div>
        <div class="small text-muted mb-2">
            Точка жива в сети (пингуется), но контроллер её не видит — разадоптировалась
            или сбился inform-адрес. Ping-мониторинг такое не ловит.
        </div>
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Точка</th>
                    <th>IP</th>
                    <th>Состояние</th>
                    <th>Имя в контроллере</th>
                    <th>Последний adopted</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apBad as $ap): ?>
                <tr>
                    <td><?= htmlspecialchars($ap['switch_name'] ?: '—') ?></td>
                    <td class="font-monospace"><?= htmlspecialchars($ap['hardware_ip']) ?></td>
                    <td>
                        <span class="badge <?= $ap['state'] === 'missing' ? 'bg-danger' : 'bg-warning text-dark' ?>">
                            <?= $ap['state'] === 'missing' ? 'нет у контроллера' : 'отключена' ?>
                        </span>
                    </td>
                    <td class="text-muted"><?= htmlspecialchars($ap['name_in_ctrl']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars((string)$ap['last_ok_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Журнал инцидентов -->
    <div class="panel p-4">
        <div class="fw-semibold mb-1 border-bottom pb-1">
            <i class="bi bi-journal-text"></i> Журнал инцидентов (30 дней)
        </div>
        <div class="small text-muted mb-3">
            Открывается и закрывается автоматически мониторингом. Укажите причину — инциденты
            по организационным причинам (питание, доступ в помещение, провайдер) не снижают оценку сети.
        </div>
        <div id="healthMsg" class="mb-2"></div>
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Устройство</th>
                    <th>Начало</th>
                    <th>Конец</th>
                    <th>Длительность</th>
                    <th style="width: 280px;">Причина</th>
                    <th>Комментарий</th>
                    <?php if ($can_edit): ?><th style="width: 90px;"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$incidents): ?>
                <tr><td colspan="7" class="text-muted">Инцидентов не зафиксировано 🎉</td></tr>
            <?php endif; ?>
            <?php foreach ($incidents as $inc):
                $open = $inc['ended_at'] === null;
                $org = in_array($inc['cause'], $ORG_CAUSES, true);
            ?>
                <tr class="<?= $open ? 'table-danger' : '' ?>">
                    <td class="fw-semibold"><?= htmlspecialchars($inc['switch_name'] ?? "ID {$inc['hardware_id']}") ?></td>
                    <td class="small"><?= htmlspecialchars($inc['started_at']) ?></td>
                    <td class="small"><?= $open ? '<span class="badge bg-danger">down сейчас</span>' : htmlspecialchars($inc['ended_at']) ?></td>
                    <td class="small"><?= fmtDur($inc['started_at'], $inc['ended_at'] ?: date('Y-m-d H:i:s')) ?></td>
                    <td>
                        <?php if ($can_edit): ?>
                        <select class="form-select form-select-sm inc-cause" data-id="<?= (int)$inc['id'] ?>">
                            <?php foreach ($CAUSES as $ck => $cl): ?>
                                <option value="<?= $ck ?>" <?= $inc['cause'] === $ck ? 'selected' : '' ?>><?= $cl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                            <?= htmlspecialchars($CAUSES[$inc['cause']] ?? $inc['cause']) ?>
                        <?php endif; ?>
                        <?php if ($org): ?><span class="badge bg-secondary mt-1">организационная</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($can_edit): ?>
                        <input type="text" class="form-control form-control-sm inc-comment"
                               data-id="<?= (int)$inc['id'] ?>"
                               value="<?= htmlspecialchars($inc['comment']) ?>"
                               placeholder="комментарий…">
                        <?php else: ?>
                            <?= htmlspecialchars($inc['comment']) ?>
                        <?php endif; ?>
                    </td>
                    <?php if ($can_edit): ?>
                    <td>
                        <button type="button" class="btn btn-outline-primary btn-sm inc-save"
                                data-id="<?= (int)$inc['id'] ?>">✓</button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var AED = '/mod/health/dataAED.php';

    function showMsg(text, ok) {
        var el = document.getElementById('healthMsg');
        el.innerHTML = '<div class="alert ' + (ok ? 'alert-success' : 'alert-danger') + ' py-2 mb-0"></div>';
        el.firstChild.textContent = text;
        clearTimeout(showMsg._t);
        showMsg._t = setTimeout(function () { el.innerHTML = ''; }, 4000);
    }

    document.querySelectorAll('.inc-save').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            var cause = document.querySelector('.inc-cause[data-id="' + id + '"]').value;
            var comment = document.querySelector('.inc-comment[data-id="' + id + '"]').value;
            fetch(AED, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ oper: 'incident_update', id: id, cause: cause, comment: comment }).toString()
            })
            .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, text: t }; }); })
            .then(function (res) { showMsg((res.text || 'Сохранено').trim(), res.ok); })
            .catch(function (e) { showMsg('Ошибка сети: ' + e, false); });
        });
    });
})();
</script>

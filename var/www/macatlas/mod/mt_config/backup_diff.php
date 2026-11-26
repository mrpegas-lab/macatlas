<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}

header('Content-Type: application/json; charset=utf-8');

$toId   = isset($_GET['to_id'])   ? (int)$_GET['to_id']   : 0;
$fromId = isset($_GET['from_id']) ? (int)$_GET['from_id'] : 0;

if (!$toId) { 
    echo json_encode(['error' => 'Не передан to_id']); 
    exit; 
}

// Функция получения данных файла из БД
function fetch_bkp(mysqli $db, int $id): ?array {
    $q = $db->prepare("SELECT * FROM mt_backups WHERE id = ?");
    $q->bind_param('i', $id); 
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    return $r ?: null;
}

$to = fetch_bkp($mysqli, $toId);
if (!$to) { 
    echo json_encode(['error' => 'Файл (to_id) не найден в БД']); 
    exit; 
}

if (!$fromId && $to['prev_id']) {
    $fromId = (int)$to['prev_id'];
}
$from = $fromId ? fetch_bkp($mysqli, $fromId) : null;
if (!$from) { 
    echo json_encode(['error' => 'Нет предыдущего файла для сравнения']); 
    exit; 
}

// Проверка, что файлы реально существуют на диске
if (!is_file($to['file_path']) || !is_file($from['file_path'])) {
    echo json_encode(['error' => 'Файлы отсутствуют на диске по указанным путям']);
    exit;
}

// Бинарный .backup
if ($to['file_type'] === 'backup') {
    echo json_encode([
        'error'     => null,
        'type'      => 'binary',
        'file'      => $to['file_name'],
        'prev'      => $from['file_name'],
        'size'      => (int)$to['file_size'],
        'prev_size' => (int)$from['file_size'],
        'changed'   => (int)$to['file_size'] !== (int)$from['file_size'],
        'note'      => 'Бинарный .backup — построчное сравнение невозможно.',
    ]);
    exit;
}

// Сначала — кеш
$q = $mysqli->prepare("SELECT added, removed, diff_json FROM mt_backup_diffs WHERE from_id=? AND to_id=?");
$q->bind_param('ii', $from['id'], $to['id']);
$q->execute();
$cache = $q->get_result()->fetch_assoc();

if ($cache) {
    echo json_encode([
        'error'   => null,
        'type'    => 'text',
        'file'    => $to['file_name'],
        'prev'    => $from['file_name'],
        'changed' => true,
        'cached'  => true,
        'added'   => (int)$cache['added'],
        'removed' => (int)$cache['removed'],
        'lines'   => json_decode($cache['diff_json'], true),
    ]);
    exit;
}

// Быстрая проверка: хэши совпадают
if ($to['sha256'] && $from['sha256'] && $to['sha256'] === $from['sha256']) {
    echo json_encode([
        'error'   => null,
        'type'    => 'text',
        'file'    => $to['file_name'],
        'prev'    => $from['file_name'],
        'changed' => false,
        'added'   => 0,
        'removed' => 0,
        'lines'   => [],
    ]);
    exit;
}

// Читаем файлы для диффа
$a = @file($from['file_path'], FILE_IGNORE_NEW_LINES);
$b = @file($to['file_path'],   FILE_IGNORE_NEW_LINES);
if ($a === false || $b === false) {
    echo json_encode(['error' => 'Ошибка чтения файлов с диска (проверьте права)']);
    exit;
}

// .rsc: комментарии и пустые строки в diff не показываем — как в хэше
// индексатора, иначе каждый экспорт «отличается» служебной строкой
// "# 2026-11-14 10:16:23 by RouterOS ..."
if ($to['file_type'] === 'rsc') {
    $skipComments = function ($lines) {
        return array_values(array_filter($lines, function ($l) {
            $t = trim($l);
            return $t !== '' && $t[0] !== '#';
        }));
    };
    $a = $skipComments($a);
    $b = $skipComments($b);
}

// Игнор-лист строк конфига (regex из config.json → backup_ignore):
// динамические записи вроде address-list'ов VPN-клиентов
// (list=l2tp-inside) в diff не показываем — это не изменение конфига
$patterns = $config['backup_ignore'] ?? [];
if ($patterns) {
    $re = '~' . implode('|', array_map(function($p) { return '(?:' . $p . ')'; }, $patterns)) . '~u';
    $a = array_values(array_filter($a, function($l) use ($re) { return !preg_match($re, $l); }));
    $b = array_values(array_filter($b, function($l) use ($re) { return !preg_match($re, $l); }));
}

$diff    = build_diff($a, $b);
$added   = 0; $removed = 0;
foreach ($diff as $d) {
    if ($d[0] === '+') $added++;
    elseif ($d[0] === '-') $removed++;
}

// Кешируем
$json = json_encode($diff, JSON_UNESCAPED_UNICODE);
$ins = $mysqli->prepare("INSERT INTO mt_backup_diffs (from_id, to_id, added, removed, diff_json, created_at) 
                         VALUES (?,?,?,?,?,NOW()) 
                         ON DUPLICATE KEY UPDATE added=VALUES(added), removed=VALUES(removed), diff_json=VALUES(diff_json)");
$ins->bind_param('iiiis', $from['id'], $to['id'], $added, $removed, $json);
$ins->execute();
$ins->close();

echo json_encode([
    'error'   => null,
    'type'    => 'text',
    'file'    => $to['file_name'],
    'prev'    => $from['file_name'],
    'changed' => ($added + $removed > 0),
    'added'   => $added,
    'removed' => $removed,
    'lines'   => $diff,
]);

function build_diff(array $a, array $b): array {
    $n = count($a); $m = count($b);
    // Большие файлы (тысячи строк): точный LCS съест всю память,
    // поэтому — построчное сравнение с ресинхронизацией (lookahead).
    // Старый наивный fallback сравнивал строки по позиции: одна
    // добавленная строка сдвигала всё, и diff «сходил с ума».
    if ($n * $m > 4_000_000) {
        return build_diff_resync($a, $b);
    }
    $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--)
        for ($j = $m - 1; $j >= 0; $j--)
            $dp[$i][$j] = ($a[$i] === $b[$j]) ? $dp[$i+1][$j+1] + 1 : max($dp[$i+1][$j], $dp[$i][$j+1]);
    $out = []; $i = 0; $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) { $out[] = [' ', $a[$i]]; $i++; $j++; }
        elseif ($dp[$i+1][$j] >= $dp[$i][$j+1]) { $out[] = ['-', $a[$i]]; $i++; }
        else { $out[] = ['+', $b[$j]]; $j++; }
    }
    while ($i < $n) { $out[] = ['-', $a[$i]]; $i++; }
    while ($j < $m) { $out[] = ['+', $b[$j]]; $j++; }
    return $out;
}

// Построчное сравнение с ресинхронизацией для больших файлов.
// Идём по обоим файлам; при расхождении ищем в окне LOOKAHEAD
// ближайшую пару совпадающих строк и «сходимся» на ней:
// пропущенное слева = удалено, справа = добавлено.
// Повторяющиеся строки-якоря (одинаковые «add action=accept ...»)
// не ломают картину: выбирается совпадение с минимальным сдвигом.
function build_diff_resync(array $a, array $b): array {
    $n = count($a); $m = count($b);
    $out = [];
    $i = 0; $j = 0;
    $LOOKAHEAD = 60;

    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $out[] = [' ', $a[$i]]; $i++; $j++;
            continue;
        }
        $best = null; // [di, dj, shift]
        $dmax = min($LOOKAHEAD, max($n - $i, $m - $j) - 1);
        for ($d = 1; $d <= $dmax && $best === null; $d++) {
            for ($di = 0; $di <= $d; $di++) {
                $dj = $d - $di;
                if ($i + $di < $n && $j + $dj < $m && $a[$i + $di] === $b[$j + $dj]) {
                    $best = [$di, $dj];
                    break;
                }
            }
        }
        if ($best === null) {
            // Совпадений в окне нет — выкидываем обе строки и идём дальше
            $out[] = ['-', $a[$i]]; $i++;
            $out[] = ['+', $b[$j]]; $j++;
            continue;
        }
        for ($k = 0; $k < $best[0]; $k++) $out[] = ['-', $a[$i + $k]];
        for ($k = 0; $k < $best[1]; $k++) $out[] = ['+', $b[$j + $k]];
        $i += $best[0]; $j += $best[1];
    }
    while ($i < $n) { $out[] = ['-', $a[$i]]; $i++; }
    while ($j < $m) { $out[] = ['+', $b[$j]]; $j++; }
    return $out;
}
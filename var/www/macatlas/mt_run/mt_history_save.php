<?php
// Файл: /mod/mt_run/mt_history_save.php
include_once("../../loadinc.php");
header('Content-Type: application/json; charset=utf-8');

function out($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

// === Проверка прав: запись в историю — только с правом «выполнение» ===
if (!$can_exec) {
    out(['success' => false, 'error' => 'Нет прав на выполнение команд'], 403);
}

$mode     = $in['mode']     ?? 'run';       // run | dry | debug
$commands = (string)($in['commands'] ?? ''); // полный текст шаблона
$items    = $in['items']    ?? [];           // список устройств с результатами

// === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
$slot = (int)($in['slot'] ?? 0);
if ($slot < 1 || $slot > 5) $slot = 0;   // 0 = слот не выбран, в слоты не пишем
// === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===

if (!in_array($mode, ['run','dry','debug'], true)) $mode = 'run';
if (!is_array($items) || !count($items)) out(['success' => false, 'error' => 'Пустой список items'], 400);

$userId    = (int)($_SESSION['auth'] ?? 0);
$userFIO = (string)($_SESSION['fio'] ?? '');

$total = count($items);
$okCnt = 0;
foreach ($items as $it) {
    if (!empty($it['success'])) $okCnt++;
}
$failCnt = $total - $okCnt;

$mysqli->begin_transaction();

try {
    $stmt = $mysqli->prepare(
        "INSERT INTO mt_history_runs
            (user_id, user_fio, mode, commands_text, total, ok_count, fail_count)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('isssiii', $userId, $userFIO, $mode, $commands, $total, $okCnt, $failCnt);
    $stmt->execute();
    $runId = $stmt->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare(
        "INSERT INTO mt_history_items
            (run_id, site_id, hostname, ip, success, error_text, output)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    // === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    $stmtSlot = null;
    if ($slot > 0 && $mode === 'run') {
        $stmtSlot = $mysqli->prepare(
            "INSERT INTO mt_run_slot_items (slot, site_id, status, error_text)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status=VALUES(status),
                error_text=VALUES(error_text),
                updated_at=NOW()"
        );
    }
    // === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===

    foreach ($items as $it) {
        $siteId   = (int)($it['site_id'] ?? 0);
        $hostname = (string)($it['hostname'] ?? '');
        $ip       = (string)($it['ip'] ?? '');
        $success  = !empty($it['success']) ? 1 : 0;
        $errText  = isset($it['error'])  ? (string)$it['error']  : null;
        $output   = isset($it['output']) ? (string)$it['output'] : null;

        $stmt->bind_param(
            'iisssss',
            $runId, $siteId, $hostname, $ip, $success, $errText, $output
        );
        $stmt->execute();

        // === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
        if ($stmtSlot && $siteId > 0) {
            $stStatus = $success ? 'ok' : 'fail';
            $stErr    = $success ? null : $errText;
            $stmtSlot->bind_param('iiss', $slot, $siteId, $stStatus, $stErr);
            $stmtSlot->execute();
        }
        // === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    }
    $stmt->close();

    // === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    if ($stmtSlot) $stmtSlot->close();

    // Обновляем сохранённый шаблон слота — только в боевом режиме
    if ($slot > 0 && $mode === 'run' && $commands !== '') {
        $stmtC = $mysqli->prepare(
            "UPDATE mt_run_slots SET commands=? WHERE slot=?"
        );
        $stmtC->bind_param('si', $commands, $slot);
        $stmtC->execute();
        $stmtC->close();
    }
    // === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===

    $mysqli->commit();

    out([
        'success' => true,
        'run_id'  => $runId,
    ]);

} catch (Throwable $e) {
    $mysqli->rollback();
    out(['success' => false, 'error' => 'DB: ' . $e->getMessage()], 500);
}
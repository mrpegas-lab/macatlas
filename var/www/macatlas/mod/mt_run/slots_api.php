<?php
// Файл: /mod/mt_run/slots_api.php
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

$action = (string)($in['action'] ?? '');
$slot   = (int)($in['slot'] ?? 0);

// === Проверка прав: чтение — can_view, всё остальное (сохранение слотов/отметок) — can_exec ===
if (in_array($action, ['list', 'load'], true)) {
    if (!$can_view) out(['success'=>false, 'error'=>'Нет прав на просмотр'], 403);
} else {
    if (!$can_exec) out(['success'=>false, 'error'=>'Нет прав на выполнение'], 403);
}

//if ($slot < 1 || $slot > 5) out(['success'=>false,'error'=>'slot 1..5'], 400);
// Блокируем только те действия, которым реально нужен конкретный слот
if ($action !== 'list' && ($slot < 1 || $slot > 5)) {
    out(['success'=>false,'error'=>'slot 1..5'], 400);
}

try {
    switch ($action) {

        case 'list': {
            $rows = [];
            $res = $mysqli->query(
                "SELECT s.slot, s.title, s.updated_at,
                        COALESCE(SUM(i.status='ok'),0)   AS ok_cnt,
                        COALESCE(SUM(i.status='fail'),0) AS fail_cnt,
                        LENGTH(COALESCE(s.commands,''))   AS cmd_len
                   FROM mt_run_slots s
                   LEFT JOIN mt_run_slot_items i ON i.slot = s.slot
                  GROUP BY s.slot
                  ORDER BY s.slot"
            );
            while ($r = $res->fetch_assoc()) {
                $r['slot']    = (int)$r['slot'];
                $r['ok_cnt']  = (int)$r['ok_cnt'];
                $r['fail_cnt']= (int)$r['fail_cnt'];
                $r['cmd_len'] = (int)$r['cmd_len'];
                $rows[] = $r;
            }
            out(['success'=>true, 'slots'=>$rows]);
        }

        case 'load': {
            $stmt = $mysqli->prepare(
                "SELECT slot, title, commands, updated_at FROM mt_run_slots WHERE slot=?"
            );
            $stmt->bind_param('i', $slot);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$r) out(['success'=>false,'error'=>'Слот не найден'], 404);
            $r['slot'] = (int)$r['slot'];
            out(['success'=>true, 'slot'=>$r]);
        }

        case 'save': {
            $title    = isset($in['title'])    ? (string)$in['title']    : null;
            $commands = isset($in['commands']) ? (string)$in['commands'] : null;

            if ($title !== null && $commands !== null) {
                $stmt = $mysqli->prepare(
                    "UPDATE mt_run_slots SET title=?, commands=? WHERE slot=?"
                );
                $stmt->bind_param('ssi', $title, $commands, $slot);
            } elseif ($title !== null) {
                $stmt = $mysqli->prepare(
                    "UPDATE mt_run_slots SET title=? WHERE slot=?"
                );
                $stmt->bind_param('si', $title, $slot);
            } elseif ($commands !== null) {
                $stmt = $mysqli->prepare(
                    "UPDATE mt_run_slots SET commands=? WHERE slot=?"
                );
                $stmt->bind_param('si', $commands, $slot);
            } else {
                out(['success'=>false,'error'=>'Нечего сохранять'], 400);
            }
            $stmt->execute();
            $stmt->close();
            out(['success'=>true]);
        }

        case 'reset': {
            $mysqli->begin_transaction();
            try {
                $stmt = $mysqli->prepare("DELETE FROM mt_run_slot_items WHERE slot=?");
                $stmt->bind_param('i', $slot);
                $stmt->execute();
                $stmt->close();

                $stmt = $mysqli->prepare(
                    "UPDATE mt_run_slots SET title='', commands=NULL WHERE slot=?"
                );
                $stmt->bind_param('i', $slot);
                $stmt->execute();
                $stmt->close();

                $mysqli->commit();
                out(['success'=>true]);
            } catch (Throwable $e) {
                $mysqli->rollback();
                throw $e;
            }
        }

        case 'reset_items': {
            $stmt = $mysqli->prepare("DELETE FROM mt_run_slot_items WHERE slot=?");
            $stmt->bind_param('i', $slot);
            $stmt->execute();
            $stmt->close();
            out(['success'=>true]);
        }

        case 'mark': {
            $siteId   = (int)($in['site_id'] ?? 0);
            $status   = (string)($in['status'] ?? '');
            $errText  = isset($in['error']) ? (string)$in['error'] : null;
            if (!$siteId) out(['success'=>false,'error'=>'site_id'], 400);
            if (!in_array($status, ['ok','fail'], true)) {
                out(['success'=>false,'error'=>'status ok|fail'], 400);
            }
            $stmt = $mysqli->prepare(
                "INSERT INTO mt_run_slot_items (slot, site_id, status, error_text)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    status=VALUES(status),
                    error_text=VALUES(error_text),
                    updated_at=NOW()"
            );
            $stmt->bind_param('iiss', $slot, $siteId, $status, $errText);
            $stmt->execute();
            $stmt->close();
            out(['success'=>true]);
        }

        default:
            out(['success'=>false,'error'=>'unknown action'], 400);
    }
} catch (Throwable $e) {
    out(['success'=>false,'error'=>'DB: '.$e->getMessage()], 500);
}
<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

if (!$can_edit) {
    http_response_code(403);
    exit('Нет прав на редактирование');
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || ($data['oper'] ?? '') !== 'save' || empty($data['rows'])) {
    exit('Неверные данные');
}

$mysqli->begin_transaction();

try {
    // Собираем данные в удобную структуру
    $map = [];
    foreach ($data['rows'] as $r) {
        $rid  = (int)$r['role_id'];
        $sid  = (int)$r['resource_id'];
        $perm = $r['perm'];
        if (!in_array($perm, ['can_view','can_add','can_edit','can_del','can_exec'], true)) continue;

        if (!isset($map[$rid])) $map[$rid] = [];
        if (!isset($map[$rid][$sid])) $map[$rid][$sid] = ['can_view'=>0, 'can_add'=>0, 'can_edit'=>0, 'can_del'=>0, 'can_exec'=>0];

        $map[$rid][$sid][$perm] = (int)$r['val'];
    }

    // Пишем в базу
    foreach ($map as $rid => $resMap) {
        foreach ($resMap as $sid => $perms) {
            $v = $perms['can_view'];
            $a = $perms['can_add'];
            $e = $perms['can_edit'];
            $d = $perms['can_del'];
            $x = $perms['can_exec'];

            // Если все нули — удаляем запись
            if ($v === 0 && $a === 0 && $e === 0 && $d === 0 && $x === 0) {
                $stmt = $mysqli->prepare("DELETE FROM acl_permissions WHERE role_id = ? AND resource_id = ?");
                $stmt->bind_param('ii', $rid, $sid);
                $stmt->execute();
                $stmt->close();
            } else {
                // Если есть хоть одна 1 — вставляем/обновляем
                $stmt = $mysqli->prepare(
                    "INSERT INTO acl_permissions (role_id, resource_id, can_view, can_add, can_edit, can_del, can_exec)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        can_view = VALUES(can_view),
                        can_add  = VALUES(can_add),
                        can_edit = VALUES(can_edit),
                        can_del  = VALUES(can_del),
                        can_exec = VALUES(can_exec)"
                );
                $stmt->bind_param('iiiiiii', $rid, $sid, $v, $a, $e, $d, $x);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    $mysqli->commit();
    exit;
} catch (Throwable $ex) {
    $mysqli->rollback();
    exit('Ошибка: ' . $ex->getMessage());
}
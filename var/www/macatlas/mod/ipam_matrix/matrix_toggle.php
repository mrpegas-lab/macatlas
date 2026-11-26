<?php
// Переключение состояния ячейки матрицы VLAN: none → accept → drop → none
include_once("../../loadinc.php");
header('Content-Type: application/json; charset=utf-8');

function mx_fail($msg) {
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($can_edit)) mx_fail('Нет прав на изменение матрицы');

$src  = (int)($_POST['src'] ?? 0);
$type = (($_POST['dst_type'] ?? '') === 'internet') ? 'internet' : 'vlan';
$dst  = (int)($_POST['dst'] ?? 0);

if ($src <= 0) mx_fail('Не указан источник');
if ($type === 'vlan') {
    if ($dst <= 0) mx_fail('Не указано назначение');
    if ($dst === $src) mx_fail('Ячейка на диагонали не редактируется');
}

// Текущее состояние ячейки
if ($type === 'internet') {
    $stmt = $mysqli->prepare(
        "SELECT id, action FROM ipam_matrix
          WHERE src_vlan_id = ? AND dst_type = 'internet' AND dst_vlan_id IS NULL"
    );
    $stmt->bind_param('i', $src);
} else {
    $stmt = $mysqli->prepare(
        "SELECT id, action FROM ipam_matrix
          WHERE src_vlan_id = ? AND dst_type = 'vlan' AND dst_vlan_id = ?"
    );
    $stmt->bind_param('ii', $src, $dst);
}
$stmt->execute();
$cur = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Цикл состояний
$next     = ['none' => 'accept', 'accept' => 'drop', 'drop' => 'none'];
$curState = $cur['action'] ?? 'none';
$newState = $next[$curState];

if ($newState === 'none') {
    // «—» = отсутствие записи
    $stmt = $mysqli->prepare("DELETE FROM ipam_matrix WHERE id = ?");
    $stmt->bind_param('i', $cur['id']);
} elseif ($cur) {
    $stmt = $mysqli->prepare("UPDATE ipam_matrix SET action = ? WHERE id = ?");
    $stmt->bind_param('si', $newState, $cur['id']);
} else {
    if ($type === 'internet') {
        $stmt = $mysqli->prepare(
            "INSERT INTO ipam_matrix (src_vlan_id, dst_type, dst_vlan_id, action)
             VALUES (?, 'internet', NULL, ?)"
        );
        $stmt->bind_param('is', $src, $newState);
    } else {
        $stmt = $mysqli->prepare(
            "INSERT INTO ipam_matrix (src_vlan_id, dst_type, dst_vlan_id, action)
             VALUES (?, 'vlan', ?, ?)"
        );
        $stmt->bind_param('iis', $src, $dst, $newState);
    }
}
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true, 'state' => $newState], JSON_UNESCAPED_UNICODE);

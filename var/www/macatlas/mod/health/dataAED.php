<?php
// dataAED.php — изменения модуля «Здоровье сети» (AJAX).
// oper: incident_update — причина и комментарий инцидента.
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

$oper = $_POST['oper'] ?? '';

if ($oper === 'incident_update') {
    if (!$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
    $id = (int)($_POST['id'] ?? 0);
    $cause = preg_replace('/[^a-z]/', '', $_POST['cause'] ?? '');
    $allowed = ['', 'power', 'access', 'provider', 'hardware', 'config', 'other'];
    if (!in_array($cause, $allowed, true)) $cause = '';
    $comment = mb_substr(trim($_POST['comment'] ?? ''), 0, 500);

    $stmt = $mysqli->prepare("UPDATE incidents SET cause = ?, comment = ? WHERE id = ?");
    $stmt->bind_param('ssi', $cause, $comment, $id);
    $stmt->execute();
    exit('Инцидент обновлён');
}

http_response_code(400);
exit('Неизвестная операция');

<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

$id   = $_POST['id']   ?? '';
$code = trim($_POST['code'] ?? '');
$name = trim($_POST['name'] ?? '');

if (!$oper) exit;

if ($name === '') exit('Название обязательно');

// ---------- ДОБАВИТЬ ----------
if ($oper === 'add') {
    if (!preg_match('/^[a-z0-9_]+$/', $code)) exit('Код: только a-z, 0-9, _');

    $stmt = $mysqli->prepare("INSERT INTO acl_roles (code, name) VALUES (?, ?)");
    $stmt->bind_param('ss', $code, $name);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}

// ---------- ИЗМЕНИТЬ ----------
if ($oper === 'edit') {
    // узнаём, системная ли
    $stmt = $mysqli->prepare("SELECT is_system FROM acl_roles WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) exit('Роль не найдена');

    if ((int)$row['is_system']) {
        // code менять нельзя
        $stmt = $mysqli->prepare("UPDATE acl_roles SET name = ? WHERE id = ?");
        $stmt->bind_param('si', $name, $id);
    } else {
        if (!preg_match('/^[a-z0-9_]+$/', $code)) exit('Код: только a-z, 0-9, _');
        $stmt = $mysqli->prepare("UPDATE acl_roles SET code = ?, name = ? WHERE id = ?");
        $stmt->bind_param('ssi', $code, $name, $id);
    }
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}

// ---------- УДАЛИТЬ ----------
if ($oper === 'del') {
    // системную — нельзя
    $stmt = $mysqli->prepare("SELECT is_system FROM acl_roles WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) exit('Роль не найдена');
    if ((int)$row['is_system']) exit('Системную роль удалить нельзя');

    // есть ли сотрудники?
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM acl_staff WHERE role_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($c > 0) exit("Нельзя удалить — в роли {$c} сотрудник(ов)");

    $stmt = $mysqli->prepare("DELETE FROM acl_roles WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}
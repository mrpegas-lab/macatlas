<?php
include_once("../../loadinc.php");
header('Content-Type: text/html; charset=utf-8');

$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

$type = $_POST['type'] ?? 'group';
$id   = $_POST['id'] ?? '';
$name = trim($_POST['name'] ?? '');
$sort = (int)($_POST['sort'] ?? 0);

if (!$oper) exit;

// ==========================================
// УДАЛЕНИЕ (проверки на имя тут нет)
// ==========================================
if ($oper === 'del') {
    if ($type === 'group') {
        // Каскадное удаление пунктов и прав произойдет по FK
        $stmt = $mysqli->prepare("DELETE FROM acl_menu_groups WHERE id = ?");
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $mysqli->prepare("DELETE FROM acl_resources WHERE id = ?");
        $stmt->bind_param('i', $id);
    }

    if (isset($stmt)) {
        $stmt->execute();
        if ($stmt->error) exit('SQL: ' . $stmt->error);
        $stmt->close();
    }
    exit;
}

// ==========================================
// ДОБАВЛЕНИЕ / ИЗМЕНЕНИЕ (только тут нужно имя)
// ==========================================
if ($name === '') exit('Название обязательно');

if ($type === 'group') {
    if ($oper === 'add') {
        if ($sort === 0) {
            $sort = (int)$mysqli->query("SELECT IFNULL(MAX(sort),0)+10 AS n FROM acl_menu_groups")->fetch_assoc()['n'];
        }
        $stmt = $mysqli->prepare("INSERT INTO acl_menu_groups (title, sort) VALUES (?, ?)");
        $stmt->bind_param('si', $name, $sort);
    } else {
        $stmt = $mysqli->prepare("UPDATE acl_menu_groups SET title = ?, sort = ? WHERE id = ?");
        $stmt->bind_param('sii', $name, $sort, $id);
    }
}

if ($type === 'item') {
    $code     = trim($_POST['code'] ?? '');
    $group_id = (int)($_POST['group_id'] ?? 0);

    if (!preg_match('/^[a-z0-9_]+$/', $code)) exit('Код: только a-z, 0-9, _');

    if ($oper === 'add') {
        if ($group_id <= 0) exit('Группа не выбрана');
        
        $stmt = $mysqli->prepare("SELECT id FROM acl_menu_groups WHERE id = ?");
        $stmt->bind_param('i', $group_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) { $stmt->close(); exit('Группа не найдена'); }
        $stmt->close();

        if ($sort === 0) {
            $stmt = $mysqli->prepare("SELECT IFNULL(MAX(sort),0)+10 AS n FROM acl_resources WHERE group_id = ?");
            $stmt->bind_param('i', $group_id);
            $stmt->execute();
            $sort = (int)$stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();
        }

        $stmt = $mysqli->prepare("INSERT INTO acl_resources (code, name, group_id, sort) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssii', $code, $name, $group_id, $sort);
    } else {
        $stmt = $mysqli->prepare("UPDATE acl_resources SET code = ?, name = ?, sort = ? WHERE id = ?");
        $stmt->bind_param('ssii', $code, $name, $sort, $id);
    }
}

if (isset($stmt)) {
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
}
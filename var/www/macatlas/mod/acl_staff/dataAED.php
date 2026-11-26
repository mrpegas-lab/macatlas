<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }
if (!$oper) exit;

$id        = (int)($_POST['id']        ?? 0);
$login     = trim($_POST['login']      ?? '');
$password  = $_POST['password']        ?? '';
$fio       = trim($_POST['fio']        ?? '');
$role_id   = (int)($_POST['role_id']   ?? 0);
$auth_type = $_POST['auth_type']       ?? 'local';
$is_active = (int)($_POST['is_active'] ?? 1) ? 1 : 0;

// валидация полей — только для add/edit (del шлёт только id)
if ($oper === 'add' || $oper === 'edit') {
    if (!in_array($auth_type, ['local', 'ldap'], true)) exit('Некорректный тип авторизации');
    if ($login === '') exit('Логин обязателен');
    if ($fio   === '') exit('ФИО обязательно');
    if ($role_id <= 0) exit('Роль не выбрана');

    // проверка роли
    $stmt = $mysqli->prepare("SELECT id FROM acl_roles WHERE id = ?");
    $stmt->bind_param('i', $role_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) { $stmt->close(); exit('Роль не найдена'); }
    $stmt->close();
}

// ---------- ДОБАВИТЬ ----------
if ($oper === 'add') {
    if ($auth_type === 'local' && $password === '') {
        exit('Пароль обязателен для локальной учётной записи');
    }

    // уникальность логина
    $stmt = $mysqli->prepare("SELECT id FROM acl_staff WHERE login = ?");
    $stmt->bind_param('s', $login);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) { $stmt->close(); exit('Логин уже занят'); }
    $stmt->close();

    // для LDAP пароль не храним
    $hash = ($auth_type === 'local' && $password !== '')
        ? password_hash($password, PASSWORD_DEFAULT)
        : null;

    $stmt = $mysqli->prepare(
        "INSERT INTO acl_staff
            (login, password, fio, role_id, auth_type, is_active, is_protected)
         VALUES (?, ?, ?, ?, ?, ?, 0)"
    );
    $stmt->bind_param('sssisi', $login, $hash, $fio, $role_id, $auth_type, $is_active);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}

// ---------- ИЗМЕНИТЬ ----------
if ($oper === 'edit') {
    if ($id <= 0) exit('Некорректный ID');

    // читаем текущую запись
    $stmt = $mysqli->prepare(
        "SELECT id, login, auth_type, is_protected FROM acl_staff WHERE id = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$current) exit('Запись не найдена');

    // защищённая учётка: логин / auth_type / активность менять нельзя
    if ((int)$current['is_protected'] === 1) {
        if ($login !== $current['login']) {
            exit('Нельзя изменить логин защищённой учётной записи');
        }
        if ($is_active !== 1) {
            exit('Нельзя деактивировать защищённую учётную запись');
        }
        $auth_type = $current['auth_type'];
    }

    // уникальность логина (исключая себя)
    $stmt = $mysqli->prepare("SELECT id FROM acl_staff WHERE login = ? AND id <> ?");
    $stmt->bind_param('si', $login, $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) { $stmt->close(); exit('Логин уже занят'); }
    $stmt->close();

    // новый пароль — только для local и только если введён
    $newHash = null;
    if ($auth_type === 'local' && $password !== '') {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
    }

    // переключили local → ldap: стираем пароль
    $wipePassword = ($auth_type === 'ldap' && $current['auth_type'] === 'local');

    if ($newHash !== null) {
        $stmt = $mysqli->prepare(
            "UPDATE acl_staff
                SET login=?, password=?, fio=?, role_id=?, auth_type=?, is_active=?
              WHERE id=?"
        );
        $stmt->bind_param('sssisii', $login, $newHash, $fio, $role_id, $auth_type, $is_active, $id);
    } elseif ($wipePassword) {
        $stmt = $mysqli->prepare(
            "UPDATE acl_staff
                SET login=?, password=NULL, fio=?, role_id=?, auth_type=?, is_active=?
              WHERE id=?"
        );
        $stmt->bind_param('ssisii', $login, $fio, $role_id, $auth_type, $is_active, $id);
    } else {
        $stmt = $mysqli->prepare(
            "UPDATE acl_staff
                SET login=?, fio=?, role_id=?, auth_type=?, is_active=?
              WHERE id=?"
        );
        $stmt->bind_param('ssisii', $login, $fio, $role_id, $auth_type, $is_active, $id);
    }
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}

// ---------- УДАЛИТЬ ----------
if ($oper === 'del') {
    if ($id <= 0) exit('Некорректный ID');

    if ($id === (int)($_SESSION['auth'] ?? 0)) {
        exit('Нельзя удалить свою учётную запись');
    }

    $stmt = $mysqli->prepare("SELECT is_protected FROM acl_staff WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$p) exit('Запись не найдена');
    if ((int)$p['is_protected'] === 1) exit('Нельзя удалить защищённую учётную запись');

    $stmt = $mysqli->prepare("DELETE FROM acl_staff WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}
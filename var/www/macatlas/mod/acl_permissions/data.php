<?php
include_once("../../loadinc.php");

header('Content-Type: application/json; charset=utf-8');

// 1. Роли
$roles = [];
$res = $mysqli->query("SELECT id, code, name FROM acl_roles ORDER BY name");
while ($r = $res->fetch_assoc()) {
    $roles[] = ['id' => (int)$r['id'], 'name' => $r['name']];
}

// 2. Группы меню
$groups = [];
$res = $mysqli->query("SELECT id, title FROM acl_menu_groups ORDER BY sort, title");
while ($r = $res->fetch_assoc()) {
    $groups[] = ['id' => (int)$r['id'], 'title' => $r['title']];
}

// 3. Ресурсы (с group_id), сортировка: по группам (как в меню), внутри — по sort
$resources = [];
$res = $mysqli->query("SELECT r.id, r.code, r.name, r.group_id
                       FROM acl_resources r
                       LEFT JOIN acl_menu_groups g ON g.id = r.group_id
                       ORDER BY g.sort, g.title, r.sort, r.name");
while ($r = $res->fetch_assoc()) {
    $resources[] = [
        'id' => (int)$r['id'],
        'code' => $r['code'],
        'name' => $r['name'],
        'group_id' => (int)$r['group_id']
    ];
}

// 4. Права
$perms = [];
$res = $mysqli->query("SELECT role_id, resource_id, can_view, can_add, can_edit, can_del, can_exec FROM acl_permissions");
while ($r = $res->fetch_assoc()) {
    $rid = (int)$r['role_id'];
    $sid = (int)$r['resource_id'];

    if (!isset($perms[$rid])) $perms[$rid] = [];

    $perms[$rid][$sid] = [
        'can_view' => (int)$r['can_view'],
        'can_add'  => (int)$r['can_add'],
        'can_edit' => (int)$r['can_edit'],
        'can_del'  => (int)$r['can_del'],
        'can_exec' => (int)$r['can_exec'],
    ];
}

echo json_encode([
    'roles'     => $roles,
    'groups'    => $groups,
    'resources' => $resources,
    'perms'     => $perms
], JSON_UNESCAPED_UNICODE);
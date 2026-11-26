<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

$id = (int)($_POST['id'] ?? 0);

// Запись события в ipam_history (user_id — из сессии)
function ipam_hist($mysqli, $addr_id, $action, $old_st, $new_st, $comment = '') {
    static $uid = null;
    if ($uid === null) $uid = (int)($_SESSION['auth'] ?? 0);
    $uidSql = $uid > 0 ? $uid : 'NULL';
    $stmt = $mysqli->prepare(
        "INSERT INTO ipam_history (address_id, action, old_status, new_status, user_id, comment)
         VALUES (?, ?, ?, ?, $uidSql, ?)"
    );
    $stmt->bind_param('issss', $addr_id, $action, $old_st, $new_st, $comment);
    $stmt->execute();
    $stmt->close();
}

// Статус -> действие в истории
function ipam_hist_action($status) {
    switch ($status) {
        case 'reserved': return 'reserve';
        case 'assigned': return 'assign';
        case 'offline':
        case 'free':
        case 'released': return 'release';
        case 'conflict': return 'conflict';
        default:         return 'change';
    }
}

if ($oper === 'del') {
    // история адреса удаляется каскадом вместе с записью (FK ON DELETE CASCADE)
    $stmt = $mysqli->prepare("DELETE FROM ipam_addresses WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}

// === add / edit ===
$subnet_id = (int)($_POST['subnet_id'] ?? 0);
$ip_str    = trim($_POST['ip'] ?? '');
$status    = $_POST['status'] ?? 'assigned';
$hostname  = trim($_POST['hostname'] ?? '');
$mac       = trim($_POST['mac'] ?? '');
$desc      = $_POST['description'] ?? '';

if ($subnet_id <= 0) {
    http_response_code(400); exit('Не выбрана подсеть');
}

// Валидация IP
if (!filter_var($ip_str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(400); exit('Некорректный IP-адрес');
}

$ip_int = (int)sprintf('%u', ip2long($ip_str));

// Проверка, что IP входит в выбранную подсеть (опционально, но полезно)
$stmt = $mysqli->prepare("SELECT network, prefix_len FROM ipam_subnets WHERE id = ?");
$stmt->bind_param('i', $subnet_id);
$stmt->execute();
$subnet = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($subnet) {
    $netInt = (int)$subnet['network'];
    $prefix = (int)$subnet['prefix_len'];
    $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
    if (($ip_int & $mask) !== ($netInt & $mask)) {
        http_response_code(400); exit('IP-адрес не принадлежит выбранной подсети');
    }
}

// Для edit — старая запись (нужна для истории)
$old = null;
if ($oper === 'edit') {
    $q = $mysqli->prepare("SELECT status, hostname, mac FROM ipam_addresses WHERE id = ?");
    $q->bind_param('i', $id);
    $q->execute();
    $old = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$old) { http_response_code(404); exit('Адрес не найден'); }
}

if ($oper === 'add') {
    $sql = "INSERT INTO ipam_addresses SET subnet_id=?, ip=?, status=?, hostname=?, mac=?, description=?, assigned_at=NOW()";
    $types = 'iissss';
    $vals  = [$subnet_id, $ip_int, $status, $hostname, $mac, $desc];
} elseif ($oper === 'edit') {
    $sql = "UPDATE ipam_addresses SET subnet_id=?, ip=?, status=?, hostname=?, mac=?, description=? WHERE id=?";
    $types = 'iissssi';
    $vals  = [$subnet_id, $ip_int, $status, $hostname, $mac, $desc, $id];
} else {
    exit('Неизвестная операция');
}

$stmt = $mysqli->prepare($sql);
if (!$stmt) exit('SQL: ' . $mysqli->error);
$stmt->bind_param($types, ...$vals);
$stmt->execute();

if ($stmt->error) {
    if (strpos($stmt->error, 'uq_ipam_subnet_ip') !== false) {
        http_response_code(409); exit('Этот IP уже занят в данной подсети');
    }
    exit('SQL: ' . $stmt->error);
}
$stmt->close();

// === История изменений ===
if ($oper === 'add') {
    $addrId = $mysqli->insert_id;
    $cm = [];
    if ($hostname !== '') $cm[] = "host: $hostname";
    if ($mac !== '')      $cm[] = "MAC: $mac";
    ipam_hist($mysqli, $addrId, ipam_hist_action($status), null, $status, implode('; ', $cm));
} elseif ($oper === 'edit' && $old) {
    $changes = [];
    if (($old['mac'] ?? '') !== $mac) {
        $changes[] = 'MAC: ' . ($old['mac'] !== '' ? $old['mac'] : '—') . ' → ' . ($mac !== '' ? $mac : '—');
    }
    if (($old['hostname'] ?? '') !== $hostname) {
        $changes[] = 'host: ' . ($old['hostname'] !== '' ? $old['hostname'] : '—') . ' → ' . ($hostname !== '' ? $hostname : '—');
    }
    if ($old['status'] !== $status) {
        ipam_hist($mysqli, $id, ipam_hist_action($status), $old['status'], $status, implode('; ', $changes));
    } elseif ($changes) {
        ipam_hist($mysqli, $id, 'change', $status, $status, implode('; ', $changes));
    }
}

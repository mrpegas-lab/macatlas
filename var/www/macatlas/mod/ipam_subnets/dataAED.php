<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'ipam_subnets';
$ID_FIELD = 'id';

$id = $_POST['id'] ?? '';

if ($oper) {
    if ($oper === 'del') {
        // Удаление подсети запрещено, если на ней есть адреса
        $stmt = $mysqli->prepare(
            "SELECT COUNT(*) AS c,
                    GROUP_CONCAT(INET_NTOA(ip) ORDER BY ip SEPARATOR ', ') AS names
               FROM ipam_addresses WHERE subnet_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)$row['c'] > 0) {
            $names = ($row['names'] ?? '') !== '' ? explode(', ', $row['names']) : [];
            $s = implode(', ', array_slice($names, 0, 5));
            if (count($names) > 5) $s .= ', …';
            http_response_code(409);
            exit('Нельзя удалить подсеть: на ней есть адреса (' . (int)$row['c'] . '): '
                 . $s . '. Сначала освободите их.');
        }

        $stmt = $mysqli->prepare("DELETE FROM ipam_subnets WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->error) exit('SQL: ' . $stmt->error);
        $stmt->close();
        exit;
    }

    // === add / edit ===
    $vlan_ref_id = (int)($_POST['vlan_ref_id'] ?? 0);
    $network_str = trim($_POST['network'] ?? '');
    $prefix_len  = (int)($_POST['prefix_len'] ?? 0);
    $gateway_str = trim($_POST['gateway'] ?? '');
    $description = $_POST['description'] ?? '';

    // --- Валидация VLAN ---
    if ($vlan_ref_id <= 0) {
        http_response_code(400);
        exit('Не выбран VLAN');
    }
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM ipam_vlans WHERE id = ?");
    $stmt->bind_param('i', $vlan_ref_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['c'] === 0) {
        http_response_code(400);
        exit('Указанный VLAN не существует');
    }

    // --- Валидация network ---
    if (!filter_var($network_str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        http_response_code(400);
        exit('Некорректный IP сети');
    }
    if ($prefix_len < 0 || $prefix_len > 32) {
        http_response_code(400);
        exit('Префикс должен быть от 0 до 32');
    }

    $network_int = (int)sprintf('%u', ip2long($network_str));
    $mask        = $prefix_len === 0 ? 0 : (-1 << (32 - $prefix_len));
    $net_calc    = $network_int & $mask;

    if ($network_int !== $net_calc) {
        http_response_code(400);
        exit('Указанный адрес не является сетевым для /' . $prefix_len . '. Ожидается ' . long2ip($net_calc));
    }

    // --- Валидация gateway ---
    $gateway_int = null;
    if ($gateway_str !== '') {
        if (!filter_var($gateway_str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            http_response_code(400);
            exit('Некорректный IP шлюза');
        }
        $gateway_int = (int)sprintf('%u', ip2long($gateway_str));

        $broadcast = $net_calc | (~$mask);
        if ($gateway_int <= $net_calc || $gateway_int >= $broadcast) {
            http_response_code(400);
            exit('Шлюз должен быть внутри подсети (не сетевой и не broadcast)');
        }
    }

    // --- Сборка SQL ---
    if ($oper === 'add') {
        $sql = "INSERT INTO $TABLE SET
                    vlan_ref_id = ?,
                    network = ?,
                    prefix_len = ?,
                    gateway = ?,
                    description = ?";
        $types  = 'iiiss';
        $values = [$vlan_ref_id, $network_int, $prefix_len, $gateway_int, $description];
    }
    elseif ($oper === 'edit') {
        $sql = "UPDATE $TABLE SET
                    vlan_ref_id = ?,
                    network = ?,
                    prefix_len = ?,
                    gateway = ?,
                    description = ?
                WHERE $ID_FIELD = ?";
        $types  = 'iiissi';
        $values = [$vlan_ref_id, $network_int, $prefix_len, $gateway_int, $description, $id];
    }
    else {
        exit('Неизвестная операция');
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) exit('SQL: ' . $mysqli->error);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    if ($stmt->error) {
        if (strpos($stmt->error, 'uq_ipam_net_prefix') !== false) {
            http_response_code(409);
            exit('Такая подсеть уже существует');
        }
        exit('SQL: ' . $stmt->error);
    }
    $stmt->close();
}
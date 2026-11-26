<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'ipam_vlans';
$ID_FIELD = 'id';
$FIELDS = [
    'hw_id'       => 'i',
    'vlan_id'     => 'i',
    'name'        => 's',
    'description' => 's',
];

$id = $_POST['id'] ?? '';

if ($oper) {
    if ($oper === 'del') {
        // Удаление VLAN запрещено, если на нём есть подсети (FK RESTRICT).
        // Проверяем заранее, чтобы вернуть человеческое сообщение.
        $stmt = $mysqli->prepare(
            "SELECT COUNT(*) AS c,
                    GROUP_CONCAT(CONCAT(INET_NTOA(network), '/', prefix_len)
                                 ORDER BY network SEPARATOR ', ') AS names
               FROM ipam_subnets WHERE vlan_ref_id = ?"
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
            exit('Нельзя удалить VLAN: на нём есть подсети (' . (int)$row['c'] . '): '
                 . $s . '. Сначала удалите их.');
        }

        $stmt = $mysqli->prepare("DELETE FROM ipam_vlans WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->error) exit('SQL: ' . $stmt->error);
        $stmt->close();
        exit;
    }

    // === add / edit ===
    $values    = [];
    $set_parts = [];
    $types     = '';

    foreach ($FIELDS as $field => $type) {
        $value = $_POST[$field] ?? '';

        // Пустые integer → NULL
        if ($value === '' && $type === 'i') {
            $value = null;
        }

        // Обязательные числовые — валидация
        if ($field === 'hw_id' && ($value === null || (int)$value <= 0)) {
            http_response_code(400);
            exit('Не выбрано устройство');
        }
        if ($field === 'vlan_id') {
            $v = (int)$value;
            if ($v < 1 || $v > 4094) {
                http_response_code(400);
                exit('VLAN ID должен быть в диапазоне 1..4094');
            }
            $value = $v;
        }

        $values[]    = $value;
        $set_parts[] = "$field = ?";
        $types      .= $type;
    }

    $set_clause = implode(', ', $set_parts);

    if ($oper === 'add') {
        $sql = "INSERT INTO $TABLE SET $set_clause";
    }
    elseif ($oper === 'edit') {
        $sql      = "UPDATE $TABLE SET $set_clause WHERE $ID_FIELD = ?";
        $values[] = $id;
        $types   .= 'i';
    }
    else {
        exit('Неизвестная операция');
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) exit('SQL: ' . $mysqli->error);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    if ($stmt->error) {
        // Ловим UNIQUE (hw_id, vlan_id) — дубль номера на устройстве
        if (strpos($stmt->error, 'uq_ipam_vlan_hw_num') !== false) {
            http_response_code(409);
            exit('На этом устройстве уже есть VLAN с таким номером');
        }
        exit('SQL: ' . $stmt->error);
    }
    $stmt->close();
}
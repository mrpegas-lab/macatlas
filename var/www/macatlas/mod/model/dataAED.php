<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'model';
$ID_FIELD = 'id';
$FIELDS   = [
    'model_type_id' => 'i',   // ← добавлено
    'id_brand'   => 'i',
    'model_name' => 's',
    'ports'      => 'i',
    'poe_ports'  => 'i'
];
// ==============

$id = $_POST['id'] ?? '';

if ($oper) {
    // === DEL: запрет, если к модели привязано оборудование ===
    if ($oper === 'del') {
        $stmt = $mysqli->prepare(
            "SELECT COUNT(*) AS c,
                    GROUP_CONCAT(switch_name ORDER BY switch_name SEPARATOR ', ') AS names
               FROM hardware WHERE id_model = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int)$row['c'] > 0) {
            $names = ($row['names'] ?? '') !== '' ? explode(', ', $row['names']) : [];
            $s = implode(', ', array_slice($names, 0, 5));
            if (count($names) > 5) $s .= ', …';
            http_response_code(400);
            exit('Нельзя удалить модель: к ней привязано устройств (' . (int)$row['c'] . '): '
                 . $s . '. Сначала удалите/перепривяжите оборудование.');
        }
    }

    $values    = [];
    $set_parts = [];
    $types     = '';

    foreach ($FIELDS as $field => $type) {
        $value = $_POST[$field] ?? '';
        if ($value === '' && ($type === 'i' || $type === 'd')) {
            $value = null;
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
    elseif ($oper === 'del') {
        $sql    = "DELETE FROM $TABLE WHERE $ID_FIELD = ?";
        $values = [$id];
        $types  = 'i';
    }
    else {
        exit('Неизвестная операция');
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        exit('SQL: ' . $mysqli->error);
    }
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    if ($stmt->error) {
        exit('SQL: ' . $stmt->error);
    }
    $stmt->close();
}
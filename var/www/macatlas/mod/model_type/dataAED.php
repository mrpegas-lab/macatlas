<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'model_type';
$ID_FIELD = 'id';
$FIELDS = [
    'name'        => 's',
    'description' => 's',
];

$id = $_POST['id'] ?? '';

if ($oper) {
    if ($oper === 'del') {
        // Проверяем, есть ли модели с этим типом (hardware ссылается на тип через model)
        $stmt = $mysqli->prepare(
            "SELECT COUNT(*) AS c,
                    GROUP_CONCAT(model_name ORDER BY model_name SEPARATOR ', ') AS names
               FROM model WHERE model_type_id = ?"
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
            exit('Нельзя удалить тип: к нему привязано моделей (' . (int)$row['c'] . '): '
                 . $s . '. Сначала смените тип у моделей.');
        }

        $stmt = $mysqli->prepare("DELETE FROM model_type WHERE id = ?");
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

        // Обязательное поле name
        if ($field === 'name' && trim($value) === '') {
            http_response_code(400);
            exit('Название не может быть пустым');
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
        // UNIQUE (name) — дубль названия
        if (strpos($stmt->error, 'name') !== false && strpos($stmt->error, 'Duplicate') !== false) {
            http_response_code(409);
            exit('Тип с таким названием уже существует');
        }
        exit('SQL: ' . $stmt->error);
    }
    $stmt->close();
}
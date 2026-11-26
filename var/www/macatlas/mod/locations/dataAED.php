<?php
include_once("../../loadinc.php");

header('Content-Type: text/plain; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'locations';
$ID_FIELD = 'id';
$FIELDS = [
    'name'          => 's',
    'code'          => 's',
    'new_name'      => 's',
    'address'       => 's',
    'gps'           => 's',
    'tz_offset_msk' => 'i',   // NOT NULL DEFAULT 0
    'work_schedule' => 's',
    'type_id'       => 'i',   // nullable
    'comment'       => 's',
];

$id = (int)($_POST['id'] ?? 0);

if (!$oper) exit('');
if ($oper === 'edit' && $id <= 0) { http_response_code(400); exit('Не передан ID локации'); }

// ================= DEL =================
if ($oper === 'del') {
    if ($id <= 0) { http_response_code(400); exit('Не передан ID локации'); }

    // Проверим, что локация существует
    $chk = $mysqli->prepare("SELECT id FROM $TABLE WHERE $ID_FIELD = ?");
    if (!$chk) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $chk->bind_param('i', $id);
    $chk->execute();
    $exists = $chk->get_result()->fetch_row();
    $chk->close();
    if (!$exists) { http_response_code(404); exit('Локация не найдена'); }

    // === Проверка привязок: при любой — удаление запрещено ===
    // (длинные списки имён не режем лимитом GROUP_CONCAT по умолчанию)
    $mysqli->query("SET SESSION group_concat_max_len = 100000");

    $usage = [];

    // Формат строки: "Название (N): имя1, имя2, …" — не более 5 имён,
    // чтобы при сотнях устройств сообщение не расползалось
    $mkUsage = function ($label, $row) {
        $names = ($row['names'] ?? '') !== '' ? explode(', ', $row['names']) : [];
        $shown = array_slice($names, 0, 5);
        $s = implode(', ', $shown);
        if (count($names) > 5) $s .= ', …';
        return $label . ' (' . (int)$row['c'] . '): ' . $s;
    };

    // 1. Оборудование (hardware)
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) AS c,
                GROUP_CONCAT(switch_name ORDER BY switch_name SEPARATOR ', ') AS names
           FROM hardware WHERE locations_id = ?"
    );
    if (!$stmt) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['c'] > 0) {
        $usage[] = $mkUsage('Оборудование', $row);
    }

    // 2. Роутеры объектов (mt_sites) — своего имени нет, показываем lan/ip1
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) AS c,
                GROUP_CONCAT(COALESCE(NULLIF(lan, ''), ip1, CAST(id AS CHAR)) SEPARATOR ', ') AS names
           FROM mt_sites WHERE locations_id = ?"
    );
    if (!$stmt) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['c'] > 0) {
        $usage[] = $mkUsage('Роутеры объектов', $row);
    }

    // 3. Комнаты (locations_rooms)
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) AS c,
                GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS names
           FROM locations_rooms WHERE locations_id = ?"
    );
    if (!$stmt) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['c'] > 0) {
        $usage[] = $mkUsage('Комнаты', $row);
    }

    if ($usage) {
        http_response_code(400);
        exit('Удаление запрещено, локация используется: ' . implode(' · ', $usage)
             . '. Сначала отвяжите/удалите перечисленное, потом удаляйте локацию.');
    }

    // Привязок нет: статистику mt_devices чистим молча, локацию удаляем
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("DELETE FROM mt_devices WHERE locations_id = ?");
        if (!$stmt) throw new Exception('SQL: ' . $mysqli->error);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);
        $stmt->close();

        $stmt = $mysqli->prepare("DELETE FROM $TABLE WHERE $ID_FIELD = ?");
        if (!$stmt) throw new Exception('SQL: ' . $mysqli->error);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);
        $stmt->close();

        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        http_response_code(400);
        exit($e->getMessage());
    }

    exit('');
}

// ================= ВАЛИДАЦИЯ =================
if (trim($_POST['name'] ?? '') === '') {
    http_response_code(400);
    exit('Название обязательно');
}

// type_id: если передан — проверим, что существует (FK нет, но логика важна)
$type_id_raw = $_POST['type_id'] ?? '';
if ($type_id_raw !== '') {
    $type_id_int = (int)$type_id_raw;
    $chkType = $mysqli->prepare("SELECT id FROM locations_type WHERE id = ?");
    if (!$chkType) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $chkType->bind_param('i', $type_id_int);
    $chkType->execute();
    $typeExists = $chkType->get_result()->fetch_row();
    $chkType->close();
    if (!$typeExists) {
        http_response_code(400);
        exit('Указанный тип локации не существует');
    }
}

// ================= ADD / EDIT =================
$values    = [];
$set_parts = [];
$types     = '';

foreach ($FIELDS as $field => $type) {
    $value = $_POST[$field] ?? '';

    // status — поле выведено из использования, в таблице остаётся как legacy

    // tz_offset_msk — NOT NULL DEFAULT 0, пустое → 0
    if ($field === 'tz_offset_msk') {
        $value = ($value === '') ? 0 : (int)$value;
    }

    // type_id — nullable integer
    if ($field === 'type_id') {
        $value = ($value === '') ? null : (int)$value;
    }

    // Прочие пустые integer → NULL
    if ($value === '' && $type === 'i' && $field !== 'tz_offset_msk') {
        $value = null;
    }

    $values[]    = $value;
    $set_parts[] = "$field = ?";
    $types      .= $type;
}

$set_clause = implode(', ', $set_parts);

if ($oper === 'add') {
    $sql = "INSERT INTO $TABLE SET $set_clause";
} elseif ($oper === 'edit') {
    $sql      = "UPDATE $TABLE SET $set_clause WHERE $ID_FIELD = ?";
    $values[] = $id;
    $types   .= 'i';
} else {
    http_response_code(400);
    exit('Неизвестная операция');
}

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('SQL: ' . $mysqli->error);
}
$stmt->bind_param($types, ...$values);
$stmt->execute();

if ($stmt->errno === 1062) {
    http_response_code(400);
    exit('Такая запись уже существует (дубликат уникального поля)');
}
if ($stmt->errno === 1452) {
    http_response_code(400);
    exit('Указанный тип локации не существует');
}
if ($stmt->error) {
    http_response_code(500);
    exit('SQL: ' . $stmt->error);
}

$stmt->close();
exit('');
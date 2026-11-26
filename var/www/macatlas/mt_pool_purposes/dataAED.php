<?php
include_once("../../loadinc.php");

header('Content-Type: text/plain; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'mt_pool_purposes';
$ID_FIELD = 'id';
$FIELDS   = [
    'code'         => 's',
    'name'         => 's',
    'kind'         => 's',
    'default_bits' => 'i',
    'icon'         => 's'
];

$id = (int)($_POST['id'] ?? 0);

if ($oper === 'del' && $id <= 0) {
    http_response_code(400);
    exit('Не передан ID назначения');
}
if ($oper === 'edit' && $id <= 0) {
    http_response_code(400);
    exit('Не передан ID назначения');
}

if (!$oper) {
    exit('');
}

// ================= DEL =================
if ($oper === 'del') {

    // 1. Получаем code удаляемого назначения
    $stmtCode = $mysqli->prepare("SELECT code FROM $TABLE WHERE $ID_FIELD = ?");
    if (!$stmtCode) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $stmtCode->bind_param("i", $id);
    $stmtCode->execute();
    $resCode = $stmtCode->get_result()->fetch_assoc();
    $stmtCode->close();

    if (!$resCode) {
        http_response_code(404);
        exit('Назначение не найдено');
    }

    $code = $resCode['code'];

    // 2. Проверяем, есть ли пулы с этим purpose
    $checkRanges = $mysqli->prepare("SELECT name FROM mt_pool_ranges WHERE purpose = ? ORDER BY name");
    if (!$checkRanges) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $checkRanges->bind_param("s", $code);
    $checkRanges->execute();
    $rangesRes = $checkRanges->get_result();
    $rangeNames = [];
    while ($r = $rangesRes->fetch_assoc()) {
        $rangeNames[] = $r['name'];
    }
    $checkRanges->close();

    if (count($rangeNames) > 0) {
        http_response_code(400);
        $list = implode(', ', $rangeNames);
        exit("Нельзя удалить назначение: к нему привязаны пулы: $list. Сначала удалите или переназначьте их.");
    }

    // 3. Удаляем
    $stmt = $mysqli->prepare("DELETE FROM $TABLE WHERE $ID_FIELD = ?");
    if (!$stmt) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }
    $stmt->close();

    exit('');
}

// ================= ВАЛИДАЦИЯ ОБЩИХ ПОЛЕЙ =================
$kind = $_POST['kind'] ?? '';
if (!in_array($kind, ['subnet', 'host'], true)) {
    http_response_code(400);
    exit('Неверный тип (kind): допустимо subnet или host');
}

$bits = (int)($_POST['default_bits'] ?? 0);
if ($bits < 1 || $bits > 32) {
    http_response_code(400);
    exit('Маска по умолчанию должна быть от 1 до 32');
}

$codeVal = trim($_POST['code'] ?? '');
if ($codeVal === '') {
    http_response_code(400);
    exit('Код обязателен');
}

// ================= ADD / EDIT =================
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
} else { // edit
    $sql      = "UPDATE $TABLE SET $set_clause WHERE $ID_FIELD = ?";
    $values[] = $id;
    $types   .= 'i';
}

$stmt = $mysqli->prepare($sql);
if (!$stmt) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
$stmt->bind_param($types, ...$values);
$stmt->execute();

if ($stmt->errno === 1062) {
    http_response_code(400);
    exit('Код уже используется, выберите другой');
}
if ($stmt->errno === 1451) {
    http_response_code(400);
    exit('Нельзя изменить код: он используется в пулах');
}
if ($stmt->error) {
    http_response_code(500);
    exit('SQL: ' . $stmt->error);
}

$stmt->close();
exit('');
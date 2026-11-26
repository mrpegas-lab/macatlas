<?php
include_once("../../loadinc.php");

header('Content-Type: text/plain; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'mt_pool_ranges';
$ID_FIELD = 'id';
$FIELDS   = [
    'group_id'  => 'i',
    'purpose'   => 's',
    'name'      => 's',
    'cidr'      => 's',
    'kind'      => 's',
    'step_bits' => 'i',
    'priority'  => 'i',
    'enabled'   => 'i'
];

$id = (int)($_POST['id'] ?? 0);

if ($oper === 'del' && $id <= 0)  { http_response_code(400); exit('Не передан ID пула'); }
if ($oper === 'edit' && $id <= 0) { http_response_code(400); exit('Не передан ID пула'); }
if (!$oper) exit('');

// ================= DEL =================
if ($oper === 'del') {

    // 1. Проверяем выданные адреса
    $checkAllocs = $mysqli->prepare("
        SELECT l.code, l.new_name
        FROM mt_pool_allocations a
        JOIN mt_sites ms ON ms.id = a.site_id
        JOIN locations l ON l.id = ms.locations_id
        WHERE a.pool_id = ?
        LIMIT 5
    ");
    if (!$checkAllocs) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $checkAllocs->bind_param("i", $id);
    $checkAllocs->execute();
    $allocsRes = $checkAllocs->get_result();
    $siteNames = [];
    while ($a = $allocsRes->fetch_assoc()) {
        $siteNames[] = $a['code'] . ' (' . $a['new_name'] . ')';
    }
    $checkAllocs->close();

    // 2. Общее количество
    $countAllocs = $mysqli->prepare("SELECT COUNT(*) FROM mt_pool_allocations WHERE pool_id = ?");
    if (!$countAllocs) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $countAllocs->bind_param("i", $id);
    $countAllocs->execute();
    $row = $countAllocs->get_result()->fetch_row();
    $totalAllocs = $row ? (int)$row[0] : 0;
    $countAllocs->close();

    if ($totalAllocs > 0) {
        http_response_code(400);
        $list = implode(', ', $siteNames);
        if ($totalAllocs > 5) {
            $list .= ' и ещё ' . ($totalAllocs - 5) . ' объектов';
        }
        exit("Нельзя удалить пул: из него уже выданы адреса объектам: $list. Сначала освободите адреса или переназначьте их.");
    }

    // 3. Удаляем
    $stmt = $mysqli->prepare("DELETE FROM $TABLE WHERE $ID_FIELD = ?");
    if (!$stmt) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    if ($stmt->errno === 1451) {
        http_response_code(400);
        exit('Нельзя удалить пул: на него ссылаются выданные адреса');
    }
    if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }
    $stmt->close();

    exit('');
}

// ================= ВАЛИДАЦИЯ =================
$kind = $_POST['kind'] ?? '';
if (!in_array($kind, ['subnet', 'host'], true)) {
    http_response_code(400);
    exit('Неверный тип (kind): допустимо subnet или host');
}

$purpose = trim($_POST['purpose'] ?? '');
if ($purpose === '') {
    http_response_code(400);
    exit('Назначение обязательно');
}

$name = trim($_POST['name'] ?? '');
if ($name === '') {
    http_response_code(400);
    exit('Имя пула обязательно');
}

$cidr = trim($_POST['cidr'] ?? '');
if (!preg_match('#^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})/(\d{1,2})$#', $cidr, $m)) {
    http_response_code(400);
    exit('CIDR должен быть в формате IP/маска, например 10.77.0.0/16');
}
$ip   = "$m[1].$m[2].$m[3].$m[4]";
$prefix = (int)$m[5];
if (ip2long($ip) === false || $prefix < 0 || $prefix > 32) {
    http_response_code(400);
    exit('Некорректный IP или маска в CIDR');
}

$step_bits = (int)($_POST['step_bits'] ?? 0);
if ($step_bits < 1 || $step_bits > 32) {
    http_response_code(400);
    exit('Шаг (step_bits) должен быть от 1 до 32');
}
if ($kind === 'subnet' && $step_bits < $prefix) {
    http_response_code(400);
    exit('Шаг (step_bits) не может быть меньше маски сети (prefix)');
}
if ($kind === 'host' && $step_bits !== 32) {
    http_response_code(400);
    exit('Для типа host шаг (step_bits) должен быть 32');
}

$group_id_raw = $_POST['group_id'] ?? '';
$group_id = ($group_id_raw === '') ? null : (int)$group_id_raw;

// ================= ADD / EDIT =================
$values    = [];
$set_parts = [];
$types     = '';

foreach ($FIELDS as $field => $type) {
    $value = $_POST[$field] ?? '';
    if ($field === 'group_id') $value = $group_id;
    if ($value === '' && ($type === 'i' || $type === 'd')) $value = null;
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
    exit('Имя пула уже используется, выберите другое');
}
if ($stmt->errno === 1452) {
    http_response_code(400);
    exit('Указанная группа или назначение не существуют');
}
if ($stmt->errno === 1451) {
    http_response_code(400);
    exit('Нельзя изменить запись: есть связанные данные');
}
if ($stmt->error) {
    http_response_code(500);
    exit('SQL: ' . $stmt->error);
}

$stmt->close();
exit('');
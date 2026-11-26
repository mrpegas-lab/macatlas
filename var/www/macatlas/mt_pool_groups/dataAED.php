<?php
include_once("../../loadinc.php");

header('Content-Type: text/plain; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'mt_pool_groups';
$ID_FIELD = 'id';
$FIELDS   = [
    'code'    => 's',
    'name'    => 's',
    'sort'    => 'i',
    'enabled' => 'i'
];

$id = (int)($_POST['id'] ?? 0);

if ($oper === 'del' && $id <= 0) {
    http_response_code(400);
    exit('Не передан ID группы');
}

if ($oper) {
    // ================= DEL =================
    if ($oper === 'del') {
        // 1. Проверяем пулы
        $checkPools = $mysqli->prepare("SELECT name FROM mt_pool_ranges WHERE group_id = ? ORDER BY name");
        if (!$checkPools) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
        $checkPools->bind_param("i", $id);
        $checkPools->execute();
        $poolsRes = $checkPools->get_result();
        $poolNames = [];
        while ($p = $poolsRes->fetch_assoc()) {
            $poolNames[] = $p['name'];
        }
        $checkPools->close();

        if (count($poolNames) > 0) {
            http_response_code(400);
            $list = implode(', ', $poolNames);
            exit("Нельзя удалить группу: к ней привязаны пулы: $list. Сначала удалите или переназначьте их.");
        }

        // 2. Проверяем объекты
        $checkSites = $mysqli->prepare("
            SELECT l.new_name
            FROM mt_sites ms
            JOIN locations l ON l.id = ms.locations_id
            WHERE ms.group_id = ?
            ORDER BY l.new_name
            LIMIT 5
        ");
        if (!$checkSites) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
        $checkSites->bind_param("i", $id);
        $checkSites->execute();
        $sitesRes = $checkSites->get_result();
        $siteNames = [];
        while ($s = $sitesRes->fetch_assoc()) {
            $siteNames[] = $s['new_name'] ?: 'Без названия';
        }
        $checkSites->close();

        // Общее количество объектов
        $countSites = $mysqli->prepare("SELECT COUNT(*) FROM mt_sites WHERE group_id = ?");
        if (!$countSites) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
        $countSites->bind_param("i", $id);
        $countSites->execute();
        $row = $countSites->get_result()->fetch_row();
        $totalSites = $row ? (int)$row[0] : 0;
        $countSites->close();

        if ($totalSites > 0) {
            http_response_code(400);
            $list = implode(', ', $siteNames);
            if ($totalSites > 5) {
                $list .= ' и ещё ' . ($totalSites - 5) . ' объектов';
            }
            exit("Нельзя удалить группу: к ней привязаны объекты: $list. Сначала переназначьте их на другую группу.");
        }

        // 3. Удаляем
        $sql = "DELETE FROM $TABLE WHERE $ID_FIELD = ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }
        $stmt->close();
        exit(''); // пустой ответ = успех (как в allocations)
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
    } elseif ($oper === 'edit') {
        if ($id <= 0) { http_response_code(400); exit('Не передан ID группы'); }
        $sql      = "UPDATE $TABLE SET $set_clause WHERE $ID_FIELD = ?";
        $values[] = $id;
        $types   .= 'i';
    } else {
        http_response_code(400);
        exit('Неизвестная операция');
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) { http_response_code(500); exit('SQL: ' . $mysqli->error); }
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }
    $stmt->close();
    exit('');
}
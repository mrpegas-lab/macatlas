<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

$oper = $_POST['oper'] ?? '';

// Разрешаем только удаление (освобождение)
if ($oper === 'del' && !$can_del) { http_response_code(403); exit('Нет прав на удаление'); }

$TABLE    = 'mt_pool_allocations';
$ID_FIELD = 'id';

if ($oper === 'del') {
    $id = $_POST['id'] ?? 0;
    if (!$id) exit('Не передан ID');
    
    $stmt = $mysqli->prepare("DELETE FROM $TABLE WHERE $ID_FIELD = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
} elseif ($oper === 'edit') {
    // На будущее, если понадобится ручная правка
    exit('Редактирование запрещено. Используйте пересоздание объекта.');
} elseif ($oper === 'add') {
    exit('Добавление запрещено. Адреса выдаются автоматически при создании объекта.');
}
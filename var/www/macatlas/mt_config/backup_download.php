<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


$locId = isset($_GET['locations_id']) ? (int)$_GET['locations_id'] : 0;
$file  = isset($_GET['file']) ? trim($_GET['file']) : '';

if (!$locId || !$file) {
    http_response_code(400);
    exit('Bad request');
}

// Ищем файл в БД — это надёжнее, чем собирать путь руками
// и защищает от попыток выйти за пределы папки через ../
$stmt = $mysqli->prepare("
    SELECT file_path, file_name
    FROM mt_backups
    WHERE locations_id = ? AND file_name = ?
    LIMIT 1
");
$stmt->bind_param("is", $locId, $file);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('File not found in database');
}

$path = $row['file_path'];

// Проверяем, что файл реально существует на диске
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found on disk');
}

// Отдаём файл
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($row['file_name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache, must-revalidate');

readfile($path);
exit;
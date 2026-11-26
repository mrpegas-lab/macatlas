<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$id) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "# Ошибка: Не указан ID салона";
    exit;
}

$template = @file_get_contents(__DIR__ . '/templates.tpl');
if ($template === false) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "# Ошибка: Не найден шаблон templates.tpl";
    exit;
}

// Вызываем единый движок
$result = mt_generate_config($mysqli, $id, $template);

header('Content-Type: text/plain; charset=utf-8');
echo $result;
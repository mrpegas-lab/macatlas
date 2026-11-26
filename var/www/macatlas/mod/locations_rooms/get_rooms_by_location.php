<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


$locations_id = (int)($_POST['locations_id'] ?? 0);

if ($locations_id > 0) {

    $stmt = $mysqli->prepare("SELECT id, name FROM locations_rooms WHERE locations_id = ? ORDER BY name");

    if (!$stmt) {
        // Покажет реальную причину вместо голой 500-й
        die('Ошибка подготовки запроса: ' . htmlspecialchars($mysqli->error));
    }

    $stmt->bind_param('i', $locations_id);
    $stmt->execute();
    $result = $stmt->get_result();

    echo "<option value=''>Выберите помещение</option>";
    while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
        echo "<option value='" . (int)$row['id'] . "'>" . htmlspecialchars($row['name']) . "</option>";
    }

} else {
    echo "<option value=''>Сначала выберите локацию</option>";
}
?>
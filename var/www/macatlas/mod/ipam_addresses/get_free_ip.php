<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


header('Content-Type: application/json; charset=utf-8');

$subnet_id = (int)($_POST['subnet_id'] ?? 0);
if ($subnet_id <= 0) {
    echo json_encode(['error' => 'Не выбрана подсеть']);
    exit;
}

// 1. Данные подсети
$stmt = $mysqli->prepare("SELECT network, prefix_len, gateway FROM ipam_subnets WHERE id = ?");
$stmt->bind_param('i', $subnet_id);
$stmt->execute();
$subnet = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subnet) {
    echo json_encode(['error' => 'Подсеть не найдена']);
    exit;
}

$prefix = (int)$subnet['prefix_len'];
if ($prefix >= 31) {
    echo json_encode(['error' => 'Для /31 и /32 автоподбор не поддерживается']);
    exit;
}

$netInt = (int)$subnet['network'];

// Вычисляем маску и broadcast через long2ip (более надежно)
$maskStr   = long2ip(-1 << (32 - $prefix));       // Например, 255.255.255.0 для /24
$netStr    = long2ip($netInt);                   // Сетевой адрес
$broadcast = ip2long($netStr) | (~ip2long($maskStr) & 0xFFFFFFFF); // Broadcast

$firstHost = $netInt + 1;
$lastHost  = $broadcast - 1;

// 2. Все занятые адреса в этой подсети
$stmt = $mysqli->prepare("SELECT ip FROM ipam_addresses WHERE subnet_id = ? ORDER BY ip");
$stmt->bind_param('i', $subnet_id);
$stmt->execute();
$res = $stmt->get_result();

$busy = [];
while ($row = $res->fetch_assoc()) {
    $busy[] = (int)$row['ip'];
}
$stmt->close();

// Добавляем шлюз в список занятых
if ($subnet['gateway']) {
    $busy[] = (int)$subnet['gateway'];
}
sort($busy);

// 3. Ищем первый свободный адрес (начиная с $firstHost)
$candidate = $firstHost;
foreach ($busy as $b) {
    if ($b > $candidate) {
        break; // Нашли дырку
    }
    if ($b === $candidate) {
        $candidate++; // Адрес занят, идем дальше
    }
}

if ($candidate > $lastHost) {
    echo json_encode(['error' => 'Нет свободных IP в этой подсети']);
} else {
    echo json_encode(['ip' => long2ip($candidate)]);
}
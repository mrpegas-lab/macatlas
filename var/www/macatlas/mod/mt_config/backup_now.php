<?php
// backup_now.php — разовый бэкап ОДНОГО объекта (mt_sites) из конфигуратора.
// Кладёт заявку в job_run_requests с params="--ip <lan>"; job_runner подхватит
// в течение минуты и запустит backup.py только для этого устройства.
include_once("../../loadinc.php");

header('Content-Type: text/plain; charset=utf-8');

if (!$can_view) { http_response_code(403); exit('Нет доступа'); }

$siteId = (int)($_POST['site_id'] ?? 0);
if ($siteId <= 0) { http_response_code(400); exit('Не передан объект'); }

// IP берём из БД (НЕ от клиента): lan хранится с маской (10.77.34.1/24)
$stmt = $mysqli->prepare("SELECT lan FROM mt_sites WHERE id = ?");
$stmt->bind_param('i', $siteId);
$stmt->execute();
$site = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$site) { http_response_code(404); exit('Объект не найден'); }

$ip = trim(explode('/', (string)$site['lan'])[0]);
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(400);
    exit('У объекта нет внутреннего IP (lan) — бэкап невозможен');
}

$params = "--ip $ip";

// уже есть такая заявка в очереди?
$stmt = $mysqli->prepare(
    "SELECT COUNT(*) c FROM job_run_requests
      WHERE job_key = 'backup_py' AND params = ? AND status IN ('pending','running')"
);
$stmt->bind_param('s', $params);
$stmt->execute();
$dup = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
if ($dup > 0) {
    http_response_code(409);
    exit("Бэкап этого устройства ($ip) уже в очереди");
}

$who = $_SESSION['login'] ?? $_SESSION['user'] ?? '';
$stmt = $mysqli->prepare(
    "INSERT INTO job_run_requests (job_key, params, requested_by) VALUES ('backup_py', ?, ?)"
);
$stmt->bind_param('ss', $params, $who);
$stmt->execute();
$stmt->close();

exit("Бэкап $ip поставлен в очередь — запустится в течение минуты. "
   . "Прогресс — в Настройках у задачи backup_py; файл появится после обновления списка.");

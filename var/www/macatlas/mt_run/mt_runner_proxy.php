<?php
include_once("../../loadinc.php");

header('Content-Type: application/json; charset=utf-8');

function out($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

// === Проверка прав: выполнение команд на устройствах — только с правом «выполнение» ===
if (!$can_exec) {
    out(['success' => false, 'error' => 'Нет прав на выполнение команд'], 403);
}

$siteId   = (int)($in['site_id'] ?? 0);
$commands = $in['commands'] ?? [];
$dryRun   = !empty($in['dry_run']);
$timeout  = (int)($in['timeout'] ?? 30);

if (!$siteId) out(['success' => false, 'error' => 'Не указан site_id'], 400);

if (is_string($commands)) {
    $commands = preg_split('/\r\n|\r|\n/', $commands);
}
if (!is_array($commands) || !count($commands)) {
    out(['success' => false, 'error' => 'Пустой список команд'], 400);
}

// --- подстановка шаблонных меток в команды ---
// Склеиваем команды обратно в текст, рендерим, снова разбиваем по строкам.
$cmdsText = implode("\n", $commands);
$cmdsText = mt_generate_config($mysqli, $siteId, $cmdsText);
$commands = preg_split('/\r\n|\r|\n/', $cmdsText);
$commands = array_values(array_filter(array_map('rtrim', $commands), function ($c) {
    $t = trim($c);
    return $t !== '' && $t[0] !== '#';
}));


$stmt = $mysqli->prepare(
    "SELECT d.id, d.hostname, d.ip, c.login, c.pas FROM mt_devices d
      LEFT JOIN mt_sites s    ON s.locations_id = d.locations_id
      LEFT JOIN credentials c ON c.id = s.cred_id
      WHERE s.id = ?
      LIMIT 1"
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    out(['success' => false, 'error' => 'Устройство не найдено'], 404);
}
if (empty($row['login']) || empty($row['pas'])) {
    out(['success' => false, 'error' => 'Не найдены учётные данные'], 400);
}

$ip = $row['ip'];
if (!$ip) {
    out(['success' => false, 'error' => 'Нет IP для подключения'], 400);
}

$user = $row['login'];
$pass = decryptPassword($row['pas'], $config['key']['master_key']);
if (empty($pass)) {
    out(['success' => false, 'error' => 'Не удалось расшифровать пароль'], 500);
}

// --- dry-run отвечает сразу ---
if ($dryRun) {
    out([
        'success' => true,
        'output'  => "DRY-RUN на {$ip} ({$user}):\n" . implode("\n", $commands)
    ]);
}

// --- payload для python ---
$payload = json_encode([
    'host'     => $ip,
    'user'     => $user,
    'password' => $pass,
    'commands' => array_values($commands),
    'timeout'  => $timeout
], JSON_UNESCAPED_UNICODE);

$script = __DIR__ . '/ssh_runner.py';
if (!is_file($script)) {
    out(['success' => false, 'error' => "Не найден скрипт: {$script}"], 500);
}

// --- запускаем python ---
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$cmd = 'python3 ' . escapeshellarg($script);

$proc = proc_open($cmd, $descriptors, $pipes, __DIR__);
if (!is_resource($proc)) {
    out(['success' => false, 'error' => 'Не удалось запустить python3'], 500);
}

fwrite($pipes[0], $payload);
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($proc);

$decoded = json_decode($stdout, true);
if (!is_array($decoded)) {
    $err = trim($stderr) ?: trim($stdout) ?: 'пустой ответ';
    out([
        'success' => false,
        'error'   => "python3 завершился с кодом {$exitCode}: " . mb_substr($err, 0, 500)
    ], 500);
}

out($decoded, !empty($decoded['success']) ? 200 : 400);
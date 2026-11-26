<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23):
// раньше endpoint отдавал данные любому авторизованному пользователю
if (!$can_view) {
    http_response_code(403);
    exit(json_encode(['data' => [], 'error' => 'Нет доступа к разделу'], JSON_UNESCAPED_UNICODE));
}


$runId = (int)($_GET['id'] ?? 0);
if (!$runId) { echo 'Неверный ID'; exit; }

$stmt = $mysqli->prepare("SELECT * FROM mt_history_runs WHERE id = ?");
$stmt->bind_param('i', $runId);
$stmt->execute();
$run = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$run) { echo 'Запуск не найден'; exit; }

$stmt = $mysqli->prepare("SELECT * FROM mt_history_items WHERE run_id = ? ORDER BY id");
$stmt->bind_param('i', $runId);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($r = $res->fetch_assoc()) $items[] = $r;
$stmt->close();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="mb-2">
    <b>Шаблон команд:</b>
    <pre class="hist-cmd"><?= h($run['commands_text']) ?></pre>
</div>

<div class="mt-3 mb-2"><b>Устройства (<?= count($items) ?>):</b></div>
<?php foreach ($items as $it): ?>
    <div class="item-line">
        <span style="color:<?= $it['success'] ? '#7ddf9a' : '#df7d7d' ?>;">
            <?= $it['success'] ? '✅' : '❌' ?>
        </span>
        <b><?= h($it['hostname']) ?></b>
        <span class="text-muted">(<?= h($it['ip']) ?>)</span>
        <?php if (!$it['success'] && $it['error_text']): ?>
            <div class="out"><?= h($it['error_text']) ?></div>
        <?php endif; ?>
        <?php if ($it['output']): ?>
            <div class="out"><?= h($it['output']) ?></div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php
// dataAED.php — все сохранения модуля «Настройки системы» (AJAX, без перезагрузки).
// oper: save_key | save_settings | job_toggle | job_schedule | job_run
// save_key — автосохранение одного поля (основной путь с 2026-11-25);
// save_settings — массовое сохранение всех ключей (legacy, кнопка удалена).
// Ответ: короткий текст сообщения (пусто = ок без сообщения).
include_once("../../loadinc.php");
include_once("registry.php");

header('Content-Type: text/html; charset=utf-8');

$oper = $_POST['oper'] ?? '';

$JOBS_OK = false;
$chk = $mysqli->query("SHOW TABLES LIKE 'collector_jobs'");
if ($chk && $chk->num_rows) $JOBS_OK = true;

// ---------- Автосохранение одного ключа (2026-11-25) ----------
// Каждое поле формы сохраняется сразу при изменении, кнопки «Сохранить» нет.
// Успех = пустой ответ; ошибка = 4xx/5xx + текст.
if ($oper === 'save_key') {
    if (!$can_edit) { http_response_code(403); exit('Нет прав на изменение настроек'); }
    $k = $_POST['key'] ?? '';
    if (!isset($REG[$k])) { http_response_code(400); exit('Неизвестный ключ настройки'); }
    $def = $REG[$k];
    $raw = $_POST['value'] ?? null;
    switch ($def['type']) {
        case 'bool':
            $v = !empty($raw) ? '1' : '0';
            break;
        case 'number':
            if (!empty($def['float'])) {
                $v = (string)(float)($raw ?? ($def['default'] ?? 0));
                if (isset($def['min']) && (float)$v < $def['min']) $v = (string)$def['min'];
                if (isset($def['max']) && (float)$v > $def['max']) $v = (string)$def['max'];
            } else {
                $v = (string)(int)($raw ?? ($def['default'] ?? 0));
                if (isset($def['min']) && (int)$v < $def['min']) $v = (string)$def['min'];
                if (isset($def['max']) && (int)$v > $def['max']) $v = (string)$def['max'];
            }
            break;
        case 'select_roles':
            $v = (string)(int)($raw ?? 0);
            break;
        case 'choice':
            $v = trim((string)$raw);
            if (!isset($def['options'][$v])) { http_response_code(400); exit('Недопустимое значение'); }
            break;
        case 'password':
            $plain = trim((string)$raw);
            if ($plain === '') { http_response_code(400); exit('Пустой пароль не сохраняется'); }
            $v = encryptPassword($plain);
            break;
        default:
            $v = trim((string)$raw);
    }
    $label = $def['label'];
    $stmt = $mysqli->prepare(
        "INSERT INTO settings (`key`, `name`, `value`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `value` = VALUES(`value`)"
    );
    $stmt->bind_param('sss', $k, $label, $v);
    $stmt->execute();
    $stmt->close();
    exit;
}

// ---------- Сохранение настроек (секции реестра) ----------
if ($oper === 'save_settings') {
    if (!$can_edit) { http_response_code(403); exit('Нет прав на изменение настроек'); }
    $stmt = $mysqli->prepare(
        "INSERT INTO settings (`key`, `name`, `value`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `value` = VALUES(`value`)"
    );
    foreach ($REG as $k => $def) {
        switch ($def['type']) {
            case 'bool':
                $v = !empty($_POST[$k]) ? '1' : '0';
                break;
            case 'number':
                if (!empty($def['float'])) {
                    $v = (string)(float)($_POST[$k] ?? ($def['default'] ?? 0));
                    if (isset($def['min']) && (float)$v < $def['min']) $v = (string)$def['min'];
                    if (isset($def['max']) && (float)$v > $def['max']) $v = (string)$def['max'];
                } else {
                    $v = (string)(int)($_POST[$k] ?? ($def['default'] ?? 0));
                    if (isset($def['min']) && (int)$v < $def['min']) $v = (string)$def['min'];
                    if (isset($def['max']) && (int)$v > $def['max']) $v = (string)$def['max'];
                }
                break;
            case 'select_roles':
                $v = (string)(int)($_POST[$k] ?? 0);
                break;
            case 'choice':
                $v = trim($_POST[$k] ?? '');
                if (!isset($def['options'][$v])) $v = $def['default'] ?? '';
                break;
            case 'password':
                $plain = trim($_POST[$k] ?? '');
                if ($plain === '') continue 2;   // пустое поле = не менять сохранённый пароль
                $v = encryptPassword($plain);
                break;
            default:
                $v = trim($_POST[$k] ?? '');
        }
        $label = $def['label'];
        $stmt->bind_param('sss', $k, $label, $v);
        $stmt->execute();
    }
    $stmt->close();
    exit('Настройки сохранены');
}

// ---------- Статус ручного запуска (опрос из JS) ----------
if ($oper === 'job_status') {
    $jkey = preg_replace('/[^a-z0-9_]/i', '', $_POST['job_key'] ?? '');
    $stmt = $mysqli->prepare(
        "SELECT status, exit_code, finished_at,
                progress_total, progress_done, progress_current, progress_at,
                TIMESTAMPDIFF(SECOND, progress_at, NOW()) AS progress_age_sec
         FROM job_run_requests
         WHERE job_key = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $jkey);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r ?: ['status' => 'none'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Задачи планировщика ----------
if (in_array($oper, ['job_toggle', 'job_schedule', 'job_run'], true)) {
    if (!$JOBS_OK) exit('Таблица collector_jobs не найдена (нет миграции)');
    if (!$can_edit && !$can_exec) { http_response_code(403); exit('Нет прав на управление задачами'); }

    $jkey = preg_replace('/[^a-z0-9_]/i', '', $_POST['job_key'] ?? '');

    if ($oper === 'job_toggle') {
        if (!$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
        $stmt = $mysqli->prepare("SELECT flag_key FROM collector_jobs WHERE job_key = ?");
        $stmt->bind_param('s', $jkey);
        $stmt->execute();
        $jrow = $stmt->get_result()->fetch_assoc();
        $flag = ($jrow && $jrow['flag_key'] !== '') ? $jrow['flag_key'] : "job_enabled_$jkey";
        $v = !empty($_POST['enabled']) ? '1' : '0';
        $stmt = $mysqli->prepare("INSERT INTO app_settings (name, value) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $stmt->bind_param('ss', $flag, $v);
        $stmt->execute();
        $mysqli->query("INSERT INTO app_settings (name, value) VALUES ('crontab_dirty', '1')
                        ON DUPLICATE KEY UPDATE value = '1'");
        exit(($v === '1' ? "Задача «$jkey» включена" : "Задача «$jkey» выключена")
             . " — crontab обновится в течение минуты");
    }

    if ($oper === 'job_schedule') {
        if (!$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
        $cron = trim(preg_replace('/\s+/', ' ', $_POST['cron'] ?? ''));
        if (!preg_match('/^\S+( \S+){4}$/', $cron)) {
            exit('Некорректное cron-выражение (5 полей: мин час день месяц день_недели): «' . $cron . '»');
        }
        $stmt = $mysqli->prepare("UPDATE collector_jobs SET cron = ? WHERE job_key = ?");
        $stmt->bind_param('ss', $cron, $jkey);
        $stmt->execute();
        $mysqli->query("INSERT INTO app_settings (name, value) VALUES ('crontab_dirty', '1')
                        ON DUPLICATE KEY UPDATE value = '1'");
        exit("Расписание «$jkey» сохранено — crontab обновится в течение минуты");
    }

    if ($oper === 'job_run') {
        $stmt = $mysqli->prepare("SELECT COUNT(*) c FROM job_run_requests WHERE job_key = ? AND status IN ('pending','running')");
        $stmt->bind_param('s', $jkey);
        $stmt->execute();
        if ((int)$stmt->get_result()->fetch_assoc()['c'] > 0) {
            exit("Задача «$jkey» уже в очереди на запуск");
        }
        $who = $_SESSION['login'] ?? $_SESSION['user'] ?? '';
        $stmt = $mysqli->prepare("INSERT INTO job_run_requests (job_key, requested_by) VALUES (?, ?)");
        $stmt->bind_param('ss', $jkey, $who);
        $stmt->execute();
        exit("Задача «$jkey» поставлена в очередь (запустится в течение минуты)");
    }
}

http_response_code(400);
exit('Неизвестная операция');

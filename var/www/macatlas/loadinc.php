<?php
// ============================================================
// CLI-режим (cron / ansible / ручной запуск из консоли):
// сессии и авторизация не нужны — только конфиг, БД и функции
// ============================================================
if (PHP_SAPI === 'cli') {
    // Подстраховка для core/acl.php, который читает REQUEST_URI
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '';
} else {
    // Старт сессии с явными флагами cookie (аудит 2026-11-23, С1)
    include_once(__DIR__ . "/core/session_boot.php");
    if (!isset($_SESSION['auth'])) {
        header("Location: /login.php");
        exit;
    }
}


// Конфиг вынесен из webroot; путь можно переопределить переменной окружения MACATLAS_CONFIG
$config_file = getenv('MACATLAS_CONFIG') ?: '/etc/macatlas/config.json';
if (!file_exists($config_file)) {
    die("Конфигурационный файл не найден.");
}
$config = json_decode(file_get_contents($config_file), true);
if ($config === null) {
    die("Ошибка чтения конфигурационного файла.");
}

define('DEBUG_MODE', (bool)($config['app']['debug'] ?? false));

include_once(__DIR__."/core/bd.php");
include_once(__DIR__."/core/functions.php");
include_once(__DIR__."/core/mt_ip_functions.php");
include_once(__DIR__."/core/config_engine.php");
include_once(__DIR__."/core/acl.php");

// ============================================================
// ЗАЩИТА ОТ НЕАКТИВНОСТИ
// Таймаут — настройка settings.session_idle_hours (часы, по умолчанию 3).
// Проверка после подключения к БД, т.к. значение читается из settings.
// ============================================================
if (PHP_SAPI !== 'cli') {
    $idleHrs = 3;
    $r = $mysqli->query("SELECT value FROM settings WHERE `key` = 'session_idle_hours'");
    if ($r && ($row = $r->fetch_assoc())) $idleHrs = max(1, (int)$row['value']);
    $idle_timeout = $idleHrs * 3600;

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idle_timeout)) {
        // Сессия истекла
        session_unset();
        session_destroy();

        // Если это AJAX-запрос — отдаём 401, чтобы JS понял
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            http_response_code(401);
            echo json_encode(['error' => 'session_expired']);
            exit;
        }

        // Для обычных запросов — редирект на логин с флагом
        header("Location: /login.php?expired=1");
        exit;
    }

    // Обновляем метку активности при каждом запросе —
    // без этого idle-таймаут никогда не срабатывает
    $_SESSION['last_activity'] = time();
}

// Статус лицензии — только для отображения в шапке (информационно, 2026-11-24)
$appst = sys_state();
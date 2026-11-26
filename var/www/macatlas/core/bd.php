<?php
/**
 * Подключение к базе данных через MySQLi
 * Настройки берутся из $config
 * Поддерживает безопасное логирование ошибок
 */

// Если DEBUG_MODE нигде не определён (например, забыли в config) —
// по умолчанию считаем прод-режим (безопасные сообщения, без деталей SQL наружу)
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

$dblocation = $config['bd']['dblocation'] ?? 'localhost';
$dbuser     = $config['bd']['dbuser'] ?? 'root';
$dbpasswd   = $config['bd']['dbpasswd'] ?? '';
$dbname     = $config['bd']['dbname'] ?? '';

// Включаем исключения для ошибок MySQLi —
// теперь ЛЮБАЯ ошибка запроса (не только при подключении) будет explicit-исключением,
// а не тихим false или голой 500-й
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Глобальный перехватчик — ловит исключения, не обёрнутые в try/catch
// в конкретных data.php/dataAED.php по всему проекту
set_exception_handler(function ($e) {
    if ($e instanceof mysqli_sql_exception) {
        error_log("MySQL error: " . $e->getMessage() . " | " . $e->getFile() . ':' . $e->getLine());
    } else {
        error_log("Unhandled exception: " . $e->getMessage() . " | " . $e->getFile() . ':' . $e->getLine());
    }

    http_response_code(500);

    if (DEBUG_MODE) {
        die("<strong>Ошибка:</strong> " . htmlspecialchars($e->getMessage()));
    } else {
        die("<strong>Ошибка сервера.</strong> Обратитесь к администратору.");
    }
});

try {
    // Создаём подключение
    $mysqli = new mysqli($dblocation, $dbuser, $dbpasswd, $dbname);

    // Устанавливаем кодировку
    $mysqli->set_charset('utf8mb4');

    // Устанавливаем SQL режимы и часовой пояс
    $mysqli->query("SET sql_mode = ''");
    $mysqli->query("SET time_zone = '+03:00'");

} catch (mysqli_sql_exception $e) {
    // Ошибка именно на подключении — обрабатываем отдельно,
    // чтобы дать понятное сообщение про БД, а не общее "ошибка сервера"
    error_log("DB Connection Error: {$e->getMessage()}");

    http_response_code(500);

    if (DEBUG_MODE) {
        die("<strong>Ошибка БД:</strong> " . htmlspecialchars($e->getMessage()));
    } else {
        die("<strong>Ошибка:</strong> Не удалось подключиться к базе данных. Обратитесь к администратору.");
    }
}
<?php
// Старт сессии с явными флагами cookie (аудит 2026-11-23, С1)
require_once(__DIR__ . "/core/session_boot.php");
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header('Location: /login.php');
exit;
<?php
// Единая точка старта сессии (аудит 2026-11-23, С1).
// Флаги сессионной cookie задаём ЯВНО, чтобы не зависеть от php.ini сервера:
//   httponly — cookie недоступна из JS (угон сессии через XSS сложнее);
//   samesite=Lax — cookie не уходит в POST с чужих сайтов (смягчает CSRF);
//   secure — только если запрос пришёл по HTTPS (на http-стенде не ломаем вход).
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,          // сессионная cookie — до закрытия браузера
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

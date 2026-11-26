<?php
// Старт сессии с явными флагами cookie (аудит 2026-11-23, С1)
require_once(__DIR__ . "/core/session_boot.php");
// Конфиг вынесен из webroot (/etc/macatlas/config.json)
$config = json_decode(file_get_contents(getenv('MACATLAS_CONFIG') ?: '/etc/macatlas/config.json'), true);
include_once(__DIR__ . "/core/bd.php");
include_once(__DIR__ . "/core/auth.php");

if (isset($_SESSION['auth'])) {
    header("Location: /");
    exit;
}
$expired = isset($_GET['expired']);

$error_message = $error == 1 ? $error_text : '';
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MacAtlas</title>
    <link rel="stylesheet" type="text/css" href="/plugins/style.css?v=<?= filemtime(__DIR__ . '/plugins/style.css') ?>" />
</head>
<body class="login-page">

<div class="login-card">
    <?php if ($error_message): ?>
        <div class="alert"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <?php if ($expired): ?>
        <div class="alert alert-warn">Сессия истекла из-за неактивности. Войдите заново.</div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="enter" value="1">
        <h2>Войдите в систему</h2>
        <input type="text" class="field" name="login" placeholder="Логин (без домена)" required autofocus>
        <input type="password" class="field" name="pwd"   placeholder="Пароль" required>
        <button class="btn" type="submit">Вход</button>
    </form>
</div>

</body>
</html>
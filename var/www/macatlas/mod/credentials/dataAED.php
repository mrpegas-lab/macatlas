<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Шифрование пароля ===
function encrypt_password($password) {
    global $config;
    $key = base64_decode($config['key']['master_key']);
    $iv  = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

$TABLE    = 'credentials';
$ID_FIELD = 'id';

$id         = $_POST['id']         ?? '';
$name       = trim($_POST['name']       ?? '');
$login      = trim($_POST['login']      ?? '');
$pas        = $_POST['pas']        ?? '';
$enable_pas = $_POST['enable_pas'] ?? '';

// Порты: пусто = NULL (будет порт по умолчанию)
function port_or_null($v) {
    $v = trim((string)($v ?? ''));
    if ($v === '' || !ctype_digit($v)) return null;
    $n = (int)$v;
    return ($n >= 1 && $n <= 65535) ? $n : null;
}
$port_ssh     = port_or_null($_POST['port_ssh']     ?? null);
$port_api     = port_or_null($_POST['port_api']     ?? null);
$port_api_ssl = port_or_null($_POST['port_api_ssl'] ?? null);

if (!$oper) exit;

// === ADD ===
if ($oper === 'add') {
    $pas_enc        = $pas        !== '' ? encrypt_password($pas)        : null;
    $enable_pas_enc = $enable_pas !== '' ? encrypt_password($enable_pas) : null;

    $stmt = $mysqli->prepare(
        "INSERT INTO credentials (name, login, pas, enable_pas, port_ssh, port_api, port_api_ssl)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ssssiii', $name, $login, $pas_enc, $enable_pas_enc,
                      $port_ssh, $port_api, $port_api_ssl);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}

// === EDIT ===
if ($oper === 'edit') {
    // 1. Основные поля
    $stmt = $mysqli->prepare(
        "UPDATE credentials SET name = ?, login = ?, port_ssh = ?, port_api = ?, port_api_ssl = ? WHERE id = ?"
    );
    $stmt->bind_param('ssiiii', $name, $login, $port_ssh, $port_api, $port_api_ssl, $id);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();

    // 2. Пароль — только если введён
    if ($pas !== '') {
        $pas_enc = encrypt_password($pas);
        $stmt = $mysqli->prepare("UPDATE credentials SET pas = ? WHERE id = ?");
        $stmt->bind_param('si', $pas_enc, $id);
        $stmt->execute();
        if ($stmt->error) exit('SQL: ' . $stmt->error);
        $stmt->close();
    }

    // 3. Enable-пароль — только если введён
    if ($enable_pas !== '') {
        $enable_pas_enc = encrypt_password($enable_pas);
        $stmt = $mysqli->prepare("UPDATE credentials SET enable_pas = ? WHERE id = ?");
        $stmt->bind_param('si', $enable_pas_enc, $id);
        $stmt->execute();
        if ($stmt->error) exit('SQL: ' . $stmt->error);
        $stmt->close();
    }

    exit;
}

// === DELETE ===
if ($oper === 'del') {
    // Запрет, если креды используются: hardware + роутеры объектов (mt_sites)
    $usage = [];

    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) AS c,
                GROUP_CONCAT(switch_name ORDER BY switch_name SEPARATOR ', ') AS names
           FROM hardware WHERE cred_id = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['c'] > 0) {
        $names = ($row['names'] ?? '') !== '' ? explode(', ', $row['names']) : [];
        $s = implode(', ', array_slice($names, 0, 5));
        if (count($names) > 5) $s .= ', …';
        $usage[] = 'Оборудование (' . (int)$row['c'] . '): ' . $s;
    }

    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) AS c,
                GROUP_CONCAT(COALESCE(NULLIF(lan, ''), ip1, CAST(id AS CHAR)) SEPARATOR ', ') AS names
           FROM mt_sites WHERE cred_id = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['c'] > 0) {
        $names = ($row['names'] ?? '') !== '' ? explode(', ', $row['names']) : [];
        $s = implode(', ', array_slice($names, 0, 5));
        if (count($names) > 5) $s .= ', …';
        $usage[] = 'Роутеры объектов (' . (int)$row['c'] . '): ' . $s;
    }

    if ($usage) {
        http_response_code(400);
        exit('Нельзя удалить учётные данные, они используются: ' . implode(' · ', $usage)
             . '. Сначала отвяжите устройства от этих кредов.');
    }

    $stmt = $mysqli->prepare("DELETE FROM credentials WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();
    exit;
}
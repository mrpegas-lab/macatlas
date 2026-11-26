<?php
// display_errors здесь НЕ включаем (аудит 2026-11-23, В3):
// страница логина публична — ошибки/пути PHP не должны показываться наружу.
// Ошибки пишутся в лог PHP (log_errors в php.ini).

$error_text = "Вы неверно ввели имя пользователя или пароль!";

// Антибрутфорс (аудит 2026-11-23, С2): счётчик неудач в auth_login_attempts,
// после LOGIN_MAX_ATTEMPTS подряд — блокировка на LOGIN_LOCK_MINUTES минут
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCK_MINUTES', 15);

$enter = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enter']))
    ? $_POST['enter']
    : '';

$error = 0;

if ($enter == 1 && !isset($_SESSION['auth'])) {

    $login = trim($_POST['login'] ?? '');
    $pass  = $_POST['pwd'] ?? '';

    if ($login === '' || $pass === '') {
        $error = 1;
    } else {

        // --- Антибрутфорс: проверка блокировки ДО проверки пароля ---
        $client_ip   = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $auth_locked = false;
        if ($stmt = $mysqli->prepare("SELECT locked_until FROM auth_login_attempts WHERE ip = ? AND login = ? AND locked_until > NOW() LIMIT 1")) {
            $stmt->bind_param("ss", $client_ip, $login);
            $stmt->execute();
            $auth_locked = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        // ленивая чистка записей старше суток (в среднем раз на 50 заходов)
        if (random_int(1, 50) === 1) {
            @$mysqli->query("DELETE FROM auth_login_attempts WHERE last_attempt_at < NOW() - INTERVAL 1 DAY");
        }

        if ($auth_locked) {
            $error = 1;
            $error_text = 'Слишком много неудачных попыток входа. Попробуйте через ' . LOGIN_LOCK_MINUTES . ' минут.';
        } else {

        // --- настройки LDAP: только из БД (таблица settings) ---
        $ldap_db = [];
        $res = $mysqli->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'ldap_%'");
        if ($res) {
            while ($r = $res->fetch_assoc()) $ldap_db[$r['key']] = $r['value'];
        }
        $ldap_enabled = (bool)(int)($ldap_db['ldap_enabled'] ?? 0);
        $ldap_domain  = $ldap_db['ldap_domain'] ?? '';
        $ldap_host    = $ldap_db['ldap_host']   ?? '';
        $ldap_port    = (int)($ldap_db['ldap_port'] ?? 389);
        $ldap_tls     = (int)($ldap_db['ldap_tls'] ?? 0); // 0=нет, 1=StartTLS, 2=LDAPS (аудит 2026-11-23, В5)
        $default_role = (int)($ldap_db['ldap_default_role_id'] ?? 0);

        // 1) ищем локального пользователя
        $stmt = $mysqli->prepare("SELECT * FROM acl_staff WHERE login = ? LIMIT 1");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $auth_success = false;

        // 2) неактивные — сразу отказ
        if ($row && (int)$row['is_active'] === 0) {
            $error = 1;
        }
        // 3) локальная проверка
        elseif ($row && $row['auth_type'] === 'local' && password_verify($pass, $row['password'])) {
            $auth_success = true;
        }
        // 4) доменная проверка
        elseif ($ldap_enabled && (!$row || $row['auth_type'] === 'ldap')) {
            $ldap_login = (strpos($login, '@') !== false)
                ? $login
                : $login . "@" . $ldap_domain;

            // В5 (аудит 2026-11-23): доменный пароль не должен идти открытым текстом.
            // ldap_tls: 0 = без шифрования (как раньше), 1 = StartTLS на 389, 2 = LDAPS (636).
            // Если шифрование включено, но не поднялось — НЕ биндимся вообще
            // (никакого молчаливого отката на открытое соединение).
            $ldap_conn = ldap_connect(($ldap_tls === 2 ? 'ldaps' : 'ldap') . "://{$ldap_host}:{$ldap_port}");
            if ($ldap_conn) {
                ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
                if ($ldap_tls > 0) {
                    // сертификат КД обычно самоподписанный — не проверяем (канал всё равно шифруется);
                    // строгая проверка — через /etc/ldap/ldap.conf (TLS_REQCERT demand + TLS_CACERT)
                    if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
                        ldap_set_option($ldap_conn, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
                    }
                    if ($ldap_tls === 1 && !@ldap_start_tls($ldap_conn)) {
                        $ldap_conn = null; // StartTLS не поднялся — пароль открытым не отправляем
                    }
                }
            }
            if ($ldap_conn) {
                $ldap_bind = @ldap_bind($ldap_conn, $ldap_login, $pass);

                if ($ldap_bind) {
                    $auth_success = true;
                    @ldap_unbind($ldap_conn);

                    // авто-создание локальной записи для LDAP-пользователя
                    if (!$row) {
                        $fio = $login;

                        $stmt = $mysqli->prepare(
                            "INSERT INTO acl_staff
                                 (login, password, fio, role_id, auth_type, is_active, is_protected)
                             VALUES (?, NULL, ?, ?, 'ldap', 1, 0)"
                        );
                        $stmt->bind_param("ssi", $login, $fio, $default_role);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $mysqli->prepare("SELECT * FROM acl_staff WHERE login = ? LIMIT 1");
                        $stmt->bind_param("s", $login);
                        $stmt->execute();
                        $row = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                    }
                }
            }
        }

        // 5) успешный вход
        if ($auth_success && $row) {
            $id = (int)$row['id'];

            $_SESSION['auth']          = $id;
            $_SESSION['fio']           = $row['fio'];
            $_SESSION['acl_groups_id'] = (int)$row['role_id'];
            $_SESSION['auth_type']     = $row['auth_type'];
            $_SESSION['last_activity'] = time(); // старт отсчёта idle-таймаута

            $stmt = $mysqli->prepare("UPDATE acl_staff SET login_date = NOW() WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $SQL_prov = "SELECT p.can_view, p.can_add, p.can_edit, p.can_del, p.can_exec, r.code
                         FROM acl_permissions p
                         INNER JOIN acl_resources r ON p.resource_id = r.id
                         WHERE p.role_id = ?";
            $stmt_prov = $mysqli->prepare($SQL_prov);
            $stmt_prov->bind_param("i", $row['role_id']);
            $stmt_prov->execute();
            $result_prov = $stmt_prov->get_result();

            $_SESSION['acl'] = [];
            while ($p = $result_prov->fetch_assoc()) {
                $_SESSION['acl'][$p['code']] = [
                    "view" => $p['can_view'],
                    "add"  => $p['can_add'],
                    "edit" => $p['can_edit'],
                    "del"  => $p['can_del'],
                    "exec" => $p['can_exec'],
                ];
            }
            $stmt_prov->close();

            // Антибрутфорс: сброс счётчика после успешного входа
            if ($stmt = $mysqli->prepare("DELETE FROM auth_login_attempts WHERE ip = ? AND login = ?")) {
                $stmt->bind_param("ss", $client_ip, $login);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $error = 1;

            // Антибрутфорс: фиксируем неудачу
            if ($stmt = $mysqli->prepare("INSERT INTO auth_login_attempts (ip, login, attempts, last_attempt_at)
                                          VALUES (?, ?, 1, NOW())
                                          ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = NOW()")) {
                $stmt->bind_param("ss", $client_ip, $login);
                $stmt->execute();
                $stmt->close();
            }
            // достигли лимита — блокируем и обнуляем счётчик
            if ($stmt = $mysqli->prepare("UPDATE auth_login_attempts
                                          SET locked_until = DATE_ADD(NOW(), INTERVAL " . LOGIN_LOCK_MINUTES . " MINUTE), attempts = 0
                                          WHERE ip = ? AND login = ? AND attempts >= " . LOGIN_MAX_ATTEMPTS)) {
                $stmt->bind_param("ss", $client_ip, $login);
                $stmt->execute();
                $stmt->close();
            }
        }

        } // конец ветки «не заблокирован» (антибрутфорс)
    }
}
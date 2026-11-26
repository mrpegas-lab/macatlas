<?php
function decryptPassword($encrypted) {
    global $config;

    if (empty($encrypted)) return '';

    $masterKey = $config['key']['master_key'] ?? '';
    if (empty($masterKey)) return '';

    $key  = base64_decode($masterKey);
    $data = base64_decode($encrypted);

    if ($data === false || strlen($data) < 17) return '';

    $ivLen      = openssl_cipher_iv_length('aes-256-cbc');
    $iv         = substr($data, 0, $ivLen);
    $ciphertext = substr($data, $ivLen);

    $result = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $result === false ? '' : $result;
}

// Шифрование тем же методом, что decryptPassword (AES-256-CBC, iv в начале).
// Используется для паролей в настройках (zabbix_password и т.п.).
function encryptPassword($plain) {
    global $config;

    if ($plain === '' || $plain === null) return '';

    $masterKey = $config['key']['master_key'] ?? '';
    if (empty($masterKey)) return '';

    $key = base64_decode($masterKey);
    $iv  = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $enc = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $enc === false ? '' : base64_encode($iv . $enc);
}

function mikrotikApi($ip, $login, $pass, $method = '', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://{$ip}/rest/" . ltrim($method, '/'));
    curl_setopt($ch, CURLOPT_USERPWD, "$login:$pass");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error)           return ['error' => $error];
    if ($httpCode >= 300) return ['error' => "HTTP $httpCode"];

    return json_decode($response, true) ?: [];
}

function db_query_page($sql, $params, $types, $order, $page, $limit) {
    global $mysqli;
    $page  = max(1, (int)$page);
    $limit = max(1, (int)$limit);
    $start = ($page - 1) * $limit;

    $sql .= $order ? " ORDER BY $order" : '';
    $sql .= " LIMIT ?, ?";
    $params[] = $start;
    $params[] = $limit;
    $types .= 'ii';

    $stmt = $mysqli->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function db_count($sql, $params = [], $types = '') {
    global $mysqli;
    $stmt = $mysqli->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $n;
}

function dt_respond($sql, $countSql, $params, $types, $order, $columnsMap, $extraSearch = [], $searchColumns = null) {
    $draw   = (int)($_POST['draw'] ?? 1);
    $start  = (int)($_POST['start'] ?? 0);
    $length = (int)($_POST['length'] ?? 25);
    $search = trim($_POST['search']['value'] ?? '');

    // === Сборка ORDER BY ===
    $orderParts = [];
    if (!empty($_POST['order']) && is_array($_POST['order'])) {
        foreach ($_POST['order'] as $o) {
            $colIdx = (int)($o['column'] ?? 0);
            $dir    = ($o['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
            $col    = $columnsMap[$colIdx] ?? null;
            if ($col) $orderParts[] = "$col $dir";
        }
    }
    if (empty($orderParts) && $order !== '') {
        $orderParts[] = $order;
    }
    if (empty($orderParts)) {
        $firstCol = $columnsMap[0] ?? 'id';
        $orderParts[] = "$firstCol ASC";
    }
    $orderSql = implode(', ', $orderParts);

    // === Запоминаем исходные параметры ===
    $paramsOriginal   = $params;
    $typesOriginal    = $types;
    $countSqlOriginal = $countSql;

    // === Всего записей (без фильтра) ===
    $total = db_count($countSqlOriginal, $paramsOriginal, $typesOriginal);

    // === Поиск ===
    $filtered = $total;
    if ($search !== '') {
        $whereParts   = [];
        $searchParams = [];
        $searchTypes  = '';

        // Если список колонок для поиска не задан — ищем по всем columnsMap
        $cols = $searchColumns !== null ? $searchColumns : $columnsMap;

        foreach ($cols as $c) {
            $whereParts[]   = "$c LIKE ?";
            $searchParams[] = "%$search%";
            $searchTypes   .= 's';
        }

        foreach ($extraSearch as $cond) {
            $whereParts[]   = $cond;
            $searchParams[] = "%$search%";
            $searchTypes   .= 's';
        }

        $searchCond = '(' . implode(' OR ', $whereParts) . ')';

        // Добавляем WHERE или AND через sel()
        $sql      = sel($sql,      'AND') . $searchCond;
        $countSql = sel($countSql, 'AND') . $searchCond;

        $allParams = array_merge($params, $searchParams);
        $allTypes  = $types . $searchTypes;

        $filtered = db_count($countSql, $allParams, $allTypes);
        $params   = $allParams;
        $types    = $allTypes;
    }

    // === Данные страницы ===
    $page = intdiv($start, max(1, $length)) + 1;
    $rows = db_query_page($sql, $params, $types, $orderSql, $page, $length);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $filtered,
        'data'            => $rows
    ], JSON_UNESCAPED_UNICODE);
}

function sel($par, $andor)
{
    // Ищем WHERE только на верхнем уровне (вне скобок),
    // чтобы не спутать с WHERE внутри подзапросов.
    $depth = 0;
    $hasWhere = false;
    $len = strlen($par);
    for ($i = 0; $i < $len; $i++) {
        $ch = $par[$i];
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        } elseif ($depth === 0 && ($ch === 'W' || $ch === 'w')) {
            if (strtoupper(substr($par, $i, 5)) === 'WHERE') {
                $hasWhere = true;
                break;
            }
        }
    }
    $par .= $hasWhere ? " " . $andor . " " : " WHERE ";
    return $par;
}


function error($error)
{
    if ($error != "") {
        echo <<<QQQ
<div class="container">
        <div class="alert alert-danger" role="alert">
            <strong>Ошибка: </strong> $error
        </div>
</div>
QQQ;
    }
}

// ---------------------------------------------------------------
// Служебные параметры среды (не изменять)
// ---------------------------------------------------------------
function _sys_s() { return base64_decode('TWFjQXRsYXM6OkxpY2Vuc2U6OnYx'); }

function _sys_pk() {
    return base64_decode('LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0NCk1JSUJJakFOQmdrcWhraUc5dzBCQVFFRkFBT0NBUThBTUlJQkNnS0NBUUVBb25sWHVxanBtc01ST080djczTmINCmZxbVU2ekx4OXM4dVVESGxnM3V3WFMxTVVFV0tsQ1NUTUJmZktwZmNNNWtCclE4RXRPZlBybXRwS2dMcFByc1YNCjZzemJKWE4yZVk2aXIyTUNmMytvbTdxS1ZQVnhpajY3cUc0SnpSaTZ5WHQybTdSMHRjdFdCdk1yeithS3pEazgNCjd3Y2Jtbm1IN2YxalhvbEF6cUh6RUNQK0RDQUxyQkFYclA0dFFCdWxxVTZLaWM1azFaMEJkZmgzeEs3QWVEcS8NCnAxS2trOGluUTc2dVN2K2RmbGNjMHZiMGJqSnkrUkJWdklUZzlaUVJuWVBVdlgxUkJ2ZzlQbFgxWVNXM2lSS2sNCk44S2JzOXdId2NRMjY3M0JtLzlWOWxYTHN3VXdIY2NOeFhkR1pzUkR0NXluSW9zZWRUN2p2dlo1Q2xKTmRRV2gNCmV3SURBUUFCDQotLS0tLUVORCBQVUJMSUMgS0VZLS0tLS0NCg==');
}

function _sys_fp() {
    return substr(hash('sha256', php_uname('n') . '|' . _sys_s()), 0, 16);
}

// ---------------------------------------------------------------
// Статус лицензии — ИНФОРМАЦИОННЫЙ (2026-11-24, решение владельца):
// ни на что не влияет, только отображение в шапке и «О программе».
// ---------------------------------------------------------------
function sys_state() {
    $st = ['valid' => false, 'reason' => 'missing', 'company' => '', 'expires_at' => '', 'days_left' => null];

    // Файл лицензии лежит вне webroot; путь можно переопределить переменной окружения MACATLAS_LICENSE
    $file = getenv('MACATLAS_LICENSE') ?: '/etc/macatlas/license.json';
    if (!file_exists($file)) return $st;

    $data = json_decode(file_get_contents($file), true);
    $lic  = $data['license'] ?? null;
    if (!is_array($lic)) { $st['reason'] = 'corrupt'; return $st; }

    $st['company']    = $lic['company'] ?? '';
    $st['expires_at'] = $lic['expires_at'] ?? '';

    $payload = implode('|', [
        $lic['company']     ?? '',
        $lic['issued_at']   ?? '',
        $lic['expires_at']  ?? '',
        $lic['fingerprint'] ?? '',
    ]);
    $sig = base64_decode((string)($lic['signature'] ?? ''), true);
    $ok  = ($sig !== false && function_exists('openssl_verify'))
        ? openssl_verify($payload, $sig, _sys_pk(), OPENSSL_ALGO_SHA256)
        : 0;
    if ($ok !== 1) { $st['reason'] = 'bad_signature'; return $st; }

    if (!empty($lic['fingerprint']) && !hash_equals((string)$lic['fingerprint'], _sys_fp())) {
        $st['reason'] = 'other_server';
        return $st;
    }

    if (!empty($lic['expires_at'])) {
        $days = (int)floor((strtotime($lic['expires_at']) - strtotime(date('Y-m-d'))) / 86400);
        $st['days_left'] = $days;
        if ($days < 0) { $st['reason'] = 'expired'; return $st; }
    }

    $st['valid']  = true;
    $st['reason'] = 'ok';
    return $st;
}
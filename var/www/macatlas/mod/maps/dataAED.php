<?php
// Изменения карты сети: узлы и линки.
// Все действия POST, action = ...
// Пустой ответ = успех; непустой текст = ошибка (показывается в UI).
include_once("../../loadinc.php");

header('Content-Type: text/plain; charset=utf-8');

$MAP_ID = intval($_POST['map_id'] ?? 1);
if ($MAP_ID <= 0) $MAP_ID = 1;
$action = $_POST['action'] ?? '';

// Действия, меняющие карту, требуют права редактирования
if (!$can_edit) {
    http_response_code(403);
    exit('Нет прав на редактирование карты');
}

$ROLES = ['router', 'switch', 'ap', 'modem', 'server', 'pc', 'printer', 'camera', 'intercom', 'phone', 'cloud', 'umswitch', 'other'];
$SPEEDS = ['1G', '10G', '25G', '40G', '100G'];
$COLORS = ['gray', 'red', 'blue', 'green', 'orange', 'purple'];
$LINES  = ['smooth', 'elbow'];

// иконки узлов (glyph'ы и список файлов из custom/)
require_once __DIR__ . '/icons_lib.php';

// ---------- УЗЛЫ ----------

if ($action === 'add_node') {
    $hardware_id = intval($_POST['hardware_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $role  = $_POST['role'] ?? 'other';
    if (!in_array($role, $ROLES, true)) $role = 'other';

    if ($hardware_id > 0) {
        // не даём добавить одно и то же железо дважды
        $chk = $mysqli->prepare("SELECT COUNT(*) c FROM map_nodes WHERE map_id = ? AND hardware_id = ?");
        $chk->bind_param("ii", $MAP_ID, $hardware_id);
        $chk->execute();
        if ((int)$chk->get_result()->fetch_assoc()['c'] > 0) {
            exit('Это устройство уже есть на карте');
        }
        // label по умолчанию — switch_name
        if ($label === '') {
            $q = $mysqli->prepare("SELECT switch_name FROM hardware WHERE id = ?");
            $q->bind_param("i", $hardware_id);
            $q->execute();
            $row = $q->get_result()->fetch_assoc();
            if (!$row) exit('Устройство не найдено');
            $label = $row['switch_name'] ?: ('hw-' . $hardware_id);
        }
    } else {
        // свободный узел (Интернет и т.п.)
        if ($label === '') exit('Укажите подпись узла');
        $hardware_id = null;
    }

    // координаты — центр текущего вида пользователя (клиент), иначе (0,0)
    $x = intval($_POST['x'] ?? 0);
    $y = intval($_POST['y'] ?? 0);
    if ($x < -100000 || $x > 100000) $x = 0;
    if ($y < -100000 || $y > 100000) $y = 0;

    $stmt = $mysqli->prepare("INSERT INTO map_nodes (map_id, hardware_id, label, role, x, y) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissii", $MAP_ID, $hardware_id, $label, $role, $x, $y);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'update_node') {
    $id    = intval($_POST['id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $role  = $_POST['role'] ?? 'other';
    $icon  = trim($_POST['icon'] ?? '');
    $checkType = strtolower(trim($_POST['check_type'] ?? 'ping'));
    $checkPort = intval($_POST['check_port'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    if ($id <= 0) exit('Не передан ID узла');
    if ($label === '') exit('Подпись не может быть пустой');
    if (!in_array($role, $ROLES, true)) $role = 'other';
    if (!in_array($checkType, ['ping', 'http', 'https', 'tcp', 'dns', 'ssh', 'snmp'], true)) {
        $checkType = 'ping';
    }
    if ($checkPort < 1 || $checkPort > 65535) $checkPort = null;
    // иконка — только имя файла из custom/, иначе сброс на «по типу»
    $icon = basename($icon);
    if ($icon === '' || !in_array($icon, maps_icon_files(), true)) $icon = null;

    $stmt = $mysqli->prepare("UPDATE map_nodes SET label = ?, comment = ?, role = ?, icon = ?, check_type = ?, check_port = ? WHERE id = ? AND map_id = ?");
    $stmt->bind_param("sssssiii", $label, $comment, $role, $icon, $checkType, $checkPort, $id, $MAP_ID);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'move_node') {
    $id = intval($_POST['id'] ?? 0);
    $x  = floatval($_POST['x'] ?? 0);
    $y  = floatval($_POST['y'] ?? 0);
    if ($id <= 0) exit('Не передан ID узла');

    $stmt = $mysqli->prepare("UPDATE map_nodes SET x = ?, y = ? WHERE id = ? AND map_id = ?");
    $stmt->bind_param("ddii", $x, $y, $id, $MAP_ID);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'del_node') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) exit('Не передан ID узла');

    $mysqli->begin_transaction();
    try {
        // точки излома линков этого узла
        $stmt = $mysqli->prepare("DELETE p FROM map_link_points p
                                  JOIN map_links l ON l.id = p.link_id
                                  WHERE l.map_id = ? AND (l.src_node = ? OR l.dst_node = ?)");
        $stmt->bind_param("iii", $MAP_ID, $id, $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $stmt = $mysqli->prepare("DELETE FROM map_links WHERE map_id = ? AND (src_node = ? OR dst_node = ?)");
        $stmt->bind_param("iii", $MAP_ID, $id, $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $stmt = $mysqli->prepare("DELETE FROM map_nodes WHERE id = ? AND map_id = ?");
        $stmt->bind_param("ii", $id, $MAP_ID);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        exit($e->getMessage());
    }
    exit('');
}

// ---------- ЛИНКИ ----------

if ($action === 'add_link' || $action === 'update_link') {
    $src_port = trim($_POST['src_port'] ?? '');
    $dst_port = trim($_POST['dst_port'] ?? '');
    $speed    = $_POST['speed'] ?? '';
    $label    = trim($_POST['label'] ?? '');
    $color    = $_POST['color'] ?? '';
    $line     = $_POST['line'] ?? 'smooth';
    if ($speed !== '' && !in_array($speed, $SPEEDS, true)) $speed = '';
    if (!in_array($color, $COLORS, true)) $color = null;
    if (!in_array($line, $LINES, true)) $line = 'smooth';

    if ($action === 'add_link') {
        $src = intval($_POST['src_node'] ?? 0);
        $dst = intval($_POST['dst_node'] ?? 0);
        if ($src <= 0 || $dst <= 0) exit('Не выбраны узлы линка');
        if ($src === $dst) exit('Нельзя связать узел сам с собой');

        // параллельные линки между одной парой узлов разрешены (LAG и т.п.) —
        // на карте они разводятся кривизной, см. renderLink в index.php
        $stmt = $mysqli->prepare("INSERT INTO map_links (map_id, src_node, dst_node, src_port, dst_port, speed, label, color, line) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiissssss", $MAP_ID, $src, $dst, $src_port, $dst_port, $speed, $label, $color, $line);
    } else {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) exit('Не передан ID линка');
        $stmt = $mysqli->prepare("UPDATE map_links SET src_port = ?, dst_port = ?, speed = ?, label = ?, color = ?, line = ? WHERE id = ? AND map_id = ?");
        $stmt->bind_param("ssssssii", $src_port, $dst_port, $speed, $label, $color, $line, $id, $MAP_ID);
    }
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);

    // Обслуживание точек излома:
    // - elbow: если точек нет и клиент передал px,py — создаём первую
    // - не elbow: точки удаляем, чтобы не копились
    $linkId = ($action === 'add_link') ? $mysqli->insert_id : $id;
    if ($line === 'elbow') {
        $chk = $mysqli->prepare("SELECT COUNT(*) c FROM map_link_points WHERE link_id = ?");
        $chk->bind_param("i", $linkId);
        $chk->execute();
        $hasPoints = (int)$chk->get_result()->fetch_assoc()['c'] > 0;
        $px = $_POST['px'] ?? null;
        $py = $_POST['py'] ?? null;
        if (!$hasPoints && $px !== null && $py !== null) {
            $stmt = $mysqli->prepare("INSERT INTO map_link_points (link_id, seq, x, y) VALUES (?, 0, ?, ?)");
            $fx = floatval($px); $fy = floatval($py);
            $stmt->bind_param("idd", $linkId, $fx, $fy);
            $stmt->execute();
        }
    } else {
        $stmt = $mysqli->prepare("DELETE FROM map_link_points WHERE link_id = ?");
        $stmt->bind_param("i", $linkId);
        $stmt->execute();
    }
    exit('');
}

// ---------- ТОЧКИ ИЗЛОМА ----------

if ($action === 'add_point') {
    $linkId = intval($_POST['link_id'] ?? 0);
    $x = floatval($_POST['x'] ?? 0);
    $y = floatval($_POST['y'] ?? 0);
    if ($linkId <= 0) exit('Не передан ID линка');

    // в конец цепочки
    $stmt = $mysqli->prepare("INSERT INTO map_link_points (link_id, seq, x, y)
                              SELECT ?, COALESCE(MAX(seq), -1) + 1, ?, ?
                              FROM map_link_points WHERE link_id = ?");
    $stmt->bind_param("iddi", $linkId, $x, $y, $linkId);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'move_point') {
    $id = intval($_POST['id'] ?? 0);
    $x  = floatval($_POST['x'] ?? 0);
    $y  = floatval($_POST['y'] ?? 0);
    if ($id <= 0) exit('Не передан ID точки');

    $stmt = $mysqli->prepare("UPDATE map_link_points SET x = ?, y = ? WHERE id = ?");
    $stmt->bind_param("ddi", $x, $y, $id);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'del_point') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) exit('Не передан ID точки');

    $stmt = $mysqli->prepare("DELETE FROM map_link_points WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'swap_link') {
    // разворот направления: узлы и их порты меняются местами
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) exit('Не передан ID линка');

    $stmt = $mysqli->prepare("SELECT src_node, dst_node, src_port, dst_port FROM map_links WHERE id = ? AND map_id = ?");
    $stmt->bind_param("ii", $id, $MAP_ID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) exit('Линк не найден');

    $stmt = $mysqli->prepare("UPDATE map_links SET src_node = ?, dst_node = ?, src_port = ?, dst_port = ? WHERE id = ? AND map_id = ?");
    $stmt->bind_param("iissii", $row['dst_node'], $row['src_node'], $row['dst_port'], $row['src_port'], $id, $MAP_ID);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'del_link') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) exit('Не передан ID линка');

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("DELETE FROM map_link_points WHERE link_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $stmt = $mysqli->prepare("DELETE FROM map_links WHERE id = ? AND map_id = ?");
        $stmt->bind_param("ii", $id, $MAP_ID);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        exit($e->getMessage());
    }
    exit('');
}

// ---------- БЛОКИ-КОНТЕЙНЕРЫ ----------

if ($action === 'add_group') {
    $x = floatval($_POST['x'] ?? 0);
    $y = floatval($_POST['y'] ?? 0);
    $stmt = $mysqli->prepare("INSERT INTO map_groups (map_id, label, x, y, w, h) VALUES (?, 'Блок', ?, ?, 300, 200)");
    $stmt->bind_param("idd", $MAP_ID, $x, $y);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'update_group') {
    $id    = intval($_POST['id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    if ($id <= 0) exit('Не передан ID блока');

    $stmt = $mysqli->prepare("UPDATE map_groups SET label = ? WHERE id = ? AND map_id = ?");
    $stmt->bind_param("sii", $label, $id, $MAP_ID);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'geom_group') {
    // перемещение/размер блока одним действием (клиент таскает блок мышкой)
    $id = intval($_POST['id'] ?? 0);
    $x  = floatval($_POST['x'] ?? 0);
    $y  = floatval($_POST['y'] ?? 0);
    $w  = floatval($_POST['w'] ?? 0);
    $h  = floatval($_POST['h'] ?? 0);
    if ($id <= 0) exit('Не передан ID блока');
    // минимальный размер, чтобы блок не схлопнулся
    if ($w < 80) $w = 80;
    if ($h < 50) $h = 50;

    $stmt = $mysqli->prepare("UPDATE map_groups SET x = ?, y = ?, w = ?, h = ? WHERE id = ? AND map_id = ?");
    $stmt->bind_param("ddddii", $x, $y, $w, $h, $id, $MAP_ID);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'del_group') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) exit('Не передан ID блока');

    $stmt = $mysqli->prepare("DELETE FROM map_groups WHERE id = ? AND map_id = ?");
    $stmt->bind_param("ii", $id, $MAP_ID);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

// ---------- КАРТОЧКА ОБОРУДОВАНИЯ (из узла карты) ----------

// JSON-помощник для карточки оборудования: успех = JSON,
// ошибка = http-код + текст (как в остальных action'ах)
function maps_json($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// транслитерация для locations.new_name (машинное имя — папки бэкапов/inventory)
function maps_translit($s) {
    static $t = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e',
        'ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m',
        'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
        'ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    $s = mb_strtolower(trim((string)$s));
    $s = strtr($s, $t);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}

if ($action === 'hw_form') {
    // справочники + все устройства (автозаполнение при выборе существующего)
    $out = ['ok' => 1, 'hardware' => [], 'models' => [], 'brands' => [],
            'model_types' => [], 'locations' => [], 'loc_types' => [],
            'rooms' => [], 'creds' => []];

    $res = $mysqli->query("SELECT id, id_model, switch_name, hardware_ip, poe,
                                  locations_id, rooms_id, active, cred_id
                           FROM hardware ORDER BY switch_name");
    while ($r = $res->fetch_assoc()) $out['hardware'][] = $r;

    $res = $mysqli->query("SELECT m.id, m.model_name, m.id_brand, b.brand_name
                           FROM model m JOIN brand b ON b.id = m.id_brand
                           ORDER BY b.brand_name, m.model_name");
    while ($r = $res->fetch_assoc()) $out['models'][] = $r;

    $res = $mysqli->query("SELECT id, brand_name FROM brand ORDER BY brand_name");
    while ($r = $res->fetch_assoc()) $out['brands'][] = $r;

    $res = $mysqli->query("SELECT id, name FROM model_type ORDER BY id");
    while ($r = $res->fetch_assoc()) $out['model_types'][] = $r;

    $res = $mysqli->query("SELECT l.id, l.name, lt.name AS type_name
                           FROM locations l
                           LEFT JOIN locations_type lt ON lt.id = l.type_id
                           ORDER BY lt.sort, lt.name, l.name");
    while ($r = $res->fetch_assoc()) $out['locations'][] = $r;

    $res = $mysqli->query("SELECT id, name FROM locations_type ORDER BY sort, name");
    while ($r = $res->fetch_assoc()) $out['loc_types'][] = $r;

    $res = $mysqli->query("SELECT id, locations_id, name FROM locations_rooms ORDER BY name");
    while ($r = $res->fetch_assoc()) $out['rooms'][] = $r;

    $res = $mysqli->query("SELECT id, name FROM credentials ORDER BY name");
    while ($r = $res->fetch_assoc()) $out['creds'][] = $r;

    maps_json($out);
}

if ($action === 'hw_save') {
    $node_id  = intval($_POST['node_id'] ?? 0);
    $hw_id    = intval($_POST['hardware_id'] ?? 0);
    $id_model = intval($_POST['id_model'] ?? 0);
    $name     = trim($_POST['switch_name'] ?? '');
    $ip       = trim($_POST['hardware_ip'] ?? '');
    $poe      = !empty($_POST['poe']) ? 1 : 0;
    $loc      = intval($_POST['locations_id'] ?? 0);
    $room     = intval($_POST['rooms_id'] ?? 0);
    $active   = !empty($_POST['active']) ? 1 : 0;
    $cred     = intval($_POST['cred_id'] ?? 0);

    if ($node_id <= 0)  { http_response_code(400); exit('Не передан узел'); }
    if ($id_model <= 0) { http_response_code(400); exit('Выберите модель'); }
    if ($name === '')   { http_response_code(400); exit('Укажите имя устройства'); }

    // узел существует на этой карте?
    $chk = $mysqli->prepare("SELECT id FROM map_nodes WHERE id = ? AND map_id = ?");
    $chk->bind_param("ii", $node_id, $MAP_ID);
    $chk->execute();
    if (!$chk->get_result()->fetch_row()) { http_response_code(404); exit('Узел не найден'); }
    $chk->close();

    if ($hw_id > 0) {
        // это железо уже привязано к другому узлу карты?
        $chk = $mysqli->prepare("SELECT COUNT(*) c FROM map_nodes
                                 WHERE map_id = ? AND hardware_id = ? AND id <> ?");
        $chk->bind_param("iii", $MAP_ID, $hw_id, $node_id);
        $chk->execute();
        if ((int)$chk->get_result()->fetch_assoc()['c'] > 0) {
            http_response_code(409);
            exit('Это устройство уже привязано к другому узлу карты');
        }
        $chk->close();
    }

    // nullable int — подставляем NULL литералом (bind_param отдаёт 0 вместо NULL)
    $locSql  = $loc  > 0 ? $loc  : 'NULL';
    $roomSql = $room > 0 ? $room : 'NULL';
    $credSql = $cred > 0 ? $cred : 'NULL';

    if ($hw_id > 0) {
        $stmt = $mysqli->prepare("UPDATE hardware SET id_model = ?, switch_name = ?, hardware_ip = ?,
                                  poe = ?, locations_id = $locSql, rooms_id = $roomSql,
                                  active = ?, cred_id = $credSql WHERE id = ?");
        $stmt->bind_param("issiii", $id_model, $name, $ip, $poe, $active, $hw_id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO hardware SET id_model = ?, switch_name = ?, hardware_ip = ?,
                                  poe = ?, locations_id = $locSql, rooms_id = $roomSql,
                                  active = ?, cred_id = $credSql");
        $stmt->bind_param("issii", $id_model, $name, $ip, $poe, $active);
    }
    $stmt->execute();
    if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }
    if ($hw_id <= 0) $hw_id = $mysqli->insert_id;
    $stmt->close();

    // привязка узла к устройству
    $stmt = $mysqli->prepare("UPDATE map_nodes SET hardware_id = ? WHERE id = ? AND map_id = ?");
    $stmt->bind_param("iii", $hw_id, $node_id, $MAP_ID);
    $stmt->execute();
    if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }

    maps_json(['ok' => 1, 'id' => $hw_id]);
}

if ($action === 'model_add') {
    $brand = intval($_POST['brand_id'] ?? 0);
    $type  = intval($_POST['model_type_id'] ?? 0);
    $name  = trim($_POST['model_name'] ?? '');
    if ($brand <= 0)  { http_response_code(400); exit('Выберите бренд'); }
    if ($name === '') { http_response_code(400); exit('Укажите название модели'); }
    $typeSql = $type > 0 ? $type : 'NULL';
    $stmt = $mysqli->prepare("INSERT INTO model (id_brand, model_type_id, model_name) VALUES (?, $typeSql, ?)");
    $stmt->bind_param("is", $brand, $name);
    $stmt->execute();
    if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }
    maps_json(['ok' => 1, 'id' => $mysqli->insert_id, 'name' => $name]);
}

if ($action === 'location_add') {
    $name = trim($_POST['name'] ?? '');
    $type = intval($_POST['type_id'] ?? 0);
    if ($name === '') { http_response_code(400); exit('Укажите название локации'); }
    // new_name — машинное имя транслитом, должно быть уникальным
    $base = maps_translit($name);
    if ($base === '') $base = 'loc';
    $new = $base; $i = 2;
    $chk = $mysqli->prepare("SELECT COUNT(*) c FROM locations WHERE new_name = ?");
    while (true) {
        $chk->bind_param("s", $new);
        $chk->execute();
        if ((int)$chk->get_result()->fetch_assoc()['c'] === 0) break;
        $new = $base . '_' . $i++;
    }
    $chk->close();
    $typeSql = $type > 0 ? $type : 'NULL';
    $stmt = $mysqli->prepare("INSERT INTO locations (name, new_name, type_id) VALUES (?, ?, $typeSql)");
    $stmt->bind_param("ss", $name, $new);
    $stmt->execute();
    if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }
    maps_json(['ok' => 1, 'id' => $mysqli->insert_id, 'name' => $name]);
}

if ($action === 'room_add') {
    $loc  = intval($_POST['locations_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($loc <= 0)    { http_response_code(400); exit('Сначала выберите локацию'); }
    if ($name === '') { http_response_code(400); exit('Укажите название помещения'); }
    $stmt = $mysqli->prepare("INSERT INTO locations_rooms (locations_id, name) VALUES (?, ?)");
    $stmt->bind_param("is", $loc, $name);
    $stmt->execute();
    if ($stmt->error) { http_response_code(500); exit('SQL: ' . $stmt->error); }
    maps_json(['ok' => 1, 'id' => $mysqli->insert_id, 'name' => $name]);
}

// ---------- КАРТЫ ----------

if ($action === 'save_monitoring') {
    // глобальный выключатель мониторинга (читает ping_monitor.py)
    // служебный флаг — живёт в app_settings
    $mysqli->query("CREATE TABLE IF NOT EXISTS app_settings (
        `name`  VARCHAR(64)  NOT NULL PRIMARY KEY,
        `value` VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $enabled = !empty($_POST['enabled']) ? '1' : '0';
    $stmt = $mysqli->prepare("INSERT INTO app_settings (name, value)
                              VALUES ('monitoring_enabled', ?)
                              ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $stmt->bind_param("s", $enabled);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    // флаг мониторинга влияет на cron-строку ping_monitor —
    // просим job_runner пересобрать crontab
    $mysqli->query("INSERT INTO app_settings (name, value)
                    VALUES ('crontab_dirty', '1')
                    ON DUPLICATE KEY UPDATE value = '1'");
    // момент включения: состояния, собранные ДО него, считаем устаревшими,
    // чтобы карта не «зеленела» мгновенно от старых данных
    if ($enabled === '1') {
        $mysqli->query("INSERT INTO app_settings (name, value)
                        VALUES ('monitoring_enabled_at', UNIX_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE value = UNIX_TIMESTAMP()");
    }
    exit('');
}

if ($action === 'save_settings') {
    // настройки отображения текущей карты
    $font_size = intval($_POST['font_size'] ?? 13);
    $icon_size = intval($_POST['icon_size'] ?? 24);
    $icon_img_max = intval($_POST['icon_img_max'] ?? 96);
    $show_model = !empty($_POST['show_model']) ? 1 : 0;
    $show_ip    = !empty($_POST['show_ip']) ? 1 : 0;
    $show_firmware = !empty($_POST['show_firmware']) ? 1 : 0;
    $show_uptime   = !empty($_POST['show_uptime']) ? 1 : 0;
    $show_temp     = !empty($_POST['show_temp']) ? 1 : 0;
    $show_comment  = !empty($_POST['show_comment']) ? 1 : 0;
    // разумные рамки, чтобы карту не разнести
    if ($font_size < 10) $font_size = 10;
    if ($font_size > 20) $font_size = 20;
    if ($icon_size < 14) $icon_size = 14;
    if ($icon_size > 48) $icon_size = 48;
    if ($icon_img_max < 48)  $icon_img_max = 48;
    if ($icon_img_max > 512) $icon_img_max = 512;

    $stmt = $mysqli->prepare("INSERT INTO map_settings
                              (map_id, font_size, icon_size, icon_img_max, show_model, show_ip, show_firmware, show_uptime, show_temp, show_comment)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE font_size = VALUES(font_size),
                                  icon_size = VALUES(icon_size), icon_img_max = VALUES(icon_img_max),
                                  show_model = VALUES(show_model),
                                  show_ip = VALUES(show_ip), show_firmware = VALUES(show_firmware),
                                  show_uptime = VALUES(show_uptime), show_temp = VALUES(show_temp),
                                  show_comment = VALUES(show_comment)");
    $stmt->bind_param("iiiiiiiiii", $MAP_ID, $font_size, $icon_size, $icon_img_max,
                      $show_model, $show_ip, $show_firmware, $show_uptime, $show_temp, $show_comment);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'save_style') {
    // стиль карты — один JSON; поля не из белого списка отбрасываем
    $raw = json_decode($_POST['style'] ?? '', true);
    if (!is_array($raw)) exit('Некорректный формат стиля');

    $pick = function ($arr, $keys) {
        $out = [];
        foreach ($keys as $k => $def) {
            $v = $arr[$k] ?? $def;
            $out[$k] = is_string($def) ? trim((string)$v) : $v;
        }
        return $out;
    };

    $style = [
        'block' => $pick($raw['block'] ?? [], [
            'font_size' => 13, 'header_h' => 24,
            'header_bg' => '#FFFFFF', 'header_text' => '#444444', 'border' => '#8a8a8a',
        ]),
        'selection' => $pick($raw['selection'] ?? [], [
            'color' => '#0d6efd', 'dash' => '6,4', 'padding' => 6,
        ]),
        'links' => $pick($raw['links'] ?? [], [
            'up' => '#45a049', 'down' => '#c62828', 'font_size' => 10, 'wp_size' => 4,
        ]),
        'icons' => $pick($raw['icons'] ?? [], [
            'neutral' => '#4A4A4A', 'up' => '#0E7A33', 'down' => '#A81F14',
        ]),
        'background' => $pick($raw['background'] ?? [], [
            'color' => '#B3B3B3', 'grid' => 1, 'image' => '', 'opacity' => 0.35,
        ]),
    ];
    // числовые поля в разумные рамки
    $style['block']['font_size'] = max(9, min(24, (int)$style['block']['font_size']));
    $style['links']['font_size'] = max(6, min(20, (int)$style['links']['font_size']));
    $style['links']['wp_size']   = max(2, min(15, (int)$style['links']['wp_size']));
    $style['block']['header_h']  = max(16, min(60, (int)$style['block']['header_h']));
    $style['selection']['padding'] = max(0, min(30, (int)$style['selection']['padding']));
    $style['background']['grid'] = !empty($style['background']['grid']) ? 1 : 0;
    $style['background']['opacity'] = max(0.05, min(1, (float)$style['background']['opacity']));

    $json = json_encode($style, JSON_UNESCAPED_UNICODE);
    $stmt = $mysqli->prepare("UPDATE map_settings SET style = ? WHERE map_id = ?");
    $stmt->bind_param("si", $json, $MAP_ID);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'add_map') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') exit('Укажите название карты');

    $stmt = $mysqli->prepare("INSERT INTO maps (name) VALUES (?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'rename_map') {
    $id   = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0) exit('Не передан ID карты');
    if ($name === '') exit('Укажите название карты');

    $stmt = $mysqli->prepare("UPDATE maps SET name = ? WHERE id = ?");
    $stmt->bind_param("si", $name, $id);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    exit('');
}

if ($action === 'del_map') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) exit('Не передан ID карты');

    // не даём удалить последнюю карту
    $cnt = (int)$mysqli->query("SELECT COUNT(*) c FROM maps")->fetch_assoc()['c'];
    if ($cnt <= 1) exit('Нельзя удалить последнюю карту');

    $mysqli->begin_transaction();
    try {
        // точки излома линков этой карты
        $stmt = $mysqli->prepare("DELETE p FROM map_link_points p
                                  JOIN map_links l ON l.id = p.link_id
                                  WHERE l.map_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $stmt = $mysqli->prepare("DELETE FROM map_links WHERE map_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $stmt = $mysqli->prepare("DELETE FROM map_nodes WHERE map_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $stmt = $mysqli->prepare("DELETE FROM map_groups WHERE map_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $stmt = $mysqli->prepare("DELETE FROM maps WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->error) throw new Exception('SQL: ' . $stmt->error);

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        exit($e->getMessage());
    }
    exit('');
}

exit('Неизвестное действие');

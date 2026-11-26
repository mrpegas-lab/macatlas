<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

// === Проверка прав ===
$oper = $_POST['oper'] ?? '';
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

// === Конфиг ===
$TABLE    = 'hardware';
$ID_FIELD = 'id';
$FIELDS = [
    'id_model'    => 'i',
    'switch_name' => 's',
    'hardware_ip' => 's',
    'poe'         => 'i',
    'locations_id'=> 'i',
    'rooms_id'    => 'i',
    'active'      => 'i',
    'cred_id'     => 'i'
];

$id = $_POST['id'] ?? '';

if ($oper) {
    if ($oper === 'del') {
        // === Удаление по цепочке (в транзакции) ===
        // Ручная очистка таблиц, где FK БЕЗ каскада:
        //   sw_port_info, sw_port_fdb (порты/FDB).
        // IPAM чистим каскадно вниз (решение владельца 2026-11-16):
        //   подсети VLAN'ов устройства (адреса и ipam_hist удалятся CASCADE),
        //   затем сами ipam_vlans (матрица связей — CASCADE).
        // Каскадные сами подчистятся: hw_ping_state, map_nodes;
        // ipam_addresses.hw_id — ON DELETE SET NULL; incidents — без FK (история остаётся).
        $id = (int)$id;

        // имя устройства нужно для очистки backup_device_status (device = switch_name)
        $stmt = $mysqli->prepare("SELECT switch_name FROM hardware WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $dev = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$dev) {
            http_response_code(404);
            exit('Устройство не найдено');
        }
        $devName = trim((string)($dev['switch_name'] ?? ''));

        $mysqli->begin_transaction();
        try {
            foreach (['sw_port_info' => 'id_switch', 'sw_port_fdb' => 'id_switch'] as $tbl => $col) {
                $stmt = $mysqli->prepare("DELETE FROM `$tbl` WHERE `$col` = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                if ($stmt->error) throw new Exception("очистка $tbl: " . $stmt->error);
                $stmt->close();
            }

            // IPAM: сначала подсети (иначе FK fk_ipam_subnet_vlan не даст удалить VLAN)
            $stmt = $mysqli->prepare(
                "DELETE s FROM ipam_subnets s
                 JOIN ipam_vlans v ON v.id = s.vlan_ref_id
                 WHERE v.hw_id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->error) throw new Exception("очистка ipam_subnets: " . $stmt->error);
            $stmt->close();

            $stmt = $mysqli->prepare("DELETE FROM ipam_vlans WHERE hw_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->error) throw new Exception("очистка ipam_vlans: " . $stmt->error);
            $stmt->close();

            // статус последнего бэкапа устройства (таблица может отсутствовать — миграция)
            if ($devName !== '') {
                $tchk = $mysqli->query("SHOW TABLES LIKE 'backup_device_status'");
                if ($tchk && $tchk->num_rows > 0) {
                    $stmt = $mysqli->prepare("DELETE FROM backup_device_status WHERE device = ? AND kind = 'hw'");
                    $stmt->bind_param('s', $devName);
                    $stmt->execute();
                    if ($stmt->error) throw new Exception("очистка backup_device_status: " . $stmt->error);
                    $stmt->close();
                }
            }

            $stmt = $mysqli->prepare("DELETE FROM hardware WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->error) throw new Exception("удаление устройства: " . $stmt->error);
            $stmt->close();

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            http_response_code(500);
            exit("Ошибка удаления: " . $e->getMessage());
        }

        exit; // успех = пустой ответ (иначе crud.js покажет текст как ошибку)
    }

    // === add / edit ===
    $values    = [];
    $set_parts = [];
    $types     = '';

    // Запоминаем прежнее состояние мониторинга (нужно для авто-закрытия инцидентов)
    $oldActive = null;
    if ($oper === 'edit') {
        $stmtA = $mysqli->prepare("SELECT active FROM hardware WHERE id = ?");
        $idInt = (int)$id;
        $stmtA->bind_param('i', $idInt);
        $stmtA->execute();
        $rowA = $stmtA->get_result()->fetch_assoc();
        $stmtA->close();
        $oldActive = $rowA ? (int)$rowA['active'] : null;
    }

    foreach ($FIELDS as $field => $type) {
        $value = $_POST[$field] ?? '';

        // Чекбоксы: если не пришло — значит 0
        if ($field === 'poe' || $field === 'active') {
            $value = isset($_POST[$field]) && $_POST[$field] == 1 ? 1 : 0;
        }

        // Пустые integer → NULL
        if ($value === '' && $type === 'i') {
            $value = null;
        }

        // '0' у ссылочных полей → NULL (записей с id=0 не существует,
        // иначе получаем ссылки-сироты — аудит 2026-11-23, С3)
        if ($value === '0' && in_array($field, ['id_model', 'locations_id', 'rooms_id', 'cred_id'], true)) {
            $value = null;
        }

        $values[]    = $value;
        $set_parts[] = "$field = ?";
        $types      .= $type;
    }

    $set_clause = implode(', ', $set_parts);

    if ($oper === 'add') {
        $sql = "INSERT INTO $TABLE SET $set_clause";
    }
    elseif ($oper === 'edit') {
        $sql      = "UPDATE $TABLE SET $set_clause WHERE $ID_FIELD = ?";
        $values[] = $id;
        $types   .= 'i';
    }
    else {
        exit('Неизвестная операция');
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) exit('SQL: ' . $mysqli->error);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    if ($stmt->error) exit('SQL: ' . $stmt->error);
    $stmt->close();

    // Вывод из мониторинга (active 1→0): закрыть открытые инциденты устройства,
    // чтобы «красная строка» не висела открытой в журнале после снятия галочки (2026-11-24).
    // Сбой здесь не должен ломать основное сохранение — ошибки глушим.
    $newActive = isset($_POST['active']) && $_POST['active'] == 1 ? 1 : 0;
    if ($oper === 'edit' && $oldActive === 1 && $newActive === 0) {
        $tchk = $mysqli->query("SHOW TABLES LIKE 'incidents'");
        if ($tchk && $tchk->num_rows > 0) {
            $idInt = (int)$id;
            $stmtI = $mysqli->prepare(
                "UPDATE incidents SET ended_at = NOW()
                 WHERE hardware_id = ? AND ended_at IS NULL"
            );
            if ($stmtI) {
                $stmtI->bind_param('i', $idInt);
                $stmtI->execute();
                $stmtI->close();
            }
        }
    }
}
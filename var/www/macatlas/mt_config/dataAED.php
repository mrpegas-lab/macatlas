<?php
include_once("../../loadinc.php");

$TABLE = 'mt_sites';
$ID_FIELD = 'id';

$FIELDS = [
    'locations_id'    => 'i',
    'group_id'        => 'i',  // <-- ДОБАВЛЕНО
    'usb_modem'       => 'i',
    'lte_router'      => 'i',
    'ip1'             => 's',
    'gw1'             => 's',
    'dns1'            => 's',
    'dhcpclient1'     => 'i',
    'ip2'             => 's',
    'gw2'             => 's',
    'dns2'            => 's',
    'dhcpclient2'     => 'i',
    'lan'             => 's',
    'ip_video'        => 's',
    'ip_ipip_salons'  => 's',
    'ip_ipip_router1' => 's',
    'routerid_vpn1'   => 's',
    'routerid_vpn2'   => 's',
    'lo'              => 's',
    'ip_wg'           => 's',
    'wg_private'      => 's',
    'wg_public'       => 's',
    'cred_id'         => 'i'
];

$id      = $_POST['id']      ?? '';
$oper    = $_POST['oper']    ?? '';
$comment = $_POST['comment'] ?? '';
$groupId = intval($_POST['group_id'] ?? 0);

// === Проверка прав ===
if ($oper === 'add'  && !$can_add)  { http_response_code(403); exit('Нет прав на добавление'); }
if ($oper === 'edit' && !$can_edit) { http_response_code(403); exit('Нет прав на редактирование'); }
if ($oper === 'del'  && !$can_del)  { http_response_code(403); exit('Нет прав на удаление'); }

if ($oper) {
    $values = [];
    $set_parts = [];
    $types = '';

    foreach ($FIELDS as $field => $type) {
        $value = $_POST[$field] ?? '';
        // Ссылочные поля: '' и '0' → NULL (записей с id=0 не бывает;
        // иначе mysqli молча кладёт 0 — ссылки-сироты, аудит 2026-11-23, С3).
        // Флаги (usb_modem, lte_router, dhcpclient*) не трогаем — там 0 легитимен.
        if (in_array($field, ['locations_id', 'group_id', 'cred_id'], true) && ($value === '' || $value === '0')) {
            $value = null;
        }
        $values[] = $value;
        $set_parts[] = "$field = ?";
        $types .= $type;
    }

    $set_clause = implode(', ', $set_parts);

    // Функция для резервирования конкретного IP
    $reserve = function($purpose, $ipStr, $bits, $siteId) use ($mysqli, $groupId) {
        if (empty($ipStr)) return;
        $pool = mt_find_pool($mysqli, $purpose, $groupId);
        if (!$pool) return;

        $ip = explode('/', $ipStr)[0];
        $netInt = ip2long($ip);
        if ($bits < 32) {
            $mask = ~((1 << (32 - $bits)) - 1);
            $netInt &= $mask;
        }
        $networkStr = mt_long2ip($netInt);

        $stmt = $mysqli->prepare("INSERT IGNORE INTO mt_pool_allocations (pool_id, network_ip, block_bits, site_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isii', $pool['id'], $networkStr, $bits, $siteId);
        $stmt->execute();
    };

    // Синхронизация аллокаций для всех полей
    $syncAllocations = function($siteId) use ($reserve) {
        $reserve('lan',        $_POST['lan'] ?? '',        24, $siteId);
        $reserve('ip_video',   $_POST['ip_video'] ?? '',   24, $siteId);
        $reserve('ipip',       $_POST['ip_ipip_salons'] ?? '', 31, $siteId);
        $reserve('lo',         $_POST['lo'] ?? '',         32, $siteId);
        $reserve('wg',         $_POST['ip_wg'] ?? '',      32, $siteId);
        $reserve('routerid1',  $_POST['routerid_vpn1'] ?? '', 32, $siteId);
        $reserve('routerid2',  $_POST['routerid_vpn2'] ?? '', 32, $siteId);
    };

    if ($oper == "add") {
        if (!$groupId) {
            exit("Ошибка: не выбрана группа (регион)");
        }

        // Одна локация — один объект: не даём создать второй на той же локации
        $locations_id = intval($_POST['locations_id'] ?? 0);
        if ($locations_id > 0) {
            $stmtChk = $mysqli->prepare("SELECT COUNT(*) c FROM $TABLE WHERE locations_id = ?");
            $stmtChk->bind_param("i", $locations_id);
            $stmtChk->execute();
            if ((int)$stmtChk->get_result()->fetch_assoc()['c'] > 0) {
                exit("Ошибка: к этой локации уже привязан объект");
            }
        }

        $mysqli->begin_transaction();
        try {
            $sql = "INSERT INTO $TABLE SET $set_clause";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $newSiteId = $mysqli->insert_id;

            // Обновляем locations (только комментарий) — как в edit
            if ($locations_id > 0) {
                $sql2 = "UPDATE locations SET comment = ? WHERE id = ?";
                $stmt2 = $mysqli->prepare($sql2);
                $stmt2->bind_param("si", $comment, $locations_id);
                $stmt2->execute();
            }

            $syncAllocations($newSiteId);

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            exit("Ошибка сохранения: " . $e->getMessage());
        }

    } elseif ($oper == "edit") {
        // Одна локация — один объект: не даём перепривязать запись
        // на локацию, где уже есть другой объект
        $locations_id = intval($_POST['locations_id'] ?? 0);
        if ($locations_id > 0) {
            $stmtChk = $mysqli->prepare("SELECT COUNT(*) c FROM $TABLE WHERE locations_id = ? AND id != ?");
            $stmtChk->bind_param("ii", $locations_id, $id);
            $stmtChk->execute();
            if ((int)$stmtChk->get_result()->fetch_assoc()['c'] > 0) {
                exit("Ошибка: к этой локации уже привязан другой объект.");
            }
        }

        $mysqli->begin_transaction();
        try {
            // 1. Обновляем mt_sites
            $sql = "UPDATE $TABLE SET $set_clause WHERE $ID_FIELD = ?";
            $values[] = $id;
            $types .= 'i';
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();

            // 2. Обновляем locations (только комментарий; статус из формы убран)
            $locations_id = intval($_POST['locations_id'] ?? 0);
            if ($locations_id > 0) {
                $sql2 = "UPDATE locations SET comment = ? WHERE id = ?";
                $stmt2 = $mysqli->prepare($sql2);
                $stmt2->bind_param("si", $comment, $locations_id);
                $stmt2->execute();
            }

            // 3. Синхронизируем аллокации: удаляем старые, ставим новые
            mt_release_site($mysqli, (int)$id);
            if ($groupId > 0) {
                $syncAllocations((int)$id);
            }

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            exit("Ошибка сохранения: " . $e->getMessage());
        }

    } elseif ($oper == "del") {
        // Данные объекта до удаления: локация + машинное имя
        // (new_name = hostname в mt_devices, device в backup_device_status,
        //  имя файлов бэкапов <new_name>_<14 цифр>.ext)
        $stmt = $mysqli->prepare(
            "SELECT s.locations_id, l.new_name
               FROM $TABLE s
               LEFT JOIN locations l ON l.id = s.locations_id
              WHERE s.$ID_FIELD = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $site = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$site) {
            http_response_code(404);
            exit("Объект не найден");
        }
        $locId   = (int)($site['locations_id'] ?? 0);
        $newName = trim((string)($site['new_name'] ?? ''));

        $mysqli->begin_transaction();
        try {
            // 1. Освобождаем IP из пулов
            mt_release_site($mysqli, (int)$id);

            // 2. Слоты mt_run (mt_run_slots без site_id — не трогаем)
            $stmt = $mysqli->prepare("DELETE FROM mt_run_slot_items WHERE site_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            // 3. Метки задач
            $stmt = $mysqli->prepare("DELETE FROM mt_task_marks WHERE site_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            // 4. История запусков сбора
            $stmt = $mysqli->prepare("DELETE FROM mt_history_items WHERE site_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            if ($newName !== '') {
                // 5. Статистика (hostname = new_name)
                $stmt = $mysqli->prepare("DELETE FROM mt_devices WHERE hostname = ?");
                $stmt->bind_param("s", $newName);
                $stmt->execute();
                $stmt->close();

                // 6. Статус бэкапа (таблица может отсутствовать — миграция)
                $tchk = $mysqli->query("SHOW TABLES LIKE 'backup_device_status'");
                if ($tchk && $tchk->num_rows > 0) {
                    $stmt = $mysqli->prepare("DELETE FROM backup_device_status WHERE device = ? AND kind = 'site'");
                    $stmt->bind_param("s", $newName);
                    $stmt->execute();
                    $stmt->close();
                }

                // 7. Реестр бэкапов: файлы этого объекта в его локации
                //    (mt_backup_diffs удалятся каскадом FK; файлы на диске не трогаем)
                if ($locId > 0) {
                    $re = '^' . preg_quote($newName) . '_[0-9]{14}\\.(rsc|backup|cfg)$';
                    $stmt = $mysqli->prepare(
                        "DELETE FROM mt_backups WHERE locations_id = ? AND file_name REGEXP ?"
                    );
                    $stmt->bind_param("is", $locId, $re);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            // 8. Сам объект
            $stmt = $mysqli->prepare("DELETE FROM $TABLE WHERE $ID_FIELD = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            exit("Ошибка удаления: " . $e->getMessage());
        }
    }
}
?>
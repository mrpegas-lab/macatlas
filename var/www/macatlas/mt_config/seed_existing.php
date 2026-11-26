<?php
include_once("../../loadinc.php");

header('Content-Type: text/html; charset=utf-8');

// === Проверка прав: пакетная перезапись данных — только с правом редактирования ===
if (!$can_edit) {
    exit('<div class="alert alert-danger m-3">Нет прав на редактирование</div>');
}

echo "<h2>Очистка базы и заполнение IP-пулов</h2>";

// 1. СТАТИСТИКА ДО ОЧИСТКИ
$totalBefore = $mysqli->query("SELECT COUNT(*) FROM mt_sites")->fetch_row()[0];
$emptyBefore = $mysqli->query("SELECT COUNT(*) FROM mt_sites WHERE locations_id IS NULL OR locations_id <= 0")->fetch_row()[0];

echo "<p>Всего записей в mt_sites: <b>$totalBefore</b></p>";
echo "<p>Мусорных записей (locations_id <= 0): <b style='color:red'>$emptyBefore</b></p>";

// 2. ОЧИСТКА (Удаляем болванки)
if ($emptyBefore > 0) {
    $mysqli->query("DELETE FROM mt_sites WHERE locations_id IS NULL OR locations_id <= 0");
    echo "<p>✅ Удалено мусорных записей: <b>$emptyBefore</b></p>";
} else {
    echo "<p>Мусорных записей не найдено.</p>";
}

// 3. ОБНУЛЕНИЕ АЛЛОКАЦИЙ (очищаем всё, что было, чтобы заполнить заново начисто)
$mysqli->query("TRUNCATE TABLE mt_pool_allocations");
echo "<p>Таблица mt_pool_allocations очищена.</p>";

// 4. ЗАПОЛНЕНИЕ ПО ЖИВЫМ САЛОНАМ
echo "<h3>Заполнение пулов по живым салонам...</h3>";

$sites = $mysqli->query("
    SELECT id, group_id, lan, ip_video, ip_ipip_salons, ip_ipip_router1, lo, ip_wg, routerid_vpn1, routerid_vpn2 
    FROM mt_sites 
    WHERE locations_id > 0 AND group_id IS NOT NULL
");

$count = 0;

while ($s = $sites->fetch_assoc()) {
    $siteId = (int)$s['id'];
    $groupId = (int)$s['group_id'];

    // Вспомогательная функция для вставки
    $insert = function($pool, $ipStr, $bits) use ($mysqli, $siteId) {
        if (empty($ipStr)) return;
        
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

    // LAN (/24)
    $p = mt_find_pool($mysqli, 'lan', $groupId);
    if ($p) $insert($p, $s['lan'], 24);

    // Video (/24)
    $p = mt_find_pool($mysqli, 'ip_video', $groupId);
    if ($p) $insert($p, $s['ip_video'], 24);

    // IPIP (/31) - берем ip_ipip_salons
    $p = mt_find_pool($mysqli, 'ipip', null);
    if ($p) $insert($p, $s['ip_ipip_salons'], 31);

    // LO (/32)
    $p = mt_find_pool($mysqli, 'lo', null);
    if ($p) $insert($p, $s['lo'], 32);

    // WG (/32) - в БД там маска /20, но для пула храним как /32
    $p = mt_find_pool($mysqli, 'wg', null);
    if ($p) $insert($p, $s['ip_wg'], 32);

    // RouterID VPN 1 (/32)
    $p = mt_find_pool($mysqli, 'routerid1', null);
    if ($p) $insert($p, $s['routerid_vpn1'], 32);

    // RouterID VPN 2 (/32)
    $p = mt_find_pool($mysqli, 'routerid2', null);
    if ($p) $insert($p, $s['routerid_vpn2'], 32);

    $count++;
}

$totalAlloc = $mysqli->query("SELECT COUNT(*) FROM mt_pool_allocations")->fetch_row()[0];

echo "<p>Обработано живых салонов: <b>$count</b></p>";
echo "<p>Записано занятых блоков в пулы: <b>$totalAlloc</b></p>";
echo "<h3 style='color:green'>✅ Готово!</h3>";
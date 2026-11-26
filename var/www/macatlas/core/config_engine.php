<?php
/**
 * Единый движок генерации конфигурации для MikroTik.
 * Используется в mt_run (render_config.php) и mt_config (generate_config.php).
 */

// 1. Вспомогательные функции вычислений
function mt_calc_network($cidr) {
    if (strpos($cidr, '/') === false) return $cidr;
    list($ip, $mask_bits) = explode('/', $cidr);
    $ip_long = ip2long($ip);
    if ($ip_long === false) return $cidr;
    $mask_bits = (int)$mask_bits;
    $mask_long = ~((1 << (32 - $mask_bits)) - 1);
    if (PHP_INT_SIZE == 4) $mask_long = (float)$mask_long;
    $network_long = $ip_long & $mask_long;
    return long2ip($network_long) . '/' . $mask_bits;
}

function mt_calc_dhcp_pool($cidr) {
    $parts = explode('/', $cidr);
    if (count($parts) !== 2) return null;
    $ip = $parts[0];
    $mask = (int)$parts[1];
    if ($mask < 0 || $mask > 32) return null;
    $ipLong = ip2long($ip);
    if ($ipLong === false) return null;
    $ipCount = pow(2, 32 - $mask);
    $networkLong = $ipLong & (~($ipCount - 1));
    $broadcastLong = $networkLong + $ipCount - 1;
    $firstPool = $networkLong + 2;
    $lastPool  = $broadcastLong - 1;
    
    if ($firstPool > $lastPool) {
        if ($ipCount <= 4) return "Сеть слишком маленькая для DHCP пула (нужно минимум /30 с резервированием шлюза)";
        return "Нет доступных адресов для пула";
    }
    return long2ip($firstPool) . '-' . long2ip($lastPool);
}

// 2. Обработка условий
function mt_process_conditions($template, $conditions) {
    $pattern = '/#{(\w+):([A-Z0-9_]+)\s*}\R?(.*?)#{\/if}/s';
    $template = preg_replace_callback($pattern, function ($m) use ($conditions) {
        $mode      = $m[1];
        $condName  = $m[2];
        $block     = $m[3];
        $condMet   = !empty($conditions[$condName]);
        $show = ($mode === 'show' && $condMet) || ($mode === 'hide' && !$condMet);
        return $show ? $block : '';
    }, $template);
    return preg_replace("/\R{3,}/", "\n\n", $template);
}

// 3. Данные из БД
function mt_get_site_row($mysqli, $siteId) {
    $stmt = $mysqli->prepare(
        "SELECT ms.*, l.new_name FROM mt_sites ms
           LEFT JOIN locations l ON l.id = ms.locations_id
          WHERE ms.id = ?
          LIMIT 1"
    );
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// 4. Главная функция рендера
function mt_generate_config($mysqli, $siteId, $template) {
    $row = mt_get_site_row($mysqli, $siteId);
    if (!$row) return $template;

    $configData = [
        'NAME'            => $row['new_name'] ?? '',
        'DHCP_CLIENT1'    => $row['dhcpclient1'] ?? '',
        'WAN_IP1'         => $row['ip1'] ?? '',
        'WAN_IP_NOMASK1'  => isset($row['ip1']) ? explode('/', $row['ip1'])[0] : '',
        'GATEWAY1'        => $row['gw1'] ?? '',
        'DNS1'            => $row['dns1'] ?? '',
        'DHCP_CLIENT2'    => $row['dhcpclient2'] ?? '',
        'WAN_IP2'         => $row['ip2'] ?? '',
        'WAN_IP_NOMASK2'  => isset($row['ip2']) ? explode('/', $row['ip2'])[0] : '',
        'GATEWAY2'        => $row['gw2'] ?? '',
        'DNS2'            => $row['dns2'] ?? '',
        'LAN_IP'          => $row['lan'] ?? '',
        'LAN_MASK'        => isset($row['lan']) ? explode('/', $row['lan'])[1] : '',
        'LAN_NETWORK'     => isset($row['lan']) ? mt_calc_network($row['lan']) : '',
        'LAN_IP_NOMASK'   => isset($row['lan']) ? explode('/', $row['lan'])[0] : '',
        'POOL'            => isset($row['lan']) ? mt_calc_dhcp_pool($row['lan']) : '',
        'VIDEO_IP'        => $row['ip_video'] ?? '',
        'VIDEO_NETWORK'   => isset($row['ip_video']) ? mt_calc_network($row['ip_video']) : '',
        'IPIP_ADDRESS'    => $row['ip_ipip_salons'] ?? '',
        'WG_PUBLIC'       => $row['wg_public'] ?? '',
        'WG_PRIVATE'      => $row['wg_private'] ?? '',
        'WG_IP'           => $row['ip_wg'] ?? '',
        'SSTP_PASSWORD'   => $row['sstp_password'] ?? '',
        'ROUTER_ID1'      => $row['routerid_vpn1'] ?? '',
        'ROUTER_ID2'      => $row['routerid_vpn2'] ?? '',
        'LO_IP'           => $row['lo'] ?? '',
    ];

    $conditions = [
        'HAS_SSTP'     => !empty($row['sstp']),    
        'HAS_WAN_IP1'  => !empty($row['ip1']),
        'HAS_WAN_IP2'  => !empty($row['ip2']),
        'DHCP_CLIENT1' => !empty($row['dhcpclient1']),
        'DHCP_CLIENT2' => !empty($row['dhcpclient2']),
    ];

    $template = mt_process_conditions($template, $conditions);

    foreach ($configData as $key => $value) {
        $template = str_replace('{' . $key . '}', (string)$value, $template);
    }

    $out = trim($template) . "\n";
    return $out;
}

// 5. Алиасы для совместимости со старыми названиями функций
if (!function_exists('getNetworkWithMask')) {
    function getNetworkWithMask($cidr) { return mt_calc_network($cidr); }
}
if (!function_exists('cidrToDhcpPool')) {
    function cidrToDhcpPool($cidr) { return mt_calc_dhcp_pool($cidr); }
}
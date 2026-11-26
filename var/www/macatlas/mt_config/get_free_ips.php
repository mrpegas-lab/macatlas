<?php
include_once("../../loadinc.php");

header('Content-Type: application/json');

// === Проверка прав: резервирование IP из пулов — только с правом редактирования ===
if (!$can_edit) {
    echo json_encode(['error' => 'Нет прав на редактирование'], JSON_UNESCAPED_UNICODE);
    exit;
}

$groupId = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
if (!$groupId) {
    echo json_encode(['error' => 'Не указана группа']);
    exit;
}

$result = [];

$fields = [
    'lan'             => ['purpose' => 'lan',       'bits' => 24, 'suffix' => '/24'],
    'ip_video'        => ['purpose' => 'ip_video',  'bits' => 24, 'suffix' => '/24'],
    'ip_ipip_salons'  => ['purpose' => 'ipip',      'bits' => 31, 'suffix' => '/31'],
    'ip_ipip_router1' => ['purpose' => 'ipip',      'bits' => 31, 'suffix' => '/31'],
    'lo'              => ['purpose' => 'lo',        'bits' => 32, 'suffix' => ''],
    'ip_wg'           => ['purpose' => 'wg',        'bits' => 32, 'suffix' => '/20'],
    'routerid_vpn1'   => ['purpose' => 'routerid1', 'bits' => 32, 'suffix' => ''],
    'routerid_vpn2'   => ['purpose' => 'routerid2', 'bits' => 32, 'suffix' => ''],
];

foreach ($fields as $field => $cfg) {
    $pool = mt_find_pool($mysqli, $cfg['purpose'], $groupId);
    if (!$pool) {
        $result[$field] = '';
        continue;
    }

    $netInt = ip2long($pool['network']);
    $step   = 1 << (32 - $pool['step_bits']);
    $total  = (1 << (32 - $pool['prefix'])) / $step;

    $stmt = $mysqli->prepare("SELECT network_ip FROM mt_pool_allocations WHERE pool_id = ?");
    $stmt->bind_param('i', $pool['id']);
    $stmt->execute();
    $taken = array_flip(array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'network_ip'));

    $found = false;
    for ($i = 0; $i < $total; $i++) {
        $blockNet = $netInt + ($i * $step);
        if (isset($taken[mt_long2ip($blockNet)])) continue;

        $ips = mt_describe_block($blockNet, $cfg['bits']);
        if ($field === 'ip_ipip_salons') {
            $result['ip_ipip_salons']  = $ips[0] . $cfg['suffix'];
            $result['ip_ipip_router1'] = $ips[1] . $cfg['suffix'];
        } elseif ($field === 'ip_ipip_router1') {
            // Уже заполнено выше
        } else {
            $result[$field] = $ips[0] . $cfg['suffix'];
        }
        $found = true;
        break;
    }
    if (!$found) $result[$field] = '';
}

// === ВЫЗОВ PYTHON ДЛЯ ГЕНЕРАЦИИ WG КЛЮЧЕЙ ===
$pythonScript = __DIR__ . '/wireguard_gen.py'; // Путь к файлу
$pythonCmd = 'python3 ' . escapeshellarg($pythonScript) . ' 2>&1'; // Или 'python' вместо 'python3' на Windows
$pythonOutput = shell_exec($pythonCmd);

if ($pythonOutput) {
    $wgKeys = json_decode($pythonOutput, true);
    if ($wgKeys && isset($wgKeys['private'])) {
        $result['wg_private'] = $wgKeys['private'];
        $result['wg_public']  = $wgKeys['public'];
    } else {
        // Если парсинг не удался, вернем пустые ключи
        $result['wg_private'] = '';
        $result['wg_public']  = '';
    }
} else {
    $result['wg_private'] = '';
    $result['wg_public']  = '';
}

echo json_encode($result);
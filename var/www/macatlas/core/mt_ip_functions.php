<?php

/**
 * Ищет пул по назначению и группе.
 */
function mt_find_pool(mysqli $db, string $purpose, ?int $groupId): ?array
{
    if ($groupId !== null) {
        $stmt = $db->prepare(
            "SELECT * FROM mt_pool_ranges 
             WHERE purpose = ? AND group_id = ? AND enabled = 1 
             ORDER BY priority ASC LIMIT 1"
        );
        $stmt->bind_param('si', $purpose, $groupId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res) return mt_parse_pool($res);
    }

    $stmt = $db->prepare(
        "SELECT * FROM mt_pool_ranges 
         WHERE purpose = ? AND group_id IS NULL AND enabled = 1 
         ORDER BY priority ASC LIMIT 1"
    );
    $stmt->bind_param('s', $purpose);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    return $res ? mt_parse_pool($res) : null;
}

/**
 * Ищет первый свободный блок и записывает его в БД как строку.
 */
function mt_allocate_ip(mysqli $db, string $purpose, ?int $groupId, int $siteId): array
{
    $pool = mt_find_pool($db, $purpose, $groupId);
    if (!$pool) {
        throw new RuntimeException("Не найден пул для '$purpose'");
    }

    $netInt = ip2long($pool['network']);
    $step   = 1 << (32 - $pool['step_bits']);
    $total  = (1 << (32 - $pool['prefix'])) / $step;

    // Занятые блоки
    $stmt = $db->prepare("SELECT network_ip FROM mt_pool_allocations WHERE pool_id = ?");
    $stmt->bind_param('i', $pool['id']);
    $stmt->execute();
    $taken = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $taken[] = ip2long($row['network_ip']);
    }
    $taken = array_flip($taken);

    for ($i = 0; $i < $total; $i++) {
        $blockNet = $netInt + ($i * $step);
        
        // Получаем строковое представление блока
        $blockIpStr = mt_long2ip($blockNet);
        
        // ПРОВЕРКА: если этот блок — это .0 для своей подсети, мы его пропускаем
        // (кроме случаев /31, где мы уже обработали сдвиг)
        if ($pool['step_bits'] !== 31) {
            if (substr($blockIpStr, -2) === '.0') {
                // Для /32 или /24 пропускаем нулевой блок
                continue; 
            }
        } else {
            // Для /31, если блок начинается на .0, нам нужно проверить следующий
            // (фактически, мы можем просто пропустить .0, если хотим строго)
            // Но лучше использовать mt_describe_block, который вернет правильные.
        }

        if (isset($taken[$blockNet])) continue;

        // Нашли свободный — записываем
        $bits = $pool['step_bits'];
        $networkStr = $blockIpStr; 
        
        // ВАЖНО: для /31, если мы взяли блок с .0, но выдали .1 и .2, 
        // то в базе мы должны хранить сетевой адрес этого блока (то есть .0), 
        // чтобы знать, что он занят. Но выдавать мы будем .1 и .2.
        
        $stmt = $db->prepare(
            "INSERT INTO mt_pool_allocations (pool_id, network_ip, block_bits, site_id) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('isii', $pool['id'], $networkStr, $bits, $siteId);
        $stmt->execute();

        return [
            'block_start' => $blockNet,
            'bits'        => $bits,
            'ips'         => mt_describe_block($blockNet, $bits),
            'pool_name'   => $pool['name']
        ];
    }

    throw new RuntimeException("Пул '{$pool['name']}' исчерпан");
}

/**
 * Удаляет занятые IP при удалении салона.
 */
function mt_release_site(mysqli $db, int $siteId): void
{
    $stmt = $db->prepare("DELETE FROM mt_pool_allocations WHERE site_id = ?");
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
}

// --- Вспомогательные ---

function mt_parse_pool(array $row): array
{
    [$network, $prefix] = explode('/', $row['cidr']);
    return [
        'id'        => (int)$row['id'],
        'name'      => $row['name'],
        'network'   => $network,
        'prefix'    => (int)$prefix,
        'step_bits' => (int)$row['step_bits'],
    ];
}

function mt_describe_block(int $netInt, int $bits): array
{
    $ips = [];

    if ($bits === 31) {
        // Для /31 сетевой адрес — это чётный (оканчивается на 0).
        // Хостовые адреса: нечётный (.1, .3) и следующий чётный (.2, .4).
        // Нам нужно вернуть [нечётный, чётный].
        $ip1 = mt_long2ip($netInt);
        $ip2 = mt_long2ip($netInt + 1);
        
        if (substr($ip1, -2) === '.0') {
            // Если блок начинается с .0 (например 10.252.0.0/31),
            // то .0 — сетевой, .1 — хостовый. Берем .1 и .2.
            $ips[] = $ip2;                   // .1
            $ips[] = mt_long2ip($netInt + 2); // .2
        } else {
            // Если блок уже начинается с .1 (10.252.0.1/31),
            // то .1 и .2 — хостовые.
            $ips[] = $ip1;
            $ips[] = $ip2;
        }
    } elseif ($bits === 32) {
        // Для /32 сетевой адрес — это сам адрес, если он заканчивается на .0 в рамках /24.
        $ip = mt_long2ip($netInt);
        if (substr($ip, -2) === '.0') {
            // Пропускаем .0, берем следующий
            $ips[] = mt_long2ip($netInt + 1);
        } else {
            $ips[] = $ip;
        }
    } else {
        // Для /24 и меньше: сетевой адрес — это самый первый (нулевой) адрес.
        // Хостовые адреса идут от .1 до (broadcast - 1).
        // Но здесь есть нюанс: если мы запрашиваем /24, то $netInt - это .0.
        // Значит, берем от $netInt + 1.
        $count = 1 << (32 - $bits);
        for ($k = 1; $k < $count - 1; $k++) {
            $ips[] = mt_long2ip($netInt + $k);
        }
    }
    
    return $ips;
}

function mt_long2ip(int $long): string
{
    // Защита от отрицательных чисел в 32-битных системах
    return long2ip($long < 0 ? $long + 4294967296 : $long);
}
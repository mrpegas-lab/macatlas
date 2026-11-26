<?php
// /mod/mikrotik_stats/index.php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23)
if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}


$stats = [];

// 1. Общая статистика по MikroTik устройствам
$query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as devices_ok,
    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as devices_error,
    COUNT(DISTINCT mac) as unique_mac,
    COUNT(DISTINCT serial_number) as unique_serial
FROM mt_devices 
WHERE last_check > DATE_SUB(NOW(), INTERVAL 30 DAY)";
$result = $mysqli->query($query);
$stats['total'] = $result->fetch_assoc();

// 2. Статистика по моделям MikroTik
$query = "SELECT 
    model,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100 / (SELECT COUNT(*) FROM mt_devices), 1) as percentage
FROM mt_devices 
WHERE model IS NOT NULL AND model != '0'
GROUP BY model 
ORDER BY count DESC";
$result = $mysqli->query($query);
$stats['models'] = [];
while ($row = $result->fetch_assoc()) {
    $stats['models'][] = $row;
}

// 3. Статистика по board_name
$query = "SELECT 
    board_name,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100 / (SELECT COUNT(*) FROM mt_devices), 1) as percentage
FROM mt_devices 
WHERE board_name IS NOT NULL
GROUP BY board_name 
ORDER BY count DESC";
$result = $mysqli->query($query);
$stats['board_names'] = [];
while ($row = $result->fetch_assoc()) {
    $stats['board_names'][] = $row;
}

// 4. Статистика по версиям RouterOS
$query = "SELECT 
    version,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100 / (SELECT COUNT(*) FROM mt_devices), 1) as percentage
FROM mt_devices 
WHERE version IS NOT NULL
GROUP BY version 
ORDER BY version DESC";
$result = $mysqli->query($query);
$stats['versions'] = [];
while ($row = $result->fetch_assoc()) {
    $stats['versions'][] = $row;
}

// 5. Статистика по прошивкам current_firmware
$query = "SELECT 
    current_firmware,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100 / (SELECT COUNT(*) FROM mt_devices), 1) as percentage
FROM mt_devices 
WHERE current_firmware IS NOT NULL
GROUP BY current_firmware 
ORDER BY count DESC";
$result = $mysqli->query($query);
$stats['firmwares'] = [];
while ($row = $result->fetch_assoc()) {
    $stats['firmwares'][] = $row;
}

// 7. Статистика по количеству ядер CPU
$query = "SELECT 
    cpu_count,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100 / (SELECT COUNT(*) FROM mt_devices), 1) as percentage
FROM mt_devices 
WHERE cpu_count IS NOT NULL
GROUP BY cpu_count 
ORDER BY cpu_count ASC";
$result = $mysqli->query($query);
$stats['cpu_cores'] = [];
while ($row = $result->fetch_assoc()) {
    $stats['cpu_cores'][] = $row;
}

// 8. Статистика по частоте CPU
$query = "SELECT 
    cpu_frequency,
    COUNT(*) as count
FROM mt_devices 
WHERE cpu_frequency IS NOT NULL
GROUP BY cpu_frequency 
ORDER BY cpu_frequency ASC";
$result = $mysqli->query($query);
$stats['cpu_freq'] = [];
while ($row = $result->fetch_assoc()) {
    $stats['cpu_freq'][] = $row;
}
?>

<div class="row">
    <div class="col-md-3">
        <div class="stat-card bg-primary text-white">
            <div class="stat-number"><?= $stats['total']['total'] ?? 0 ?></div>
            <div class="stat-label">Всего устройств MikroTik</div>
            <small class="text-white-80">Успешно: <?= $stats['total']['devices_ok'] ?? 0 ?> | Ошибок: <?= $stats['total']['devices_error'] ?? 0 ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card bg-success text-white">
            <div class="stat-number"><?= $stats['total']['unique_mac'] ?? 0 ?></div>
            <div class="stat-label">Уникальных MAC адресов</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card bg-info text-white">
            <div class="stat-number"><?= $stats['total']['unique_serial'] ?? 0 ?></div>
            <div class="stat-label">Уникальных серийных номеров</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card bg-dark-card">
            <div class="stat-number"><?= count($stats['models']) ?></div>
            <div class="stat-label">Различных моделей</div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6">
        <div class="card card-compact">
            <div class="card-header card-header-compact bg-light">
                <i class="fas fa-microchip me-2"></i> Модели устройств MikroTik
            </div>
            <div class="card-body card-body-compact">
                <table class="table table-compact table-hover">
                    <thead>
                        <tr>
                            <th>Модель</th>
                            <th>Кол-во</th>
                            <th>Доля</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['models'] as $model): ?>
                        <tr class="stat-row" data-type="model" data-value="<?= htmlspecialchars($model['model']) ?>">
                            <td><?= htmlspecialchars($model['model']) ?></td>
                            <td><?= $model['count'] ?></td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?= $model['percentage'] ?>%">
                                        <?= $model['percentage'] ?>%
                                    </div>
                                </div>
                             </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card card-compact">
            <div class="card-header card-header-compact bg-light">
                <i class="fas fa-microchip me-2"></i> Board Name MikroTik
            </div>
            <div class="card-body card-body-compact">
                <table class="table table-compact table-hover">
                    <thead>
                        <tr>
                            <th>Board Name</th>
                            <th>Кол-во</th>
                            <th>Доля</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['board_names'] as $board): ?>
                        <tr class="stat-row" data-type="board_name" data-value="<?= htmlspecialchars($board['board_name']) ?>">
                            <td><?= htmlspecialchars($board['board_name']) ?></td>
                            <td><?= $board['count'] ?></td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?= $board['percentage'] ?>%">
                                        <?= $board['percentage'] ?>%
                                    </div>
                                </div>
                             </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card card-compact">
            <div class="card-header card-header-compact bg-light">
                <i class="fab fa-linux me-2"></i> Версии RouterOS
            </div>
            <div class="card-body card-body-compact">
                <table class="table table-compact table-hover">
                    <thead>
                        <tr>
                            <th>Версия</th>
                            <th>Кол-во</th>
                            <th>Доля</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['versions'] as $version): ?>
                        <tr class="stat-row" data-type="version" data-value="<?= htmlspecialchars($version['version']) ?>">
                            <td><?= htmlspecialchars($version['version']) ?></td>
                            <td><?= $version['count'] ?></td>
                            <td><?= $version['percentage'] ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card card-compact">
            <div class="card-header card-header-compact bg-light">
                <i class="fas fa-code-branch me-2"></i> Текущие прошивки (current_firmware)
            </div>
            <div class="card-body card-body-compact">
                <table class="table table-compact table-hover">
                    <thead>
                        <tr>
                            <th>Прошивка</th>
                            <th>Кол-во</th>
                            <th>Доля</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['firmwares'] as $firmware): ?>
                        <tr class="stat-row" data-type="current_firmware" data-value="<?= htmlspecialchars($firmware['current_firmware']) ?>">
                            <td><?= htmlspecialchars($firmware['current_firmware']) ?></td>
                            <td><?= $firmware['count'] ?></td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?= $firmware['percentage'] ?>%">
                                        <?= $firmware['percentage'] ?>%
                                    </div>
                                </div>
                             </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card card-compact">
            <div class="card-header card-header-compact bg-light">
                <i class="fas fa-microchip me-2"></i> Количество ядер CPU
            </div>
            <div class="card-body card-body-compact">
                <table class="table table-compact table-hover">
                    <thead>
                        <tr>
                            <th>Ядер</th>
                            <th>Кол-во</th>
                            <th>Доля</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['cpu_cores'] as $cores): ?>
                        <tr class="stat-row" data-type="cpu_count" data-value="<?= htmlspecialchars($cores['cpu_count']) ?>">
                            <td><?= $cores['cpu_count'] == 1 ? 'Одноядерные' : ($cores['cpu_count'] == 2 ? 'Двухядерные' : ($cores['cpu_count'] == 4 ? 'Четырехядерные' : $cores['cpu_count'] . ' ядра')) ?></td>
                            <td><?= $cores['count'] ?></td>
                            <td><?= $cores['percentage'] ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card card-compact">
            <div class="card-header card-header-compact bg-light">
                <i class="fas fa-tachometer-alt me-2"></i> Частота CPU (MHz)
            </div>
            <div class="card-body card-body-compact">
                <table class="table table-compact table-hover">
                    <thead>
                        <tr>
                            <th>Частота</th>
                            <th>Кол-во</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['cpu_freq'] as $freq): ?>
                        <tr class="stat-row" data-type="cpu_frequency" data-value="<?= htmlspecialchars($freq['cpu_frequency']) ?>">
                            <td><?= htmlspecialchars($freq['cpu_frequency']) ?> MHz</td>
                            <td><?= $freq['count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ДЕТАЛИЗАЦИЯ ПО КЛИКУ -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card card-compact">
            <div class="card-header card-header-compact bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list me-2"></i> Детализация <span id="details-title" class="text-white-80"></span></span>
                <button type="button" class="btn btn-sm btn-outline-light" id="details-clear" style="display:none;">
                    <i class="bi bi-x-lg"></i> Сброс
                </button>
            </div>
            <div class="card-body card-body-compact">
                <div class="table-responsive">
                    <table class="table table-compact table-hover" id="details-table">
                        <thead>
                            <tr>
                                <th>Хост</th>
                                <th>IP</th>
                                <th>Модель</th>
                                <th>Board</th>
                                <th>Версия ROS</th>
                                <th>Прошивка</th>
                                <th>Uptime</th>
                                <th>Последний сбор</th>
                            </tr>
                        </thead>
                        <tbody id="details-body">
                            <tr><td colspan="8" class="text-muted text-center">Кликните по строке в любой таблице выше, чтобы увидеть устройства</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var detailsBody  = document.getElementById('details-body');
    var detailsTitle = document.getElementById('details-title');
    var clearBtn     = document.getElementById('details-clear');

    var typeLabels = {
        model: 'Модель',
        board_name: 'Board Name',
        version: 'Версия RouterOS',
        current_firmware: 'Прошивка',
        cpu_count: 'Ядер CPU',
        cpu_frequency: 'Частота CPU'
    };

    document.querySelectorAll('.stat-row').forEach(function (tr) {
        tr.addEventListener('click', function () {
            var type  = this.dataset.type;
            var value = this.dataset.value;

            detailsTitle.textContent = '— ' + (typeLabels[type] || type) + ': ' + value;
            clearBtn.style.display = 'inline-block';
            detailsBody.innerHTML = '<tr><td colspan="8" class="text-muted text-center">Загрузка...</td></tr>';

            var fd = new FormData();
            fd.append('type', type);
            fd.append('value', value);

            fetch('/mod/mt_stat/details.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    var rows = resp.data || [];
                    if (!rows.length) {
                        detailsBody.innerHTML = '<tr><td colspan="8" class="text-muted text-center">Нет устройств</td></tr>';
                        return;
                    }
                    var html = '';
                    rows.forEach(function (d) {
                        html += '<tr>'
                            + '<td>' + esc(d.hostname) + '</td>'
                            + '<td>' + esc(d.ip) + '</td>'
                            + '<td>' + esc(d.model) + '</td>'
                            + '<td>' + esc(d.board_name) + '</td>'
                            + '<td>' + esc(d.version) + '</td>'
                            + '<td>' + esc(d.current_firmware) + '</td>'
                            + '<td>' + esc(d.uptime) + '</td>'
                            + '<td>' + esc(d.last_check) + '</td>'
                            + '</tr>';
                    });
                    detailsBody.innerHTML = html;
                })
                .catch(function () {
                    detailsBody.innerHTML = '<tr><td colspan="8" class="text-danger text-center">Ошибка загрузки</td></tr>';
                });
        });
    });

    clearBtn.addEventListener('click', function () {
        detailsTitle.textContent = '';
        clearBtn.style.display = 'none';
        detailsBody.innerHTML = '<tr><td colspan="8" class="text-muted text-center">Кликните по строке в любой таблице выше, чтобы увидеть устройства</td></tr>';
    });

    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
</script>
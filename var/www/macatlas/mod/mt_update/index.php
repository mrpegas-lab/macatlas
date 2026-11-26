<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
// Право на выполнение передаём в JS, чтобы кнопки без прав были неактивны
?>
<script>var CAN_EXEC = <?= $can_exec ? 'true' : 'false' ?>;</script>

<div class="container-fluid py-3">
    
    <!-- ВЕРХНЯЯ ПАНЕЛЬ: Заголовок + Версия + Статистика + Фильтр -->
    <div class="panel mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding: 8px 12px;">
        <div class="d-flex align-items-center gap-3">
            <h5 class="mb-0 fw-bold">⬆️ Обновление MikroTik</h5>
            <span id="target-version-info" class="text-muted small"></span>
                <span class="text-muted small border-start ps-3 ms-2 border-secondary d-inline-flex gap-3 flex-wrap">
                <span>Всего: <b id="stat-total">0</b></span>
                <span>Устар: <b id="stat-old" class="text-warning">0</b></span>
                <span>Ок: <b id="stat-ok" class="text-success">0</b></span>
                <span>Показано: <b id="stat-visible">0</b></span>
            </span>
        </div>
        <div>
            <input type="text" id="filter-input" class="form-control form-control-sm" style="width:250px;" placeholder="Поиск по имени/IP..." oninput="applyFilter()">
        </div>
    </div>

    <div class="cmd-layout">
        <!-- ЛЕВАЯ КОЛОНКА: УПРАВЛЕНИЕ + ЛОГ -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <!-- Фильтры статуса -->
                <div class="btn-group btn-group-sm w-100 mb-2" role="group">
                    <input type="radio" class="btn-check" name="status-filter" id="sf-all" value="all" checked onchange="applyStatusFilter()">
                    <label class="btn btn-outline-secondary py-1" for="sf-all">Все</label>
                    <input type="radio" class="btn-check" name="status-filter" id="sf-old" value="old" onchange="applyStatusFilter()">
                    <label class="btn btn-outline-secondary py-1" for="sf-old">Устаревшие</label>
                    <input type="radio" class="btn-check" name="status-filter" id="sf-ok" value="ok" onchange="applyStatusFilter()">
                    <label class="btn btn-outline-secondary py-1" for="sf-ok">Актуальные</label>
                </div>

                <!-- Кнопки выбора -->
                <div class="d-flex gap-2 mb-2">
                    <button class="btn btn-sm btn-outline-secondary flex-fill py-1" onclick="selectOutdated()">Выбрать старые</button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill py-1" onclick="clearSelection()">Снять</button>
                </div>

                <!-- Счетчик -->
                <div class="small text-muted mb-3 text-center">
                    Выбрано: <b id="selected-count">0</b> / 20
                </div>

                <!-- Кнопки действий -->
                <div class="d-grid gap-1 mb-3">
                    <button class="btn btn-sm btn-info py-1" id="btn-collect" onclick="runCollectBatch()" disabled <?= $can_exec ? '' : 'title="Нет прав на выполнение"' ?>>🔄 Собрать данные</button>
                    <button class="btn btn-sm btn-success py-1" id="btn-run-ros" onclick="runBatch('ros')" disabled <?= $can_exec ? '' : 'title="Нет прав на выполнение"' ?>>⬆️ Обновить ROS</button>
                    <button class="btn btn-sm btn-warning py-1" id="btn-run-fw" onclick="runBatch('firmware')" disabled <?= $can_exec ? '' : 'title="Нет прав на выполнение"' ?>>📦 Обновить Firmware</button>
                    <button class="btn btn-sm btn-danger py-1" id="btn-cancel" onclick="cancelBatch()" disabled style="display:none;">✖ Отмена</button>
                </div>
            </div>

            <!-- ЛОГ -->
            <div id="run-progress" class="log-box"></div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: ТАБЛИЦА -->
        <div class="cmd-table">
            <table id="mt-update-table" class="table table-hover table-bordered w-100">
                <thead>
                    <tr>
                        <th style="width:40px;"></th>
                        <th style="width:40px;">№</th>
                        <th style="width:80px; white-space:nowrap;">Код</th>
                        <th>Название</th>
                        <th>Адрес</th>
                        <th>Время работы</th>
                        <th>WAN IP</th>
                        <th>LAN IP</th>
                        <th>Hostname</th>
                        <th>Версия ROS</th>
                        <th>Firmware</th>
                        <th>Модель</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<script>
// ============================================================
// ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ
// ============================================================
var BATCH_MAX = 20;
var batchCancelled = false;
var mtUpdateTable = null;

var REBOOT_WAIT_ROS = 120; // сек — ожидание перезагрузки после обновления ROS
var REBOOT_WAIT_FW  = 40;  // сек — ожидание перезагрузки после обновления firmware

// escapeHtml — общая реализация в /plugins/crud.js (аудит 2026-11-23, Н1)
function getSelected() {
    var selected = [];
    if (!mtUpdateTable) return selected;
    mtUpdateTable.rows({ selected: true }).every(function() {
        var d = this.data();
        selected.push({
            site_id: d.site_id,
            hostname: d.name || ('ID ' + d.site_id)
        });
    });
    return selected;
}

function updateCount() {
    if (!mtUpdateTable) return;
    var count = mtUpdateTable.rows({ selected: true }).count();
    document.getElementById('selected-count').textContent = count + ' / ' + BATCH_MAX;
    // Без права «выполнение» кнопки всегда неактивны
    var disabled = (count === 0) || !CAN_EXEC;
    document.getElementById('btn-run-ros').disabled = disabled;
    document.getElementById('btn-run-fw').disabled  = disabled;
    document.getElementById('btn-collect').disabled = disabled;
}

function updateStats() {
    if (!mtUpdateTable) return;
    
    // Общее количество строк в таблице
    var total = mtUpdateTable.rows().count();
    
    // Считаем устаревшие/актуальные по всем данным
    var old = 0, ok = 0;
    mtUpdateTable.rows().every(function() {
        var d = this.data();
        if (d.is_outdated == 1) old++; else ok++;
    });
    
    // Видимые строки (с учетом поиска и статус-фильтра)
    var visible = mtUpdateTable.rows({ search: 'applied' }).count();
    
    document.getElementById('stat-total').textContent   = total;
    document.getElementById('stat-old').textContent     = old;
    document.getElementById('stat-ok').textContent      = ok;
    document.getElementById('stat-visible').textContent = visible;
}

function applyFilter() {
    if (!mtUpdateTable) return;
    mtUpdateTable.search(document.getElementById('filter-input').value).draw();
}

function applyStatusFilter() {
    if (!mtUpdateTable) return;
    var mode = document.querySelector('input[name="status-filter"]:checked').value;
    
    // Полностью очищаем все кастомные фильтры DataTables
    DataTable.ext.search = [];
    
    // Если выбрано не "Все", добавляем наш фильтр
    if (mode !== 'all') {
        DataTable.ext.search.push(function(settings, data, dataIndex) {
            // В DataTables v2 объект settings не всегда имеет nTable,
            // поэтому просто проверяем, что это наша таблица по ссылке
            if (!mtUpdateTable) return true;
            
            // Получаем данные строки
            var rowData = mtUpdateTable.row(dataIndex).data();
            
            // Проверяем статус
            var isOutdated = (rowData.is_outdated == 1 || rowData.is_outdated === true);
            
            if (mode === 'old') return isOutdated;
            if (mode === 'ok')  return !isOutdated;
            
            return true;
        });
    }
    
    // Перерисовываем таблицу
    mtUpdateTable.draw();
    updateStats();
}

function selectOutdated() {
    if (!mtUpdateTable) return;
    var n = 0;
    // Выбираем только те строки, которые сейчас видны (прошли фильтр)
    mtUpdateTable.rows({ search: 'applied' }).every(function() {
        var d = this.data();
        if (d.is_outdated == 1 && n < BATCH_MAX) {
            this.select();
            n++;
        }
    });
    updateCount();
}

function clearSelection() {
    if (!mtUpdateTable) return;
    mtUpdateTable.rows().deselect();
    updateCount();
}

function enforceLimit(changed) {
    if (!mtUpdateTable) return;
    if (mtUpdateTable.rows({ selected: true }).count() > BATCH_MAX) {
        mtUpdateTable.row(changed).deselect();
        alert('Максимум ' + BATCH_MAX + ' устройств за раз');
    }
}

function renderProgress(summary) {
    var titles = { firmware: 'Firmware', ros: 'ROS', collect: 'Сбор данных' };
    var title = titles[summary.type] || 'ROS';
    
    // Вставляем заголовок в лог
    var html = '<div class="mb-2"><b>' + title + ':</b> ' + summary.ok + ' ok, ' + summary.fail + ' с ошибками, из ' + summary.total + '</div>';

    summary.lines.forEach(function(line) {
        var icon = '⏳', cls = 'text-muted';
        var text = escapeHtml(line.text);
        if (line.countdown > 0) {
            text = 'Перезагрузка, осталось <b>' +
                   Math.floor(line.countdown / 60) + ':' +
                   String(line.countdown % 60).padStart(2, '0') + '</b>';
            cls = 'text-info'; icon = '⏱';
        }
        if (line.status === 'running')   { icon = '🔄'; cls = 'text-warning'; }
        if (line.status === 'ok')        { icon = '✅'; cls = 'text-success'; }
        if (line.status === 'fail')      { icon = '❌'; cls = 'text-danger'; }
        if (line.status === 'cancelled') { icon = '⛔'; cls = 'text-muted'; }
        
        html +=
            '<div class="progress-line">' +
                '<span class="status-icon">' + icon + '</span>' +
                '<strong>' + escapeHtml(line.hostname) + '</strong> ' +
                '<span class="' + cls + ' ms-2">' + text + '</span>' +
            '</div>';
    });
    
    if (summary.lines.length === 0) {
        html += '<div class="progress-empty">— журнал пуст —</div>';
    }
    document.getElementById('run-progress').innerHTML = html;
    document.getElementById('run-progress').scrollTop = document.getElementById('run-progress').scrollHeight;
}

function setCancelVisible(visible) {
    document.getElementById('btn-cancel').style.display = visible ? 'inline-block' : 'none';
}

function cancelBatch() {
    batchCancelled = true;
    document.getElementById('btn-cancel').disabled = true;
}

// ============================================================
// ХЕЛПЕРЫ ДЛЯ ЗАМЫКАНИЙ (чтобы не дублировать фабрики в runBatch)
// ============================================================
function makeOnStage(summary, idx) {
    return function(stage) {
        summary.lines[idx].text = stage;
        renderProgress(summary);
    };
}

function makeOnCountdown(summary, idx) {
    return function(sec) {
        summary.lines[idx].countdown = sec;
        renderProgress(summary);
    };
}

function makeOnDone(summary, idx) {
    return function() {
        summary.lines[idx].countdown = null;
        summary.lines[idx].status = 'ok';
        summary.lines[idx].text = 'Готово';
        renderProgress(summary);
    };
}

// ============================================================
// БИЗНЕС-ЛОГИКА
// ============================================================
async function processOne(item, onStage, onCountdown, onCountdownDone) {
    onStage('Отправка команды…');
    var r = await fetch('/api/mikrotik/update-ros.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + item.site_id
    });
    var j = await r.json();
    if (!j.success) throw new Error(j.error || 'Ошибка запуска');
    if (j.done) { onStage('Уже актуально'); return; }

    onStage('Загрузка прошивки…');
    var tries = 0;
    while (tries++ < 60) {
        if (batchCancelled) throw new Error('Отменено пользователем');
        await new Promise(function(r) { setTimeout(r, 2000); });
        var r2 = await fetch('/api/mikrotik/update-ros-check.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + item.site_id
        });
        var j2 = await r2.json();
        if (!j2.success) throw new Error(j2.error || 'Ошибка проверки');
        if (j2.done) {
            if (j2.dry_run) { onStage('Тест: не перезагружено'); return; }
            startRebootCountdown(onCountdown, onCountdownDone, REBOOT_WAIT_ROS);
            return;
        }
        if (j2.message) onStage(j2.message);
    }
    throw new Error('Таймаут ожидания');
}

function startRebootCountdown(onCountdown, onFinish, waitSeconds) {
    var REBOOT_WAIT = waitSeconds || 120;
    var endsAt = Date.now() + REBOOT_WAIT * 1000;
    var tick = function() {
        if (batchCancelled) return true;
        var left = Math.max(0, Math.ceil((endsAt - Date.now()) / 1000));
        onCountdown(left);
        if (left <= 0) { if (onFinish) onFinish(); return true; }
        return false;
    };
    if (tick()) return;
    var t = setInterval(function() { if (tick()) clearInterval(t); }, 1000);
    var onVis = function() {
        if (document.visibilityState !== 'visible') return;
        if (tick()) {
            clearInterval(t);
            document.removeEventListener('visibilitychange', onVis);
        }
    };
    document.addEventListener('visibilitychange', onVis);
    return t;
}

// Не блокирует очередь: отправляет команду, запускает обратный отсчёт
// и сразу возвращается — следующий микротик обработается параллельно.
async function processOneFirmware(item, onStage, onCountdown, onCountdownDone) {
    onStage('Обновление загрузчика…');
    var r = await fetch('/api/mikrotik/update-firmware.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + item.site_id
    });
    var j = await r.json();
    if (!j.success) throw new Error(j.error || 'Ошибка обновления');
    if (j.done) { onStage('Уже актуально'); return; }

    onStage('Перезагрузка…');
    startRebootCountdown(onCountdown, onCountdownDone, REBOOT_WAIT_FW);
}

async function runBatch(type) {
    var selected = getSelected();
    if (!selected.length) return;

    /** @type {HTMLButtonElement} */
    var btnRos     = document.getElementById('btn-run-ros');
    /** @type {HTMLButtonElement} */
    var btnFw      = document.getElementById('btn-run-fw');
    /** @type {HTMLButtonElement} */
    var btnCollect = document.getElementById('btn-collect');
    /** @type {HTMLButtonElement} */
    var btn        = (type === 'firmware') ? btnFw : btnRos;
    /** @type {HTMLButtonElement} */
    var other      = (type === 'firmware') ? btnRos : btnFw;
    var orig       = btn.innerHTML;

    batchCancelled = false;

    btn.disabled = true;
    other.disabled = true;
    btnCollect.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Работаем…';
    setCancelVisible(true);
    /** @type {HTMLButtonElement} */
    var btnCancel = document.getElementById('btn-cancel');
    btnCancel.disabled = false;

    var summary = {
        type: type,
        total: selected.length,
        ok: 0,
        fail: 0,
        lines: selected.map(function(s) { return { hostname: s.hostname, status: 'pending', text: 'Ожидает' }; })
    };
    renderProgress(summary);

    for (var i = 0; i < selected.length; i++) {
        if (batchCancelled) {
            summary.lines[i].status = 'cancelled';
            summary.lines[i].text = 'Отменено';
            renderProgress(summary);
            continue;
        }
        summary.lines[i].status = 'running';
        summary.lines[i].text = 'Запуск…';
        renderProgress(summary);

        try {
            if (type === 'firmware') {
                await processOneFirmware(
                    selected[i],
                    makeOnStage(summary, i),
                    makeOnCountdown(summary, i),
                    makeOnDone(summary, i)
                );
            } else {
                await processOne(
                    selected[i],
                    makeOnStage(summary, i),
                    makeOnCountdown(summary, i),
                    makeOnDone(summary, i)
                );
            }
            summary.ok++;
            if (!(summary.lines[i].countdown > 0)) {
                summary.lines[i].status = 'ok';
                summary.lines[i].text = 'Готово';
            }
        } catch (e) {
            if (batchCancelled) {
                summary.lines[i].status = 'cancelled';
                summary.lines[i].text = 'Отменено';
            } else {
                summary.fail++;
                summary.lines[i].status = 'fail';
                summary.lines[i].text = e.message || 'Ошибка';
            }
        }
        renderProgress(summary);
        if (i < selected.length - 1 && !batchCancelled) {
            await new Promise(function(r) { setTimeout(r, 3000); });
        }
    }

    btn.disabled = false;
    btn.innerHTML = orig;
    setCancelVisible(false);
    updateCount();
}

async function runCollectBatch() {
    var selected = getSelected();
    if (!selected.length) return;

    /** @type {HTMLButtonElement} */
    var btn      = document.getElementById('btn-collect');
    /** @type {HTMLButtonElement} */
    var btnRos   = document.getElementById('btn-run-ros');
    /** @type {HTMLButtonElement} */
    var btnFw    = document.getElementById('btn-run-fw');
    var orig = btn.innerHTML;

    batchCancelled = false;

    btn.disabled = true;
    btnRos.disabled = true;
    btnFw.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Собираем…';
    setCancelVisible(true);
    /** @type {HTMLButtonElement} */
    var btnCancel = document.getElementById('btn-cancel');
    btnCancel.disabled = false;

    var summary = {
        type: 'collect',
        total: selected.length,
        ok: 0,
        fail: 0,
        lines: selected.map(function(s) { return { hostname: s.hostname, status: 'pending', text: 'Ожидает' }; })
    };
    renderProgress(summary);

    for (var i = 0; i < selected.length; i++) {
        if (batchCancelled) {
            summary.lines[i].status = 'cancelled';
            summary.lines[i].text = 'Отменено';
            renderProgress(summary);
            continue;
        }
        summary.lines[i].status = 'running';
        summary.lines[i].text = 'Сбор данных…';
        renderProgress(summary);

        try {
            var r = await fetch('/api/mikrotik/collect-info.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + selected[i].site_id
            });
            var j = await r.json();
            if (!j.success) throw new Error(j.error || 'Ошибка');
            summary.ok++;
            summary.lines[i].status = 'ok';
            summary.lines[i].text = 'Данные обновлены';
        } catch (e) {
            if (batchCancelled) {
                summary.lines[i].status = 'cancelled';
                summary.lines[i].text = 'Отменено';
            } else {
                summary.fail++;
                summary.lines[i].status = 'fail';
                summary.lines[i].text = e.message || 'Нет связи';
            }
        }
        renderProgress(summary);
        if (i < selected.length - 1 && !batchCancelled) {
            await new Promise(function(r) { setTimeout(r, 300); });
        }
    }

    btn.disabled = false;
    btn.innerHTML = orig;
    setCancelVisible(false);
    updateCount();

    if (summary.ok > 0) {
        await refreshRows(selected.map(function(s) { return s.site_id; }));
        mtUpdateTable.rows({ selected: true }).deselect();
        updateStats();
        updateCount();
    }
}

async function refreshRows(siteIds) {
    for (var k = 0; k < siteIds.length; k++) {
        var id = siteIds[k];
        try {
            var r = await fetch('/api/mikrotik/get-device-row.php?id=' + encodeURIComponent(id));
            var j = await r.json();
            if (j.success && j.device) applyDeviceRowToTable(id, j.device);
        } catch (e) { /* тихо */ }
    }
}

function applyDeviceRowToTable(siteId, d) {
    if (!mtUpdateTable) return;
    var rowIdx = -1;
    mtUpdateTable.rows().every(function(idx) {
        if (String(this.data().site_id) === String(siteId)) rowIdx = idx;
    });
    if (rowIdx === -1) return;

    var old = mtUpdateTable.row(rowIdx).data();
    var updated = Object.assign({}, old, {
        is_outdated: d.is_outdated ? 1 : 0,
        current_version: d.current_version,
        current_firmware: d.current_firmware,
        wan_ip: d.wan_ip ?? old.wan_ip,
        lan_ip: d.lan_ip ?? old.lan_ip,
        name: d.name,
        new_name: d.new_name,
        board_name: d.board_name
    });

    updated.ros_badge = d.current_version
        ? '<span class="badge badge-version ' + (d.is_outdated ? 'badge-old' : 'badge-ok') + '">' + escapeHtml(d.current_version) + '</span>'
        : '<span class="text-muted">—</span>';
    updated.fw_badge = d.current_firmware
        ? '<span class="badge badge-version ' + (d.fw_outdated ? 'badge-old' : 'badge-ok') + '">' + escapeHtml(d.current_firmware) + '</span>'
        : '<span class="text-muted">—</span>';

    mtUpdateTable.row(rowIdx).data(updated).invalidate();
}

// ============================================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================================
mtUpdateTable = new DataTable('#mt-update-table', {
    serverSide: false, // <--- ГЛАВНОЕ, чтобы заработала пагинация
    ajax: {
        url: '/mod/mt_update/data.php',
        type: 'POST',
        dataSrc: function(json) {
            document.getElementById('stat-total').textContent = json.stats.total;
            document.getElementById('stat-old').textContent   = json.stats.old;
            document.getElementById('stat-ok').textContent    = json.stats.ok;
            document.getElementById('stat-visible').textContent = json.stats.total;
            if (json.target_version) {
                document.getElementById('target-version-info').innerHTML =
                    'Целевая: <b class="font-monospace text-warning">' + json.target_version + '</b>';
            }
            return json.data;
        }
    },
    select: { style: 'multi', selector: 'td:not(:first-child)' },
    order: [[1, 'asc']],
    columns: [
        {
            data: 'site_id',
            orderable: false,
            render: function(data, type, row) {
                var hasIp = (row.lan_ip || row.wan_ip) ? '' : 'disabled title="Нет IP"';
                return '<input type="checkbox" class="batch-cb" data-site="' + row.site_id + '" data-host="' + (row.name || ('ID ' + row.site_id)) + '" ' + hasIp + '>';
            }
        },
        { data: 'site_id', title: '№' },
        { data: 'code', title: 'Код' },
        { data: 'name', title: 'Название' },
        { data: 'address', title: 'Адрес', className: 'text-muted small' },
        {
            data: 'work_schedule_text',
            title: 'Время работы',
            render: function(data, type, row) {
                var html = '<span class="small text-muted">' + data + '</span>';
                if (row.work_schedule_msk) {
                    html += '<br><span style="color:#7db8df; font-size: 11px;">МСК: ' + row.work_schedule_msk + '</span>';
                }
                return html;
            }
        },
        { data: 'wan_ip', title: 'WAN IP', className: 'font-monospace', 
            render: function(data) {
                return data ? escapeHtml(data) : '<span class="text-muted">—</span>';
            }
        },
        { data: 'lan_ip', title: 'LAN IP', className: 'font-monospace',
            render: function(data) {
                return data ? escapeHtml(data) : '<span class="text-muted">—</span>';
            }
        },
        { data: 'new_name', title: 'Hostname' },
        { data: 'ros_badge', title: 'Версия ROS' },  // <--- Берем готовый HTML
        { data: 'fw_badge', title: 'Firmware' },     // <--- Берем готовый HTML
        { data: 'board_name', title: 'Модель', className: 'small text-muted' }
    ],
    drawCallback: function() {
        updateCount();
        updateStats();
    }
});

mtUpdateTable.on('select', function(e, dt, type, indexes) {
    if (type === 'row') {
        dt.rows(indexes).nodes().toArray().forEach(function(row) {
            var cb = row.querySelector('.batch-cb');
            if (cb) cb.checked = true;
        });
        updateCount();
    }
});

mtUpdateTable.on('deselect', function(e, dt, type, indexes) {
    if (type === 'row') {
        dt.rows(indexes).nodes().toArray().forEach(function(row) {
            var cb = row.querySelector('.batch-cb');
            if (cb) cb.checked = false;
        });
        updateCount();
    }
});

document.getElementById('mt-update-table').addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('batch-cb')) {
        var tr = e.target.closest('tr');
        if (e.target.checked) mtUpdateTable.row(tr).select();
        else mtUpdateTable.row(tr).deselect();
    }
});

document.addEventListener('change', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('batch-cb')) {
        enforceLimit(e.target);
        updateCount();
    }
});

renderProgress({ type: 'ros', total: 0, ok: 0, fail: 0, lines: [] });
updateCount();
updateStats();
</script>
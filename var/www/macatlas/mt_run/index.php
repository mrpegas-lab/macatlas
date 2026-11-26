<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
// Право на выполнение команд передаём в JS, чтобы кнопки без прав были неактивны
?>
<script>var CAN_EXEC = <?= $can_exec ? 'true' : 'false' ?>;</script>

<div class="container-fluid py-3">
    
    <!-- ВЕРХНЯЯ ПАНЕЛЬ: Заголовок + Фильтр + Кнопки -->
<!-- ВЕРХНЯЯ ПАНЕЛЬ -->
<div class="panel mb-2 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 py-2 px-3">
    
    <!-- Заголовок -->
    <div class="d-flex align-items-center">
        <h5 class="mb-0 fw-bold">🛠 Выполнение команд</h5>
    </div>

    <!-- Правая панель (на ноутбуках переносится вниз и выстраивается в колонку) -->
    <div class="d-flex flex-column flex-md-row align-items-md-center gap-2 flex-shrink-0">
        
        <!-- Слоты -->
        <div class="d-flex align-items-center gap-1">
            <span class="fw-semibold small text-nowrap">Слот:</span>
            <select id="slot-select" class="form-select form-select-sm w-auto" onchange="onSlotChange()">
                <option value="0">— не выбран —</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
            </select>
            
            <!-- Убрали flex-grow-1, оставили просто фиксированную ширину -->
            <input type="text" id="slot-title" class="form-control form-control-sm" style="width: 140px;" placeholder="Название захода" onchange="saveSlotTitle()" disabled>
            
            <span id="slot-progress" class="small text-muted text-nowrap"></span>
            <button class="btn btn-sm btn-outline-warning" id="btn-slot-reset" onclick="resetSlot()" title="Сбросить слот" disabled>⟲</button>
            <button class="btn btn-sm btn-outline-danger" id="btn-slot-reset-items" onclick="resetSlotItems()" title="Сбросить отметки" disabled>⌫</button>
        </div>

        <!-- Поиск и Кнопки -->
        <div class="d-flex align-items-center gap-2">
            <input type="text" id="filter-input" class="form-control form-control-sm" style="width: 180px;" placeholder="Поиск..." oninput="applyFilter()">
            <button class="btn btn-sm btn-outline-secondary text-nowrap" onclick="selectVisible()">Видимые</button>
            <button class="btn btn-sm btn-outline-secondary text-nowrap" onclick="clearSelection()">Снять</button>
            <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" onclick="showLabels()">Метки</button>
        </div>

    </div>
</div>

    <div class="cmd-layout">
        <!-- ЛЕВАЯ КОЛОНКА: ФОРМА + ЛОГ -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 fw-semibold">Команды (по одной на строку)</div>
                <span id="slot-badge" class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-25 rounded-pill px-3 py-1">без слота</span>
                <textarea id="cmd-text" spellcheck="false" placeholder="# По одной команде на строку..."></textarea>
                <div class="hint mt-2">Пустые строки и строки, начинающиеся с <code>#</code>, игнорируются.</div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="dry-run" checked>
                    <label class="form-check-label" for="dry-run">Тестовый режим</label>
                </div>
                <div class="d-flex gap-2 mt-3 align-items-center">
                    <button class="btn btn-sm btn-success" id="btn-run" onclick="runAll()" disabled <?= $can_exec ? '' : 'title="Нет прав на выполнение"' ?>>▶ Выполнить</button>
                    <button class="btn btn-sm btn-danger" id="btn-cancel" onclick="cancelRun()" disabled>■ Отмена</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearProgress()">Очистить Лог</button>
                </div>
            </div>
            <div id="run-progress" class="log-box"></div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: ТАБЛИЦА (без своей панели фильтров) -->
        <div class="cmd-table">
            <table id="mt-run-table" class="table table-hover w-100">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="cb-all" onchange="toggleAll(this)"></th>
                        <th style="width:40px;">№</th>
                        <th>Код</th>
                        <th>Название</th>
                        <th>Адрес</th>
                        <th>IP</th>
                        <th>Hostname</th>
                        <th>Модель</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<!-- Модалка со справочником меток -->
<div id="labels-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.7); z-index:1000; align-items:center; justify-content:center;" onclick="if(event.target===this)closeLabels()">
    <div style="background:var(--panel-bg); border:1px solid var(--panel-border); border-radius:8px; max-width:760px; width:90%; max-height:85vh; overflow:auto; padding:20px 24px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Справочник меток</h5>
            <button class="btn btn-sm btn-outline-secondary" onclick="closeLabels()">✕</button>
        </div>
        <!-- Сюда будет загружаться содержимое из labels.html -->
        <div id="labels-content">
            <div class="text-muted text-center py-4">Загрузка...</div>
        </div>
    </div>
</div>
<script>
// ============================================================
// 1. ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ (var — чтобы перезаписывались)
// ============================================================
var MAX_SELECT = 20;
var RUNNER_URL = '/mod/mt_run/mt_runner_proxy.php';
var mtRunTable = null;
var runState = { cancelled: false, done: 0, total: 0, ok: 0, fail: 0, startedAt: 0, timerId: null, abortCtrl: null };

// === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
var currentSlot = 0;          // 0 = не выбран
var slotCache = [];           // список слотов из slots_api list
// === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===

// ============================================================
// 2. ВСЕ ФУНКЦИИ
// ============================================================
function updateSelectionUI() {
    if (!mtRunTable) return;
    var count = mtRunTable.rows({ selected: true }).count();
    // Без права «выполнение» кнопка всегда неактивна
    document.getElementById('btn-run').disabled = (count === 0) || !CAN_EXEC;
}

function toggleAll(master) {
    if (!mtRunTable) return;
    if (master.checked) {
        mtRunTable.rows({ page: 'current' }).select();
    } else {
        mtRunTable.rows({ page: 'current' }).deselect();
    }
}

function selectVisible() {
    if (!mtRunTable) return;
    var n = 0;
    mtRunTable.rows({ search: 'applied' }).every(function() {
        if (n >= MAX_SELECT) return;
        this.select();
        n++;
    });
}

function clearSelection() {
    if (!mtRunTable) return;
    mtRunTable.rows().deselect();
    document.getElementById('cb-all').checked = false;
}

function getSelected() {
    if (!mtRunTable) return [];
    var out = [];
    mtRunTable.rows({ selected: true }).every(function() {
        var data = this.data();
        out.push({
            site_id: data.site_id,
            hostname: data.name || ('ID ' + data.site_id),
            ip: data.ip || ''
        });
    });
    return out;
}

function applyFilter() {
    if (!mtRunTable) return;
    mtRunTable.search(document.getElementById('filter-input').value).draw();
}

// escapeHtml — общая реализация в /plugins/crud.js (аудит 2026-11-23, Н1)
function parseCommands() { 
    return document.getElementById('cmd-text').value
        .split('\n')
        .map(function(s) { return s.replace(/\r$/, ''); })
        .filter(function(s) { return s.trim().length && !s.trim().startsWith('#'); });
}

function clearProgress() { 
    document.getElementById('run-progress').innerHTML = '';
}

function cancelRun() { 
    if (!runState.total) return; 
    if (!confirm('Прервать выполнение?')) return; 
    runState.cancelled = true; 
    if (runState.abortCtrl) { 
        try { runState.abortCtrl.abort(); } catch (e) {} 
    } 
    logLine('—', 'fail', '⛔ Выполнение прервано пользователем'); 
}

function logLine(host, status, text) { 
    var box = document.getElementById('run-progress'); 
    var ph = box.querySelector('.placeholder'); 
    if (ph) ph.remove();
    
    var div = document.createElement('div'); 
    div.className = 'log-line ' + (status || ''); 
    var icon = status === 'ok' ? '✅' : status === 'fail' ? '❌' : '🔄';
    
    div.innerHTML = 
        '<div class="head">' + icon + ' <b>' + escapeHtml(host) + '</b></div>' +
        '<div class="out">' + (text ? escapeHtml(text) : '') + '</div>'; 
        
    box.appendChild(div); 
    box.scrollTop = box.scrollHeight; 
    return div; 
}

async function callRunner(dev, cmds, dryRun) { 
    runState.abortCtrl = new AbortController(); 
    var r = await fetch(RUNNER_URL, { 
        method: 'POST', 
        headers: {'Content-Type': 'application/json'}, 
        signal: runState.abortCtrl.signal, 
        body: JSON.stringify({ 
            site_id: dev.site_id, 
            commands: cmds, 
            dry_run: dryRun, 
            timeout: 30 
        }) 
    }); 
    var text = await r.text(); 
    var j; 
    try { 
        j = JSON.parse(text); 
    } catch (e) { 
        var snippet = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400); 
        throw new Error('HTTP ' + r.status + ': ' + (snippet || '(пустой ответ)')); 
    } 
    return j; 
}

async function runAll() {
    var cmds = parseCommands();
    if (!cmds.length) { alert('Введите хотя бы одну команду'); return; }
    
    var selected = getSelected();
    if (!selected.length) return;
    
    var dryRun = document.getElementById('dry-run').checked;
    if (!dryRun) { 
        if (!confirm('Выполнить ' + cmds.length + ' команд(ы) на ' + selected.length + ' устройстве(ах)?\n\nЭто реальные изменения на MikroTik.')) return; 
    }

    // === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    if (!dryRun && !currentSlot) {
        if (!confirm('Слот не выбран — результаты не будут отмечены в слоте.\n\nПродолжить без учёта в слоте?')) {
            return;
        }
    }
    // === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    
    var btn = document.getElementById('btn-run');
    var btnCancel = document.getElementById('btn-cancel');
    
    runState = { cancelled: false, done: 0, total: selected.length, ok: 0, fail: 0, startedAt: performance.now(), timerId: null, abortCtrl: null };
    
    btn.disabled = true; 
    btnCancel.disabled = false; 
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Работаем…';
    
    document.getElementById('run-progress').innerHTML = '<div class="placeholder">⌛ Здесь появится результат выполнения…</div>';
    
    var items = [];
    
    for (var i = 0; i < selected.length; i++) {
        if (runState.cancelled) break;
        
        var dev = selected[i];
        var line = logLine(dev.hostname, 'running', 'выполняется…');
        
        var ok = false; 
        var output = '';
        
        try {
            var j = await callRunner(dev, cmds, dryRun);
            if (!j.success) { 
                line.className = 'log-line fail'; 
                line.querySelector('.head').innerHTML = '❌ <b>' + escapeHtml(dev.hostname) + '</b>'; 
                output = (j.error || 'Ошибка') + (j.output ? '  ' + j.output : ''); 
                line.querySelector('.out').textContent = output; 
            } else { 
                line.className = 'log-line ok'; 
                line.querySelector('.head').innerHTML = '✅ <b>' + escapeHtml(dev.hostname) + '</b>'; 
                output = j.output || ''; 
                line.querySelector('.out').textContent = output; 
                ok = true; 
            }
        } catch (e) {
            if (e.name === 'AbortError' || runState.cancelled) { 
                line.className = 'log-line fail'; 
                line.querySelector('.head').innerHTML = '⛔ <b>' + escapeHtml(dev.hostname) + '</b>'; 
                line.querySelector('.out').textContent = 'прервано'; 
                output = 'прервано'; 
            } else { 
                line.className = 'log-line fail'; 
                line.querySelector('.head').innerHTML = '❌ <b>' + escapeHtml(dev.hostname) + '</b>'; 
                output = e.message || 'Сетевая ошибка'; 
                line.querySelector('.out').textContent = output; 
            }
        }
        
        runState.done++; 
        if (ok) runState.ok++; else runState.fail++;
        
        items.push({ site_id: dev.site_id, hostname: dev.hostname, ip: dev.ip || '', success: ok, error: ok ? null : output, output: output });
        
        if (runState.cancelled) break;
        if (i < selected.length - 1) { await new Promise(function(r) { setTimeout(r, 200); }); }
    }
    
    try {
        var mode = dryRun ? 'dry' : 'run';
        var saveResp = await fetch('/mod/mt_run/mt_history_save.php', { 
            method: 'POST', 
            headers: {'Content-Type': 'application/json'}, 
            body: JSON.stringify({ 
                mode: mode, 
                commands: document.getElementById('cmd-text').value, 
                items: items,
                slot: currentSlot
            }) 
        });
        var saveJson = await saveResp.json();
        if (!saveJson.success) { 
            console.error('История не сохранена:', saveJson.error); 
            alert('Внимание: история не сохранена.\n' + (saveJson.error || '')); 
        }
    } catch (e) { 
        console.error('Не удалось сохранить историю:', e); 
    }

    // === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    if (currentSlot) {
        await loadSlots();
        if (mtRunTable) mtRunTable.ajax.reload(null, false);
    }
    // === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    
    btn.disabled = false; 
    btnCancel.disabled = true; 
    btn.innerHTML = '▶ Выполнить';
    
    if (runState.cancelled) { 
        logLine('—', 'fail', '⛔ Остановлено на ' + runState.done + ' из ' + runState.total + ' (✅ ' + runState.ok + ', ❌ ' + runState.fail + ')'); 
    } else { 
        logLine('—', 'ok', '🏁 Завершено: ' + runState.total + ' (✅ ' + runState.ok + ', ❌ ' + runState.fail + ')'); 
    }
    // Сбрасываем выделение после выполнения
    mtRunTable.rows().deselect();
    document.getElementById('cb-all').checked = false;
    updateSelectionUI();
    
}

function showLabels() { 
    var modal = document.getElementById('labels-modal');
    var content = document.getElementById('labels-content');
    
    modal.style.display = 'flex';
    
    if (content.getAttribute('data-loaded') !== '1') {
        fetch('/mod/mt_run/labels.html')
            .then(function(r) { return r.text(); })
            .then(function(html) {
                content.innerHTML = html;
                content.setAttribute('data-loaded', '1');
            })
            .catch(function() {
                content.innerHTML = '<div class="text-danger">Не удалось загрузить справочник</div>';
            });
    }
}

function closeLabels() { 
    document.getElementById('labels-modal').style.display = 'none'; 
}

// ============================================================
// === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
// ============================================================

function setSlotButtonsEnabled(on) {
    // Управление слотами — часть права «выполнение»
    on = on && CAN_EXEC;
    document.getElementById('btn-slot-reset').disabled = !on;
    document.getElementById('btn-slot-reset-items').disabled = !on;
    document.getElementById('slot-title').disabled = !on;
}

function renderSlotProgress() {
    var el = document.getElementById('slot-progress');
    if (!currentSlot) { el.textContent = ''; return; }
    var s = null;
    for (var i = 0; i < slotCache.length; i++) {
        if (slotCache[i].slot == currentSlot) { s = slotCache[i]; break; }
    }
    if (!s) { el.textContent = ''; return; }
    el.innerHTML = '✅ <b>' + s.ok_cnt + '</b>  ❌ <b>' + s.fail_cnt + '</b>';
}

function renderSlotBadge() {
    var badge = document.getElementById('slot-badge');
    if (!currentSlot) {
        badge.className = 'badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-25 rounded-pill px-3 py-1';
        badge.textContent = 'без слота';
        badge.title = 'Результаты не записываются в слот, только в историю';
    } else {
        // Ищем название слота в кэше
        var titleStr = '';
        for (var i = 0; i < slotCache.length; i++) {
            if (slotCache[i].slot == currentSlot && slotCache[i].title) {
                titleStr = ' — ' + slotCache[i].title;
                break;
            }
        }
        
        badge.className = 'badge bg-info bg-opacity-25 text-info border border-info border-opacity-50 rounded-pill px-3 py-1';
        badge.textContent = 'слот ' + currentSlot + titleStr;
        badge.title = 'Устройства и шаблон записываются в слот ' + currentSlot;
    }
}

async function slotsApi(action, payload) {
    var body = Object.assign({action: action}, payload || {});
    var r = await fetch('/mod/mt_run/slots_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    });
    return await r.json();
}

async function loadSlots() {
    try {
        var j = await slotsApi('list'); // Теперь это сработает без ошибок
        if (!j.success) return;
        slotCache = j.slots;
        
        var sel = document.getElementById('slot-select');
        var currentVal = sel.value; // Запоминаем, какой слот выбран
        
        // Полностью очищаем список
        sel.innerHTML = '';
        
        // Добавляем дефолтный пункт
        var defaultOpt = document.createElement('option');
        defaultOpt.value = '0';
        defaultOpt.textContent = '— не выбран —';
        sel.appendChild(defaultOpt);
        
        // Пробегаемся по всем слотам с 1 по 5
        for (var i = 1; i <= 5; i++) {
            var s = null;
            // Ищем данные для этого слота
            for (var k = 0; k < slotCache.length; k++) {
                if (parseInt(slotCache[k].slot, 10) === i) {
                    s = slotCache[k];
                    break;
                }
            }
            
            // Формируем красивую подпись
            var label = String(i);
            if (s && s.title && String(s.title).trim() !== '') {
                label += ' — ' + String(s.title).trim();
            }
            
            // Создаем новый option
            var opt = document.createElement('option');
            opt.value = i;
            opt.textContent = label;
            sel.appendChild(opt);
        }
        
        // Возвращаем выбранный слот на место
        sel.value = currentVal;
        
        renderSlotProgress();
        renderSlotBadge();
    } catch (e) {
        console.error('Ошибка в loadSlots:', e);
    }
}

async function onSlotChange() {
    var sel = document.getElementById('slot-select');
    currentSlot = parseInt(sel.value, 10) || 0;

    setSlotButtonsEnabled(currentSlot > 0);
    renderSlotBadge();

    if (!currentSlot) {
        document.getElementById('slot-title').value = '';
        renderSlotProgress();
        if (mtRunTable) mtRunTable.ajax.reload();
        return;
    }

    var j = await slotsApi('load', {slot: currentSlot});
    if (j.success) {
        document.getElementById('slot-title').value = j.slot.title || '';
        if (j.slot.commands !== null && j.slot.commands !== undefined) {
            document.getElementById('cmd-text').value = j.slot.commands;
        } else if (!document.getElementById('cmd-text').value.trim()) {
            document.getElementById('cmd-text').value = '';
        }
    }
    if (mtRunTable) mtRunTable.ajax.reload();
    loadSlots();
}

async function saveSlotTitle() {
    if (!currentSlot) return;
    var title = document.getElementById('slot-title').value.trim();
    
    // Сохраняем на сервер
    await slotsApi('save', {slot: currentSlot, title: title});
    
    // Обновляем выпадающий список и бейджик
    await loadSlots();
    renderSlotBadge(); // На случай если хотите добавить название в бейджик
}

async function resetSlot() {
    if (!currentSlot) { alert('Слот не выбран'); return; }
    if (!confirm('Сбросить слот ' + currentSlot + ' полностью?\n\nБудут стёрты: отметки устройств и сохранённый шаблон.')) return;
    await slotsApi('reset', {slot: currentSlot});
    document.getElementById('slot-title').value = '';
    document.getElementById('cmd-text').value = '';
    if (mtRunTable) mtRunTable.ajax.reload();
    loadSlots();
}

async function resetSlotItems() {
    if (!currentSlot) { alert('Слот не выбран'); return; }
    if (!confirm('Стереть отметки устройств в слоте ' + currentSlot + '?\n\nШаблон конфига останется.')) return;
    await slotsApi('reset_items', {slot: currentSlot});
    if (mtRunTable) mtRunTable.ajax.reload();
    loadSlots();
}

// ============================================================
// === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
// ============================================================

// ============================================================
// 3. ИНИЦИАЛИЗАЦИЯ (без IIFE, без DOMContentLoaded)
// ============================================================
mtRunTable = new DataTable('#mt-run-table', {
    ajax: {
        url: '/mod/mt_run/data.php',
        type: 'POST',
        // === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
        data: function(d) {
            d.slot = currentSlot;
        }
        // === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    },
    rowId: 'site_id',
    select: {
        style: 'multi',
        selector: 'td:not(:first-child)'
    },
    order: [[1, 'asc']],
    language: {
        select: {
            rows: {
                "_": "Выбрано: %d / 20",
                "0": "Выбрано: 0 / 20",
                "1": "Выбрано: 1 / 20"
            }
        }
    },
    scrollY: 'calc(100vh - 330px)',
    columns: [
        { 
            data: 'site_id', 
            orderable: false,
            render: function(data, type, row) {
                var hasIp = row.ip ? '' : 'disabled title="Нет IP"';
                return '<input type="checkbox" class="dev-cb" data-site="' + row.site_id + '" data-host="' + (row.name || ('ID ' + row.site_id)) + '" ' + hasIp + '>';
            }
        },
        { data: 'site_id', title: '№', orderable: true },
        { data: 'code', title: 'Код' },
        { data: 'name', title: 'Название' },
        { data: 'address', title: 'Адрес', className: 'text-muted small' },
        { data: 'ip', title: 'IP', className: 'font-monospace' },
        { data: 'new_name', title: 'Hostname' },
        { data: 'board_name', title: 'Модель', className: 'small text-muted' },
        // === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
        {
            data: 'slot_status',
            title: 'Слот',
            orderable: false,
            width: '60px',
            render: function(data, type, row) {
                if (!data) return '<span class="text-muted">—</span>';
                if (data === 'ok')   return '<span title="ok">✅</span>';
                if (data === 'fail') return '<span title="' + escapeHtml(row.slot_error || 'fail') + '">❌</span>';
                return '<span class="text-muted">—</span>';
            }
        }
        // === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
    ],
    drawCallback: function() {
        updateSelectionUI();
        var cbAll = document.getElementById('cb-all');
        if(cbAll) cbAll.checked = false;
    }
});

mtRunTable.on('select', function (e, dt, type, indexes) {
    if (type === 'row') {
        var rows = dt.rows(indexes).nodes().toArray();
        rows.forEach(function(row) {
            var cb = row.querySelector('.dev-cb');
            if (cb) cb.checked = true;
        });
        updateSelectionUI();
    }
});

mtRunTable.on('deselect', function (e, dt, type, indexes) {
    if (type === 'row') {
        var rows = dt.rows(indexes).nodes().toArray();
        rows.forEach(function(row) {
            var cb = row.querySelector('.dev-cb');
            if (cb) cb.checked = false;
        });
        updateSelectionUI();
    }
});

document.getElementById('mt-run-table').addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('dev-cb')) {
        var tr = e.target.closest('tr');
        if (e.target.checked) {
            mtRunTable.row(tr).select();
        } else {
            mtRunTable.row(tr).deselect();
        }
    }
});

updateSelectionUI();

// === ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
setSlotButtonsEnabled(false);
renderSlotBadge();
loadSlots();
// === /ДОБАВЛЕНО ДЛЯ СЛОТОВ ===
</script>
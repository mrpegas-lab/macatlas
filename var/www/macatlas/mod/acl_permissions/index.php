<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">

    <!-- ВЕРХНЯЯ ПАНЕЛЬ -->
    <div class="panel mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding: 8px 12px;">
        <div class="d-flex align-items-center gap-3">
            <h5 class="mb-0 fw-bold">🛡 Матрица прав</h5>
        </div>
        <?php if ($can_edit): ?>
            <button type="button" class="btn btn-primary btn-sm" id="btn-save-all">
                <i class="bi bi-check-lg"></i> Сохранить все права
            </button>
        <?php endif; ?>
    </div>

    <div class="cmd-layout perms-layout" style="height: calc(100vh - 140px);">

        <!-- ЛЕВАЯ КОЛОНКА: ФИЛЬТР -->
        <div class="cmd-form" style="width: 250px;">
            <div class="panel cmd-panel" style="height: 100%;">
                <div class="fw-semibold mb-3"><i class="bi bi-funnel me-1"></i> Фильтр</div>
                
                <div class="mb-2">
                    <label class="form-label small">Группа меню</label>
                    <select id="filter-group" class="form-select form-select-sm" onchange="filterPerms()">
                        <option value="">— Все группы —</option>
                        <!-- JS заполнит -->
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small">Роль</label>
                    <select id="filter-role" class="form-select form-select-sm" onchange="filterPerms()">
                        <option value="">— Все роли —</option>
                        <!-- JS заполнит -->
                    </select>
                </div>

                <div class="form-text small mt-3">
                    <i class="bi bi-info-circle"></i>
                    V — просмотр, A — добавление, E — редактирование, D — удаление,
                    X — выполнение (запуск команд, обновление прошивок).
                </div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: МАТРИЦА -->
        <div class="cmd-table" style="flex: 1;">
            <div class="panel" style="height: 100%; padding: 0; display: flex; flex-direction: column; overflow: hidden;">
                <div style="flex: 1; min-height: 0; overflow: auto;">
                    <table class="table table-sm table-bordered mb-0" id="perms-table" style="white-space: nowrap;">
                        <thead class="table-dark">
                            <tr id="perms-thead"></tr>
                        </thead>
                        <tbody id="perms-tbody"></tbody>
                    </table>
                </div>
                <div id="perms-msg" class="p-2 small"></div>
            </div>
        </div>

    </div>
</div>

<script>
// Глобальные данные
var allRoles = [];
var allResources = [];
var allGroups = [];
var permsMap = {}; // { roleId: { resId: {view,add,edit,del} } }

var tableHead = document.getElementById('perms-thead');
var tableBody = document.getElementById('perms-tbody');
var msgEl     = document.getElementById('perms-msg');
var btnSave   = document.getElementById('btn-save-all');

// ==========================================
// Загрузка
// ==========================================
function loadData() {
    tableBody.innerHTML = '<tr><td colspan="99" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Загрузка...</td></tr>';
    
    fetch('/mod/acl_permissions/data.php')
        .then(function(r) { return r.json(); })
        .then(function(json) {
            allRoles = json.roles;
            allResources = json.resources;
            allGroups = json.groups;
            permsMap = json.perms;
            renderFilters();
            renderTable();
        })
        .catch(function(err) {
            showMsg('Ошибка загрузки: ' + err.message, 'danger');
        });
}

// ==========================================
// Фильтры
// ==========================================
function renderFilters() {
    var selG = document.getElementById('filter-group');
    allGroups.forEach(function(g) {
        var opt = document.createElement('option');
        opt.value = g.id;
        opt.textContent = g.title;
        selG.appendChild(opt);
    });

    var selR = document.getElementById('filter-role');
    allRoles.forEach(function(r) {
        var opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = r.name;
        selR.appendChild(opt);
    });
}

function filterPerms() {
    renderTable();
}

// ==========================================
// Рендер таблицы
// ==========================================
function renderTable() {
    var filterGroup = document.getElementById('filter-group').value;
    var filterRole = document.getElementById('filter-role').value;

    // Фильтруем ресурсы по группе
    var resources = allResources.filter(function(res) {
        if (!filterGroup) return true;
        return String(res.group_id) === String(filterGroup);
    });

    // Фильтруем роли
    var roles = allRoles.filter(function(role) {
        if (!filterRole) return true;
        return String(role.id) === String(filterRole);
    });

    if (resources.length === 0) {
        tableHead.innerHTML = '<th>Нет ресурсов</th>';
        tableBody.innerHTML = '<tr><td class="text-center py-3 text-muted">Нет ресурсов в выбранной группе</td></tr>';
        return;
    }

    // --- HEAD ---
    var html = '<th style="min-width: 250px;">Ресурс \\ Роль</th>';
    roles.forEach(function(role) {
        html += '<th class="role-head">' + role.name + '</th>';
    });
    tableHead.innerHTML = html;

    // --- BODY ---
    html = '';
    var colspan = roles.length + 1;
    var lastGroupId = null;

    resources.forEach(function(res) {
        // Строка-заголовок группы при смене группы
        if (res.group_id !== lastGroupId) {
            lastGroupId = res.group_id;
            var gTitle = '';
            allGroups.forEach(function(g) {
                if (String(g.id) === String(res.group_id)) gTitle = g.title;
            });
            html += '<tr class="perm-group-row"><td colspan="' + colspan + '">' + (gTitle || 'Без группы') + '</td></tr>';
        }

        html += '<tr data-res="'+res.id+'">';
        html += '<td>' + res.name + ' <small class="text-muted">(' + res.code + ')</small></td>';
        
        roles.forEach(function(role) {
            var p = (permsMap[role.id] && permsMap[role.id][res.id]) ? permsMap[role.id][res.id] : {};
            html += '<td class="perm-cell">'
                 +  '<label title="Просмотр"><input type="checkbox" data-role="'+role.id+'" data-res="'+res.id+'" data-perm="can_view" '+(p.can_view ? 'checked' : '')+'> V</label>'
                 +  '<label title="Добавление"><input type="checkbox" data-role="'+role.id+'" data-res="'+res.id+'" data-perm="can_add" '+(p.can_add ? 'checked' : '')+'> A</label>'
                 +  '<label title="Редактирование"><input type="checkbox" data-role="'+role.id+'" data-res="'+res.id+'" data-perm="can_edit" '+(p.can_edit ? 'checked' : '')+'> E</label>'
                 +  '<label title="Удаление"><input type="checkbox" data-role="'+role.id+'" data-res="'+res.id+'" data-perm="can_del" '+(p.can_del ? 'checked' : '')+'> D</label>'
                 +  '<label title="Выполнение"><input type="checkbox" data-role="'+role.id+'" data-res="'+res.id+'" data-perm="can_exec" '+(p.can_exec ? 'checked' : '')+'> X</label>'
                 +  '</td>';
        });
        html += '</tr>';
    });
    tableBody.innerHTML = html;
}

// ==========================================
// Сохранение
// ==========================================
if (btnSave) {
    btnSave.onclick = function() {
        btnSave.disabled = true;
        showMsg('Сохранение...', 'info');

        var rows = [];
        document.querySelectorAll('#perms-table input[type=checkbox]').forEach(function(cb) {
            rows.push({
                role_id: cb.dataset.role,
                resource_id: cb.dataset.res,
                perm: cb.dataset.perm,
                val: cb.checked ? 1 : 0
            });
        });

        fetch('/mod/acl_permissions/dataAED.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ oper: 'save', rows: rows })
        })
        .then(function(r) { return r.text().then(function(text) { return { ok: r.ok, text: text }; }); })
        .then(function(res) {
            btnSave.disabled = false;
            if (res.ok && res.text.trim() === '') {
                showMsg('Права успешно сохранены', 'success');
            } else {
                showMsg('Ошибка: ' + (res.text || 'HTTP ' + res.status), 'danger');
            }
        })
        .catch(function(err) {
            btnSave.disabled = false;
            showMsg('Сеть: ' + err.message, 'danger');
        });
    };
}

function showMsg(text, kind) {
    var alertClass = kind === 'info' ? 'alert-info' : (kind === 'success' ? 'alert-success' : 'alert-danger');
    msgEl.innerHTML = '<div class="alert ' + alertClass + ' py-2 mb-0">' + text + '</div>';
    if (kind === 'success') {
        setTimeout(function() { msgEl.innerHTML = ''; }, 3000);
    }
}

// Запуск
loadData();
</script>
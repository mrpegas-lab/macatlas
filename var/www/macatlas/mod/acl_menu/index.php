<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">
    <div class="panel mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding: 8px 12px;">
        <div class="d-flex align-items-center gap-3">
            <h5 class="mb-0 fw-bold">📋 Меню</h5>
        </div>
    </div>

    <div class="cmd-layout" style="height: calc(100vh - 140px);">

        <!-- ЛЕВАЯ КОЛОНКА: ФОРМА -->
        <div class="cmd-form">
            <div class="panel cmd-panel" style="height: 100%;">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-pencil-square me-1"></i> Редактирование</div>
                    <span id="menu-mode" class="badge bg-secondary">Ничего не выбрано</span>
                </div>

                <form id="menu-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">
                    <input type="hidden" name="type" value="group">
                    <input type="hidden" name="group_id" value="">

                    <div class="mb-2">
                        <label class="form-label small">Название <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="200">
                    </div>

                    <div class="mb-2" id="menu-code-wrap" style="display:none;">
                        <label class="form-label small">Код (папка модуля) <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control form-control-sm"
                               maxlength="64" pattern="[a-z0-9_]+" placeholder="hardware">
                        <div class="form-text small">/mod/&lt;code&gt;/</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Сортировка</label>
                        <input type="number" name="sort" class="form-control form-control-sm" value="0">
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="menu-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: ДВЕ ТАБЛИЦЫ -->
        <div class="cmd-table" style="display: flex; flex-direction: column; gap: 14px; height: 100%;">

            <!-- Группы -->
            <div class="panel" style="flex: 1; display: flex; flex-direction: column; min-height: 0; padding: 0; overflow: hidden;">
                <div class="panel d-flex justify-content-between align-items-center" style="padding: 8px 12px; border-bottom: 1px solid var(--panel-border); background: var(--panel-bg); flex-shrink: 0;">
                    <div class="fw-semibold"><i class="bi bi-folder2 me-1"></i> Группы</div>
                    <?php if ($can_add): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btn-new-group">
                            <i class="bi bi-plus-lg"></i> Новая
                        </button>
                    <?php endif; ?>
                </div>
                <div style="flex: 1; min-height: 0; overflow: auto;">
                    <table id="menu-groups-table" class="table table-sm table-hover w-100 mb-0"></table>
                </div>
            </div>

            <!-- Пункты -->
            <div class="panel" style="flex: 1; display: flex; flex-direction: column; min-height: 0; padding: 0; overflow: hidden;">
                <div class="panel d-flex justify-content-between align-items-center" style="padding: 8px 12px; border-bottom: 1px solid var(--panel-border); background: var(--panel-bg); flex-shrink: 0;">
                    <div class="fw-semibold"><i class="bi bi-list-ul me-1"></i> Пункты <span id="menu-group-label" class="text-muted small"></span></div>
                    <?php if ($can_add): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btn-new-item" disabled>
                            <i class="bi bi-plus-lg"></i> Новый
                        </button>
                    <?php endif; ?>
                </div>
                <div style="flex: 1; min-height: 0; overflow: auto;">
                    <table id="menu-items-table" class="table table-sm table-hover w-100 mb-0"></table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
var menuSelectedGroupId = null;
var groupsTable = null;
var itemsTable  = null;

var form   = document.getElementById('menu-form');
var msgEl  = document.getElementById('menu-msg');
var modeEl = document.getElementById('menu-mode');
var btnDel = document.getElementById('btn-del');
var btnSave = document.getElementById('btn-save');

// Права из data-атрибутов формы (без права del кнопка не активируется)
var CAN_ADD  = form.getAttribute('data-can-add')  === '1';
var CAN_EDIT = form.getAttribute('data-can-edit') === '1';
var CAN_DEL  = form.getAttribute('data-can-del')  === '1';

// ==========================================
// Инициализация таблиц
// ==========================================
function initGroupsTable() {
    groupsTable = new DataTable('#menu-groups-table', {
        ajax: { url: '/mod/acl_menu/data.php?what=groups', type: 'POST' },
        serverSide: false, paging: false, searching: false, info: false,
        order: [[1, 'asc']],
        columns: [
            { data: 'id',    visible: false },
            { data: 'title', title: 'Название' },
            { data: 'sort',  title: 'Сорт.', width: '60px', className: 'text-center' }
        ],
        language: { url: '/plugins/DataTables/ru.json' }
    });

    document.querySelector('#menu-groups-table').addEventListener('click', function (e) {
        var tr = e.target.closest('tr'); if (!tr) return;
        var row = groupsTable.row(tr); if (!row.data()) return;

        var prev = document.querySelector('#menu-groups-table tr.at-selected');
        if (prev) prev.classList.remove('at-selected');
        tr.classList.add('at-selected');

        menuSelectedGroupId = row.data().id;
        document.getElementById('menu-group-label').textContent = '— ' + row.data().title;
        document.getElementById('btn-new-item').disabled = false;

        var prevItem = document.querySelector('#menu-items-table tr.at-selected');
        if (prevItem) prevItem.classList.remove('at-selected');

        form.reset();
        form.elements['id'].value   = row.data().id;
        form.elements['type'].value = 'group';
        form.elements['name'].value = row.data().title;
        form.elements['sort'].value = row.data().sort;
        document.getElementById('menu-code-wrap').style.display = 'none';

        modeEl.textContent = 'Группа #' + row.data().id;
        modeEl.className   = 'badge bg-warning text-dark';
        btnDel.disabled = !CAN_DEL;

        loadMenuItems(row.data().id);
        clearMsg();
    });
}

function loadMenuItems(groupId) {
    if (itemsTable) { itemsTable.destroy(); document.getElementById('menu-items-table').innerHTML = ''; }

    itemsTable = new DataTable('#menu-items-table', {
        ajax: { url: '/mod/acl_menu/data.php?what=items&group_id=' + groupId, type: 'POST' },
        serverSide: false, paging: false, searching: false, info: false,
        order: [[2, 'asc']],
        columns: [
            { data: 'id',    visible: false },
            { data: 'code',  title: 'Код' },
            { data: 'name',  title: 'Название' },
            { data: 'sort',  title: 'Сорт.', width: '60px', className: 'text-center' },
            { data: 'group_id', visible: false }
        ],
        language: { url: '/plugins/DataTables/ru.json' }
    });

    document.querySelector('#menu-items-table').addEventListener('click', function (e) {
        var tr = e.target.closest('tr'); if (!tr) return;
        var row = itemsTable.row(tr); if (!row.data()) return;

        var prev = document.querySelector('#menu-items-table tr.at-selected');
        if (prev) prev.classList.remove('at-selected');
        tr.classList.add('at-selected');

        var prevGroup = document.querySelector('#menu-groups-table tr.at-selected');
        if (prevGroup) prevGroup.classList.remove('at-selected');

        var d = row.data();
        form.reset();
        form.elements['id'].value   = d.id;
        form.elements['type'].value = 'item';
        form.elements['name'].value = d.name;
        form.elements['code'].value = d.code;
        form.elements['sort'].value = d.sort;
        form.elements['group_id'].value = d.group_id;
        document.getElementById('menu-code-wrap').style.display = '';

        modeEl.textContent = 'Пункт #' + d.id;
        modeEl.className   = 'badge bg-warning text-dark';
        btnDel.disabled = !CAN_DEL;
    });
}

// ==========================================
// Кнопки «Новый»
// ==========================================
document.getElementById('btn-new-group').onclick = function () {
    document.querySelectorAll('#menu-groups-table tr.at-selected, #menu-items-table tr.at-selected')
        .forEach(function (t) { t.classList.remove('at-selected'); });
    form.reset();
    form.elements['id'].value = '';
    form.elements['type'].value = 'group';
    form.elements['group_id'].value = '';
    document.getElementById('menu-code-wrap').style.display = 'none';
    modeEl.textContent = 'Новая группа';
    modeEl.className = 'badge bg-secondary';
    btnDel.disabled = true;
    document.getElementById('btn-new-item').disabled = true;
    menuSelectedGroupId = null;
    document.getElementById('menu-group-label').textContent = '';
    if (itemsTable) { itemsTable.destroy(); document.getElementById('menu-items-table').innerHTML = ''; itemsTable = null; }
    clearMsg();
};

document.getElementById('btn-new-item').onclick = function () {
    if (!menuSelectedGroupId) return;
    document.querySelectorAll('#menu-items-table tr.at-selected').forEach(function (t) { t.classList.remove('at-selected'); });
    form.reset();
    form.elements['id'].value = '';
    form.elements['type'].value = 'item';
    form.elements['group_id'].value = menuSelectedGroupId; // <-- ВОТ ОНО
    document.getElementById('menu-code-wrap').style.display = '';
    modeEl.textContent = 'Новый пункт';
    modeEl.className = 'badge bg-secondary';
    btnDel.disabled = true;
    clearMsg();
};

// ==========================================
// Сохранить
// ==========================================
form.onsubmit = function (e) {
    e.preventDefault();
    var isEdit = form.elements['id'].value !== '';
    btnSave.disabled = true;

    var fd = new FormData(form);
    fd.append('oper', isEdit ? 'edit' : 'add');

    fetch('/mod/acl_menu/dataAED.php', { method: 'POST', body: fd })
        .then(function (r) {
            return r.text().then(function (text) {
                btnSave.disabled = false;
                if (r.ok && text.trim() === '') {
                    showMsg(isEdit ? 'Сохранено' : 'Добавлено', 'success');
                    groupsTable.ajax.reload(null, false);
                    if (itemsTable) itemsTable.ajax.reload(null, false);
                } else {
                    showMsg('Ошибка: ' + (text || r.status), 'danger');
                }
            });
        })
        .catch(function (err) { btnSave.disabled = false; showMsg('Сеть: ' + err.message, 'danger'); });
};

// ==========================================
// Удалить
// ==========================================
btnDel.onclick = function () {
    var id = form.elements['id'].value;
    var type = form.elements['type'].value;
    if (!id) return;

    var msg = (type === 'group')
        ? 'Удалить группу? Все её пункты и права будут удалены!'
        : 'Удалить пункт #' + id + '?';
    if (!confirm(msg)) return;

    var fd = new FormData();
    fd.append('oper', 'del');
    fd.append('id', id);
    fd.append('type', type);

    fetch('/mod/acl_menu/dataAED.php', { method: 'POST', body: fd })
        .then(function (r) {
            return r.text().then(function (text) {
                if (r.ok && text.trim() === '') {
                    showMsg('Удалено', 'success');
                    groupsTable.ajax.reload(null, false);
                    if (itemsTable) itemsTable.ajax.reload(null, false);
                    form.reset();
                    form.elements['id'].value = '';
                    form.elements['type'].value = 'group';
                    form.elements['group_id'].value = '';
                    modeEl.textContent = 'Ничего не выбрано';
                    modeEl.className = 'badge bg-secondary';
                    btnDel.disabled = true;
                } else {
                    showMsg('Ошибка: ' + (text || r.status), 'danger');
                }
            });
        })
        .catch(function (err) { showMsg('Сеть: ' + err.message, 'danger'); });
};

function showMsg(t, k) {
    msgEl.innerHTML = '<div class="alert alert-' + k + ' py-2 mb-0">' + t + '</div>';
    if (k === 'success') setTimeout(clearMsg, 3000);
}
function clearMsg() { msgEl.innerHTML = ''; }

initGroupsTable();
</script>
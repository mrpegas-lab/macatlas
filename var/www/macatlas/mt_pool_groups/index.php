<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <!-- === ЛЕВАЯ КОЛОНКА: ФОРМА === -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование группы</div>
                    <span id="mt_pool_groups-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="mt_pool_groups-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Код (системный) <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control form-control-sm" required maxlength="32" placeholder="msk, spb, reg...">
                        <div class="form-text small">Только латиница, без пробелов</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Название <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="64" placeholder="Москва, Питер...">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Сортировка</label>
                        <input type="number" name="sort" class="form-control form-control-sm" value="100">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="enabled" value="1" id="group_enabled" checked>
                        <label class="form-check-label small" for="group_enabled">Активна</label>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="mt_pool_groups-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">🌍 Группы pools</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="mt_pool_groups-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по названию..."
                           oninput="applyGroupFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="mt_pool_groups-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    window.mt_pool_groups = new CrudModule({
        prefix:   'mt_pool_groups',
        tableUrl: '/mod/mt_pool_groups/data.php',
        formUrl:  '/mod/mt_pool_groups/dataAED.php',
        order:    [[3, 'asc']], // Сортировка по полю sort
        columns: [
            { data: 'id',      title: 'ID',       visible: false },
            { data: 'code',    title: 'Код',      width: '100px' },
            { data: 'name',    title: 'Название' },
            { data: 'sort',    title: 'Сортировка', className: 'text-center', width: '100px' },
            { data: 'enabled', title: 'Активна',  className: 'text-center', width: '80px',
                render: function(v) { return v == 1 ? '<i class="bi bi-check-lg text-success"></i>' : ''; }
            },
            { data: 'pools_count',     title: 'Пулов',   className: 'text-center', width: '80px' },
            { data: 'addresses_count', title: 'Адресов', className: 'text-center', width: '90px' }
        ],
        formFields: ['id', 'code', 'name', 'sort', 'enabled']
    });
})();

function applyGroupFilter() {
    var mod = window.mt_pool_groups;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('mt_pool_groups-filter').value).draw();
}
</script>
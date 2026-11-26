<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <!-- ЛЕВАЯ КОЛОНКА (ФОРМА) -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="model_type-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="model_type-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Название <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               maxlength="255" required
                               placeholder="Свич, Роутер, ...">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Описание</label>
                        <textarea name="description" class="form-control form-control-sm" rows="3"></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="model_type-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА (ТАБЛИЦА) -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">🏷 Типы устройств</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="model_type-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по типу..."
                           oninput="applyModelTypeFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="model_type-table" class="table table-hover w-100"></table>
        </div>
    </div>
</div>

<script>
(function() {
    window.model_type = new CrudModule({
        prefix:   'model_type',
        tableUrl: '/mod/model_type/data.php',
        formUrl:  '/mod/model_type/dataAED.php',
        order:    [[1, 'asc']],
        columns: [
            { data: 'id',          title: 'ID',       visible: false },
            { data: 'name',        title: 'Название' },
            { data: 'description', title: 'Описание' },
            { data: 'model_count', title: 'Моделей', className: 'text-center', width: '110px' }
        ],
        formFields: ['id', 'name', 'description']
    });

    setTimeout(function() {
        var dt = window.model_type.table;
        if (dt) {
            dt.select.style('single');
        }
    }, 100);
})();

function applyModelTypeFilter() {
    var mod = window.model_type;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('model_type-filter').value).draw();
}
</script>
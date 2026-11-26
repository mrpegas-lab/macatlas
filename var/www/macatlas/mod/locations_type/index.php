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
                    <div class="fw-semibold">Редактирование</div>
                    <span id="locations_type-mode" class="badge bg-secondary">Новая запись</span>
                </div>
                
                <form id="locations_type-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Название типа <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="200">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Порядок (сортировка)</label>
                        <input type="number" name="sort" class="form-control form-control-sm" min="0" step="1" placeholder="0">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Описание</label>
                        <textarea name="description" class="form-control form-control-sm" rows="1" maxlength="1000" style="height: auto; min-height: 31px;"></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="locations_type-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">📑 Типы локаций</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="locations_type-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по типу..."
                           oninput="applyLocationsTypeFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>
            
            <table id="locations_type-table" class="table table-hover table-bordered"></table>
        </div>

    </div>
</div>

<script>
(function() {
    window.locations_type = new CrudModule({
        prefix:   'locations_type',
        tableUrl: '/mod/locations_type/data.php',
        formUrl:  '/mod/locations_type/dataAED.php',
        order:    [[2, 'asc']],
        columns: [
            { data: 'id',          title: 'ID',     visible: false },
            { data: 'name',        title: 'Название типа' },
            { data: 'sort',        title: 'Порядок', width: '80px', className: 'text-center' },
            { data: 'description', title: 'Описание' }
        ],
        formFields: ['id', 'name', 'sort', 'description']
    });
})();

function applyLocationsTypeFilter() {
    var mod = window.locations_type;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('locations_type-filter').value).draw();
}
</script>
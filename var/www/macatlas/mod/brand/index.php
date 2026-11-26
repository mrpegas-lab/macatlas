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
                    <span id="brand-mode" class="badge bg-secondary">Новая запись</span>
                </div>
                
                <form id="brand-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-3">
                        <label class="form-label small">Бренд <span class="text-danger">*</span></label>
                        <input type="text" name="brand_name" class="form-control form-control-sm" required maxlength="200">
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="brand-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">🏷 Бренды</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="brand-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по бренду..."
                           oninput="applyBrandFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>
            
            <table id="brand-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    window.brand = new CrudModule({
        prefix:   'brand',
        tableUrl: '/mod/brand/data.php',
        formUrl:  '/mod/brand/dataAED.php',
        order:    [[1, 'asc']],   // brand_name
        columns: [
            { data: 'id',          title: 'ID',      visible: false },
            { data: 'brand_name',  title: 'Бренд' },
            { data: 'model_count', title: 'Моделей', className: 'text-center', width: '90px' }
        ],
        formFields: ['id', 'brand_name']
    });
})();

function applyBrandFilter() {
    var mod = window.brand;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('brand-filter').value).draw();
}
</script>
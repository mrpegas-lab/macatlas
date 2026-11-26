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
                    <div class="fw-semibold">Редактирование типа</div>
                    <span id="mt_pool_purposes-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="mt_pool_purposes-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Код (системный) <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control form-control-sm" required maxlength="32" placeholder="lan, ipip, lo...">
                        <div class="form-text small">Например: lan, ip_video, sstp</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Название <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="64" placeholder="LAN сеть салона...">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Тип <span class="text-danger">*</span></label>
                        <select name="kind" class="form-select form-select-sm" required>
                            <option value="subnet">subnet (Подсеть)</option>
                            <option value="host">host (Одиночный хост)</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Маска по умолчанию <span class="text-danger">*</span></label>
                        <input type="number" name="default_bits" class="form-control form-control-sm" required min="1" max="32" value="24">
                        <div class="form-text small">Например: 24 (для LAN), 31 (для туннелей), 32 (для хостов)</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Иконка (Bootstrap Icons)</label>
                        <input type="text" name="icon" class="form-control form-control-sm" maxlength="32" placeholder="bi-ethernet">
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="mt_pool_purposes-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">🏷️ Назначения pools</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="mt_pool_purposes-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по названию..."
                           oninput="applyPurposeFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="mt_pool_purposes-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    window.mt_pool_purposes = new CrudModule({
        prefix:   'mt_pool_purposes',
        tableUrl: '/mod/mt_pool_purposes/data.php',
        formUrl:  '/mod/mt_pool_purposes/dataAED.php',
        order:    [[0, 'asc']], 
        columns: [
            { data: 'id',           title: 'ID', visible: false },
            { data: 'code',         title: 'Код',      width: '100px' },
            { data: 'name',         title: 'Название' },
            { data: 'kind',         title: 'Тип',      width: '100px' },
            { data: 'default_bits', title: 'Маска',    className: 'text-center', width: '80px' },
            { data: 'icon',         title: 'Иконка',   className: 'text-center', width: '80px',
                render: function(v) { return v ? '<i class="bi ' + v + '"></i>' : ''; }
            },
            { data: 'pools_count',     title: 'Пулов',   className: 'text-center', width: '80px',  orderable: false },
            { data: 'addresses_count', title: 'Адресов', className: 'text-center', width: '90px',  orderable: false }
        ],
        formFields: ['id', 'code', 'name', 'kind', 'default_bits', 'icon']
    });
})();

function applyPurposeFilter() {
    var mod = window.mt_pool_purposes;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('mt_pool_purposes-filter').value).draw();
}
</script>
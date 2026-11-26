<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// Список брендов для <select>
$brands = [];
$result = $mysqli->query("SELECT id, brand_name FROM brand ORDER BY brand_name");
while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
    $brands[] = $row;
}

// Список типов устройств
$model_types = [];
$result = $mysqli->query("SELECT id, name FROM model_type ORDER BY name");
while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
    $model_types[] = $row;
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <!-- === ЛЕВАЯ КОЛОНКА: ФОРМА === -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="model-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="model-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Тип устройства</label>
                        <select name="model_type_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach ($model_types as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Бренд <span class="text-danger">*</span></label>
                        <select name="id_brand" class="form-select form-select-sm" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($brands as $b): ?>
                                <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['brand_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Модель <span class="text-danger">*</span></label>
                        <input type="text" name="model_name" class="form-control form-control-sm" required maxlength="200">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Портов</label>
                        <select name="ports" class="form-select form-select-sm">
                            <option value="">—</option>
                            <option>8</option><option>16</option><option>24</option>
                            <option>26</option><option>28</option><option>48</option><option>52</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">PoE портов</label>
                        <select name="poe_ports" class="form-select form-select-sm">
                            <option value="">—</option>
                            <option value="0">Нет</option>
                            <option>8</option><option>16</option><option>24</option><option>48</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="model-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">📦 Модели оборудования</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="model-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по модели..."
                           oninput="applyModelFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="model-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    window.model = new CrudModule({
        prefix:   'model',
        tableUrl: '/mod/model/data.php',
        formUrl:  '/mod/model/dataAED.php',
        order:    [[2, 'asc'], [3, 'asc']],   // model_type_name, brand_name
        columns: [
            { data: 'id',              title: 'ID',          visible: false },
            { data: 'model_type_name', title: 'Тип',         width: '120px' },
            { data: 'brand_name',      title: 'Бренд' },
            { data: 'model_name',      title: 'Модель' },
            { data: 'ports',           title: 'Портов',      className: 'text-center', width: '90px' },
            { data: 'poe_ports',       title: 'PoE портов',  className: 'text-center', width: '110px' },
            { data: 'hw_count',        title: 'Устройств',   className: 'text-center', width: '100px',
              render: function (d) { return d > 0 ? '<b>' + d + '</b>' : '<span class="text-muted">0</span>'; } }
        ],
        formFields: ['id', 'model_type_id', 'id_brand', 'model_name', 'ports', 'poe_ports']
    });
})();

function applyModelFilter() {
    var mod = window.model;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('model-filter').value).draw();
}
</script>
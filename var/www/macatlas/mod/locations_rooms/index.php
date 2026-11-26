<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// === Список локаций ===
$locations = [];
$r = $mysqli->query("SELECT id, name FROM locations ORDER BY name");
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {
    $locations[] = $row;
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">
        
        <!-- === ЛЕВАЯ КОЛОНКА: ФОРМА === -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-pencil-square me-1"></i> Редактирование</div>
                    <span id="locations_rooms-mode" class="badge bg-secondary">Новая запись</span>
                </div>
                
                <form id="locations_rooms-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Локация <span class="text-danger">*</span></label>
                        <select name="locations_id" class="form-select form-select-sm" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($locations as $l): ?>
                                <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Название помещения <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="200">
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="locations_rooms-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">📑 Список помещений</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="locations_rooms-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по помещению..."
                           oninput="applyLocationsRoomsFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>
            
            <table id="locations_rooms-table" class="table table-hover table-bordered"></table>
        </div>

    </div>
</div>

<script>
window.locations_rooms = new CrudModule({
    prefix:   'locations_rooms',
    tableUrl: '/mod/locations_rooms/data.php',
    formUrl:  '/mod/locations_rooms/dataAED.php',
    order:    [[1, 'asc'], [2, 'asc']],
    columns: [
        { data: 'id',             title: 'ID',       visible: false },
        { data: 'locations_name', title: 'Локация' },
        { data: 'name',           title: 'Название помещения' },
        { data: 'hw_count',       title: 'Оборуд.',  className: 'text-center', width: '90px' }
    ],
    formFields: ['id', 'locations_id', 'name']
});

function applyLocationsRoomsFilter() {
    var mod = window.locations_rooms;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('locations_rooms-filter').value).draw();
}
</script>
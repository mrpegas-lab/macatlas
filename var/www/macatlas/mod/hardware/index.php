<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// === Список моделей (brand + model_name) ===
$models = [];
$r = $mysqli->query("SELECT model.id, brand_name, model_name
                     FROM model
                     LEFT JOIN brand ON brand.id = model.id_brand
                     ORDER BY brand_name, model_name");
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {
    $models[] = [
        'id'   => $row['id'],
        'name' => trim($row['brand_name'] . ' ' . $row['model_name'])
    ];
}

// === Список локаций ===
$locations = [];
$r = $mysqli->query("SELECT id, name, new_name FROM locations ORDER BY name");
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {
    $locations[] = [
        'id'   => $row['id'],
        'name' => $row['name'] !== '' ? $row['name'] : $row['new_name']
    ];
}

// === Список кредов ===
$credentials = [];
$r = $mysqli->query("SELECT id, name FROM credentials ORDER BY name");
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {
    $credentials[] = $row;
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">
        
        <!-- ЛЕВАЯ КОЛОНКА (ФОРМА) -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="hardware-mode" class="badge bg-secondary">Новая запись</span>
                </div>
                
                <form id="hardware-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Модель <span class="text-danger">*</span></label>
                        <select name="id_model" class="form-select form-select-sm" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($models as $m): ?>
                                <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Имя</label>
                        <input type="text" name="switch_name" class="form-control form-control-sm" maxlength="200">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">IP адрес</label>
                        <input type="text" name="hardware_ip" class="form-control form-control-sm" maxlength="45">
                    </div>

                    <div class="mb-2 form-check">
                        <input type="hidden" name="poe" id="hardware-poe-hidden" value="0">
                        <input type="checkbox" class="form-check-input" id="hardware-poe"
                               onchange="document.getElementById('hardware-poe-hidden').value = this.checked ? 1 : 0;">
                        <label class="form-check-label small" for="hardware-poe">PoE</label>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Локация</label>
                        <select name="locations_id" id="hardware-locations_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach ($locations as $l): ?>
                                <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Помещение</label>
                        <select name="rooms_id" id="hardware-rooms_id" class="form-select form-select-sm">
                            <option value="">— сначала выберите локацию —</option>
                        </select>
                    </div>

                    <div class="mb-2 form-check">
                        <input type="hidden" name="active" id="hardware-active-hidden" value="0">
                        <input type="checkbox" class="form-check-input" id="hardware-active"
                               onchange="document.getElementById('hardware-active-hidden').value = this.checked ? 1 : 0;">
                        <label class="form-check-label small" for="hardware-active">Active</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Креды</label>
                        <select name="cred_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach ($credentials as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="hardware-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА (ТАБЛИЦА) -->
        <div class="cmd-table" style="display: flex; flex-direction: column; height: 100%;">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px; flex-shrink: 0;">
                <div class="fw-semibold">🖥 Оборудование</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="hardware-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по оборудованию..."
                           oninput="applyHardwareFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>
            
            <!-- Контейнер таблицы со скроллом -->
            <div style="flex-shrink: 0;">
                <table id="hardware-table" class="table table-hover w-100"></table>
            </div>
        </div>
    </div>
</div>

<!-- Подключаем плагин RowGroup для DataTables (если он не подключен глобально) -->
<script src="https://cdn.datatables.net/rowgroup/1.3.1/js/dataTables.rowGroup.min.js"></script>

<script>
(function() {
    // Инициализация CrudModule (он создаст DataTable)
    window.hardware = new CrudModule({
        prefix:   'hardware',
        tableUrl: '/mod/hardware/data.php',
        formUrl:  '/mod/hardware/dataAED.php',
        // Сортировка: Локация (5), Помещение (6), IP (3)
        order:    [[5, 'asc'], [6, 'asc'], [3, 'asc']],
        // Внутренний скролл
        scrollY: 'calc(100vh - 180px)',
        scrollCollapse: true,
        paging: true,
        pageLength: 100, // чтобы таблица не разбивалась каждые 10 строк
        
        columns: [
            { data: 'id',            title: 'ID',       visible: false },
            { data: 'model_full',    title: 'Модель' },
            { data: 'switch_name',   title: 'Имя' },
            { data: 'hardware_ip',   title: 'IP адрес' },
            { data: 'poe',           title: 'PoE',      className: 'text-center', width: '60px',
              render: function (v) { return v == 1 ? '<i class="bi bi-check-lg text-success"></i>' : ''; } },
            { data: 'locations_name',title: 'Локация', visible: false }, // скрыто, т.к. в заголовке
            { data: 'rooms_name',    title: 'Помещение', visible: false }, // скрыто, т.к. в заголовке
            { data: 'active',        title: 'Active',   className: 'text-center', width: '60px',
              render: function (v) { return v == 1 ? '<i class="bi bi-check-lg text-success"></i>' : ''; } },
            { data: 'cred_name',     title: 'Креды' },
            // --- НОВЫЕ ПОЛЯ ---
            { data: 'firmware_version', title: 'Прошивка', defaultContent: '' },
            { data: 'serial_number',    title: 'Серийник', defaultContent: '' },
            { data: 'uptime',           title: 'Uptime', defaultContent: '' },
            { data: 'temperature',      title: 'Темп.', className: 'text-center', defaultContent: '',
              render: function(v) {
                  if (v === null || v === undefined || v === '') return '';
                  var t = parseFloat(v);
                  var cls = t >= 70 ? 'text-danger fw-bold' : (t >= 60 ? 'text-warning' : '');
                  return '<span class="' + cls + '">' + t.toFixed(1) + '°</span>';
              }
            },
            { data: 'poe_budget',       title: 'PoE (W)', className: 'text-center', defaultContent: '',
              render: function(v, type, row) {
                  if (v === null || v === undefined || v === '') return '';
                  var b = parseFloat(v);
                  var u = parseFloat(row.poe_consumption || 0);
                  var pct = b > 0 ? (u / b * 100) : 0;
                  var cls = pct >= 80 ? 'text-danger fw-bold' : (pct >= 60 ? 'text-warning' : '');
                  return '<span class="' + cls + '">' + u.toFixed(0) + ' / ' + b.toFixed(0) + '</span>';
              }
            }
        ],
        formFields: ['id', 'id_model', 'switch_name', 'hardware_ip', 'poe','locations_id', 'rooms_id', 'active', 'cred_id'],
        
        afterRowSelect: function (row) {
            if (row.locations_id) {
                loadRooms(row.locations_id, row.rooms_id);
            } else {
                clearRooms();
            }
            
            setTimeout(function() {
                var isPoe = (row.poe == 1);
                document.getElementById('hardware-poe').checked = isPoe;
                document.getElementById('hardware-poe-hidden').value = isPoe ? 1 : 0;

                var isActive = (row.active == 1);
                document.getElementById('hardware-active').checked = isActive;
                document.getElementById('hardware-active-hidden').value = isActive ? 1 : 0;
            }, 50);
        },
        
        afterReset: function () {
            clearRooms();
            setTimeout(function() {
                document.getElementById('hardware-poe').checked = false;
                document.getElementById('hardware-poe-hidden').value = 0;

                document.getElementById('hardware-active').checked = false;
                document.getElementById('hardware-active-hidden').value = 0;
            }, 50);
        }
    });

    // Настраиваем RowGroup и селект после инициализации
    setTimeout(function() {
        var dt = window.hardware.table;
        if (dt) {
            dt.select.style('single');
            
            // Включаем группировку: по Локации, затем по Помещению
            dt.rowGroup().dataSrc(['locations_name', 'rooms_name']).enable();
            
            // Перерисовываем, чтобы применить группировку к загруженным данным
            dt.draw(false);
        }
    }, 200);

    var locationsSelect = document.getElementById('hardware-locations_id');
    var roomsSelect     = document.getElementById('hardware-rooms_id');

    locationsSelect.onchange = function () {
        var locId = this.value;
        if (locId) {
            loadRooms(locId, null);
        } else {
            clearRooms();
        }
    };

    function loadRooms(locationsId, selectedRoomId) {
        var fd = new FormData();
        fd.append('locations_id', locationsId);
        fetch('/mod/locations_rooms/get_rooms_by_location.php', { method: 'POST', body: fd })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                roomsSelect.innerHTML = html;
                if (selectedRoomId) roomsSelect.value = selectedRoomId;
            });
    }

    function clearRooms() {
        roomsSelect.innerHTML = '<option value="">— сначала выберите локацию —</option>';
    }
})();

function applyHardwareFilter() {
    var mod = window.hardware;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('hardware-filter').value).draw();
}
</script>
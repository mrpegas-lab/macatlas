<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// === Типы локаций ===
$locationTypes = [];
$r = $mysqli->query("SELECT id, name FROM locations_type ORDER BY name");
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {
    $locationTypes[] = $row;
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">
        
        <!-- === ЛЕВАЯ КОЛОНКА: ФОРМА === -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="locations-mode" class="badge bg-secondary">Новая запись</span>
                </div>
                
                <form id="locations-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Название <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="200">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Код</label>
                        <input type="text" name="code" class="form-control form-control-sm" maxlength="50">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Имя (для MikroTik)</label>
                        <input type="text" name="new_name" class="form-control form-control-sm" maxlength="200">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Адрес</label>
                        <textarea name="address" class="form-control form-control-sm" rows="1" maxlength="500" style="height: auto; min-height: 31px;"></textarea>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">GPS</label>
                        <input type="text" name="gps" class="form-control form-control-sm" maxlength="100">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">МСК+</label>
                        <select name="tz_offset_msk" class="form-select form-select-sm">
                            <option value="0">0</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                            <option value="7">7</option>
                        </select>
                    </div>

                    <!-- === Расписание работы === -->
                    <div class="mb-2">
                        <label class="form-label small">Расписание работы</label>
                        <div id="schedule-editor" class="border rounded p-2" style="border-color: #3d3d3d !important;"></div>
                        <input type="hidden" name="work_schedule" id="schedule-json">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Тип</label>
                        <select name="type_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach ($locationTypes as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Комментарий</label>
                        <textarea name="comment" class="form-control form-control-sm" rows="1" maxlength="1000" style="height: auto; min-height: 31px;"></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="locations-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">📍 Локации</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="locations-filter" class="form-control form-control-sm"
                    style="width:250px;" placeholder="Поиск по локациям..."
                    oninput="applyLocationsFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>
            
            <table id="locations-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    // ============================================================
    // 1. Расписание работы
    // ============================================================
    var scheduleEditor = document.getElementById('schedule-editor');
    var scheduleJson   = document.getElementById('schedule-json');

    var DAYS = [
        { key: 'mon', label: 'Пн' },
        { key: 'tue', label: 'Вт' },
        { key: 'wed', label: 'Ср' },
        { key: 'thu', label: 'Чт' },
        { key: 'fri', label: 'Пт' },
        { key: 'sat', label: 'Сб' },
        { key: 'sun', label: 'Вс' }
    ];

    var HOURS_START = ['07', '08', '09', '10', '11'];
    var HOURS_END   = ['12','13','14','15','16','17','18','19','20','21','22','23'];

    function scheduleLoad(jsonText) {
        var data = {};
        if (jsonText) {
            try { data = JSON.parse(jsonText); } catch (e) { data = {}; }
        }

        var expanded = expandDays(data);
        scheduleEditor.innerHTML = '';
        for (var i = 0; i < DAYS.length; i++) {
            var d = DAYS[i];
            var intervals = expanded[d.key] || [];
            var from = intervals.length ? String(intervals[0][0]).substring(0, 2) : '';
            var to   = intervals.length ? String(intervals[0][1]).substring(0, 2) : '';
            addDayRow(d.key, d.label, from, to);
        }
        scheduleSync();
    }

    function addDayRow(key, label, fromHour, toHour) {
        var row = document.createElement('div');
        row.className = 'schedule-day d-flex align-items-center gap-2 mb-1';
        row.setAttribute('data-day', key);

        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'form-check-input schedule-day-on';
        cb.checked = !!(fromHour && toHour);
        row.appendChild(cb);

        var nameEl = document.createElement('div');
        nameEl.style.width = '32px';
        nameEl.className = 'small';
        nameEl.textContent = label;
        row.appendChild(nameEl);

        var selFrom = document.createElement('select');
        selFrom.className = 'form-select form-select-sm schedule-from';
        selFrom.style.maxWidth = '85px';
        var htmlFrom = '<option value="">—</option>';
        for (var i = 0; i < HOURS_START.length; i++) {
            var h = HOURS_START[i];
            htmlFrom += '<option value="' + h + '">' + h + ':00</option>';
        }
        selFrom.innerHTML = htmlFrom;
        selFrom.value = fromHour || '';
        row.appendChild(selFrom);

        var dash = document.createElement('span');
        dash.textContent = '—';
        row.appendChild(dash);

        var selTo = document.createElement('select');
        selTo.className = 'form-select form-select-sm schedule-to';
        selTo.style.maxWidth = '85px';
        var htmlTo = '<option value="">—</option>';
        for (var i = 0; i < HOURS_END.length; i++) {
            var h = HOURS_END[i];
            htmlTo += '<option value="' + h + '">' + h + ':00</option>';
        }
        selTo.innerHTML = htmlTo;
        selTo.value = toHour || '';
        row.appendChild(selTo);

        scheduleEditor.appendChild(row);

        cb.onchange = function () {
            if (!cb.checked) {
                selFrom.value = '';
                selTo.value   = '';
            } else {
                if (!selFrom.value) selFrom.value = '09';
                if (!selTo.value)   selTo.value   = '18';
            }
            scheduleSync();
        };

        selFrom.onchange = scheduleSync;
        selTo.onchange   = scheduleSync;
    }

    function scheduleSync() {
        var order = ['mon','tue','wed','thu','fri','sat','sun'];
        var perDay = {};

        var rows = scheduleEditor.querySelectorAll('.schedule-day');
        for (var i = 0; i < rows.length; i++) {
            var day  = rows[i];
            var key  = day.getAttribute('data-day');
            var cb   = day.querySelector('.schedule-day-on');
            var from = day.querySelector('.schedule-from').value;
            var to   = day.querySelector('.schedule-to').value;

            if (cb.checked && from && to) {
                perDay[key] = [[from + ':00', to + ':00']];
            }
        }

        var result = groupDays(perDay, order);
        scheduleJson.value = Object.keys(result).length ? JSON.stringify(result) : '';
    }

    function expandDays(data) {
        var order = ['mon','tue','wed','thu','fri','sat','sun'];
        var result = {};

        for (var key in data) {
            if (!data.hasOwnProperty(key)) continue;
            var intervals = data[key];

            if (key.indexOf('-') === -1) {
                result[key] = intervals;
                continue;
            }

            var parts = key.split('-');
            var iFrom = order.indexOf(parts[0]);
            var iTo   = order.indexOf(parts[1]);
            if (iFrom === -1 || iTo === -1) continue;

            if (iFrom <= iTo) {
                for (var i = iFrom; i <= iTo; i++) result[order[i]] = intervals;
            } else {
                for (var i = iFrom; i < order.length; i++) result[order[i]] = intervals;
                for (var i = 0; i <= iTo; i++)             result[order[i]] = intervals;
            }
        }
        return result;
    }

    function groupDays(perDay, order) {
        var result = {};
        var i = 0;

        while (i < order.length) {
            var day = order[i];
            if (!perDay[day]) { i++; continue; }

            var time = JSON.stringify(perDay[day]);
            var j = i + 1;
            while (j < order.length && perDay[order[j]] && JSON.stringify(perDay[order[j]]) === time) {
                j++;
            }

            var key;
            if (j - i === 1) {
                key = day;
            } else {
                key = day + '-' + order[j - 1];
            }
            result[key] = perDay[day];
            i = j;
        }
        return result;
    }

    // ============================================================
    // 2. CrudModule
    // ============================================================
    window.locationsModule = new CrudModule({
        prefix:   'locations',
        tableUrl: '/mod/locations/data.php',
        formUrl:  '/mod/locations/dataAED.php',
        order:    [[7, 'asc'], [1, 'asc']],
        columns: [
            { data: 'id',            title: 'ID',         visible: false },
            { data: 'name',          title: 'Название' },
            { data: 'code',          title: 'Код',        width: '80px' },
            { data: 'new_name',      title: 'Имя MT' },
            { data: 'address',       title: 'Адрес',      width: '150px'  },
            { data: 'gps',           title: 'GPS',        width: '140px' },
            { data: 'tz_offset_msk', title: 'МСК+',       width: '60px', className: 'text-center' },
            { data: 'type_name',     title: 'Тип',        width: '120px' },
            { data: 'hw_cnt',        title: 'Оборуд.',    width: '80px', className: 'text-center', orderable: false,
              render: function (v, type, row) {
                  // оборудование локации = hardware + роутеры объектов (mt_sites)
                  var hw    = parseInt(v, 10) || 0;
                  var sites = parseInt(row.sites_cnt, 10) || 0;
                  var total = hw + sites;
                  if (total <= 0) return '<span class="text-muted">—</span>';
                  return '<span title="hardware: ' + hw + ', роутеры объектов: ' + sites + '">'
                         + total + '</span>';
              } },
            { data: 'sites_cnt',     title: 'Привязка',   width: '200px', orderable: false,
              render: function (v, type, row) {
                  // список имён -> первые maxShow + бейдж "+N", полный список в подсказке
                  function chip(list, cls, hint, maxShow) {
                      if (!list) return null;
                      var names = list.split(', ');
                      var shown = names.slice(0, maxShow).join(', ');
                      var rest  = names.length - maxShow;
                      var text  = rest > 0 ? shown + ' +' + rest : shown;
                      return '<span class="badge ' + cls + '" title="' + hint + ': '
                             + list.replace(/"/g, '&quot;') + '">' + text + '</span>';
                  }
                  var out = [];
                  if (parseInt(v, 10) > 0) {
                      out.push('<span class="badge bg-primary">MT-сайт</span>');
                  }
                  var hw  = chip(row.hw_list,  'bg-info text-dark',    'Оборудование', 2);
                  var dev = chip(row.dev_list, 'bg-warning text-dark', 'Устройства MT', 2);
                  if (hw)  out.push(hw);
                  if (dev) out.push(dev);
                  return out.length ? out.join(' ')
                                    : '<span class="text-muted">—</span>';
              } },
            { data: 'comment',       title: 'Комментарий' },
            { data: 'created_at',    title: 'Создано',    width: '150px' },
            { data: 'work_schedule', title: 'Schedule'}
        ],
        formFields: ['id', 'name', 'code', 'new_name', 'address', 'gps',
                     'tz_offset_msk', 'type_id', 'comment'],

        afterRowSelect: function (row) {
            scheduleLoad(row.work_schedule);
        },
        afterReset: function () {
            scheduleLoad('');
        }
    });

})();
function applyLocationsFilter() {
    var dt = new DataTable('#locations-table');
    dt.search(document.getElementById('locations-filter').value).draw();
}
</script>
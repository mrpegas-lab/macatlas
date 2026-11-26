<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// === Список роутеров для каскада ===
$routers = [];
$r = $mysqli->query("SELECT h.id,
                            h.switch_name,
                            h.hardware_ip,
                            CONCAT(IFNULL(b.brand_name,''), ' ', IFNULL(m.model_name,'')) AS model_full
                     FROM hardware h
                     LEFT JOIN model      m ON m.id = h.id_model
                     LEFT JOIN brand      b ON b.id = m.id_brand
                     LEFT JOIN model_type t ON t.id = m.model_type_id
                     WHERE t.name = 'Роутер'
                     ORDER BY h.switch_name, h.hardware_ip");
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {
    $parts = [];
    if ($row['switch_name'] !== '' && $row['switch_name'] !== null) $parts[] = $row['switch_name'];
    if ($row['hardware_ip'] !== '' && $row['hardware_ip'] !== null) $parts[] = $row['hardware_ip'];
    $model_full = trim($row['model_full']);
    if ($model_full !== '') $parts[] = $model_full;
    $label = $parts ? implode(' — ', $parts) : '#' . $row['id'];

    $routers[] = ['id' => $row['id'], 'label' => $label];
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <!-- ЛЕВАЯ КОЛОНКА (ФОРМА) -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="ipam_subnets-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="ipam_subnets-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Роутер <span class="text-danger">*</span></label>
                        <select name="hw_id" id="subnet-hw_id" class="form-select form-select-sm" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($routers as $rt): ?>
                                <option value="<?= (int)$rt['id'] ?>"><?= htmlspecialchars($rt['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">VLAN <span class="text-danger">*</span></label>
                        <select name="vlan_ref_id" id="subnet-vlan_ref_id" class="form-select form-select-sm" required>
                            <option value="">— сначала выберите роутер —</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Сеть <span class="text-danger">*</span></label>
                        <input type="text" name="network" id="subnet-network" class="form-control form-control-sm"
                               placeholder="10.0.5.0" maxlength="15" required>
                        <div class="form-text">Сетевой адрес (например, 10.0.5.0 для /24)</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Префикс <span class="text-danger">*</span></label>
                        <select name="prefix_len" class="form-select form-select-sm" required>
                            <option value="">—</option>
                            <?php for ($i = 8; $i <= 32; $i++): ?>
                                <option value="<?= $i ?>">/<?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Шлюз</label>
                        <input type="text" name="gateway" id="subnet-gateway" class="form-control form-control-sm"
                               placeholder="10.0.5.1" maxlength="15">
                        <div class="form-text">Необязательно</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Описание</label>
                        <textarea name="description" class="form-control form-control-sm" rows="2"
                                  maxlength="255"></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="ipam_subnets-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА (ТАБЛИЦА) -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">🌐 Подсети</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="ipam_subnets-filter" class="form-control form-control-sm"
                    style="width:250px;" placeholder="Поиск по подсетям..."
                    oninput="applyIpamSubnetsFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="ipam_subnets-table" class="table table-hover w-100"></table>
        </div>
    </div>
</div>


<script>
(function() {
    window.ipam_subnets = new CrudModule({
        prefix:   'ipam_subnets',
        tableUrl: '/mod/ipam_subnets/data.php',
        formUrl:  '/mod/ipam_subnets/dataAED.php',
        order:    [[2, 'asc'], [1, 'asc']],

        columns: [
            { data: 'id',           title: 'ID',         visible: false },
            { data: 'cidr',         title: 'Подсеть',    width: '160px' },
            { data: 'hw_label',     title: 'Устройство', visible: false },
            { data: 'vlan_label',   title: 'VLAN' },
            { data: 'gateway_str',  title: 'Шлюз',       width: '130px' },
            { data: 'description',  title: 'Описание' },
            { data: 'occupied',     title: 'Занято',     className: 'text-center', width: '90px', orderable: false },
            { data: 'capacity',     title: 'Ёмкость',    className: 'text-center', width: '90px', orderable: false },
            { data: 'free',         title: 'Свободно',   className: 'text-center', width: '100px',
              orderable: false,
              render: function (v, type, row) {
                  if (type !== 'display') return v;
                  var cap  = parseInt(row.capacity) || 0;
                  var free = parseInt(v) || 0;
                  var cls  = 'text-success';
                  if (cap > 0) {
                      var pct = free / cap;
                      if (pct < 0.1)      cls = 'text-danger';
                      else if (pct < 0.3) cls = 'text-warning';
                  }
                  return '<span class="' + cls + '">' + free + '</span>';
              }
            },
            { data: 'created_at',   title: 'Создан',     width: '150px' },
            { data: 'updated_at',   title: 'Изменён',    width: '150px' }
        ],

        formFields: ['id', 'vlan_ref_id', 'network', 'prefix_len', 'gateway', 'description'],

        afterRowSelect: function (row) {
            var hwSelect   = document.getElementById('subnet-hw_id');
            var vlanSelect = document.getElementById('subnet-vlan_ref_id');

            if (row.hw_id) {
                hwSelect.value = row.hw_id;
                loadVlans(row.hw_id, row.vlan_ref_id);
            } else {
                hwSelect.value = '';
                vlanSelect.innerHTML = '<option value="">— сначала выберите роутер —</option>';
            }

            if (row.network_str) {
                document.getElementById('subnet-network').value = row.network_str;
            }
            if (row.gateway_str !== undefined) {
                document.getElementById('subnet-gateway').value = row.gateway_str || '';
            }
            
            var prefixSelect = document.querySelector('select[name="prefix_len"]');
            if (prefixSelect && row.prefix_len) {
                prefixSelect.value = row.prefix_len;
            }
        },

        afterReset: function () {
            document.getElementById('subnet-hw_id').value = '';
            document.getElementById('subnet-vlan_ref_id').innerHTML =
                '<option value="">— сначала выберите роутер —</option>';
        }
    });

    // === ПРИНУДИТЕЛЬНОЕ ВКЛЮЧЕНИЕ ROWGROUP ===
    setTimeout(function() {
        var dt = ipam_subnets.table;
        
        if (dt) {
            // Включаем выделение строк
            if (dt.select && dt.select.style) {
                dt.select.style('single');
            }

            // Если rowGroup поддерживается, но не включился через CrudModule
            // Попробуем инициализировать его вручную, если это возможно.
            if (typeof dt.rowGroup === 'function') {
                dt.rowGroup().dataSrc('hw_label').enable();
                dt.draw(false);
            } 
            // Запасной вариант: если dt.rowGroup нет, пробуем пересоздать настройку
            else {
                // Пытаемся достучаться до настроек (в некоторых версиях DataTables)
                var settings = dt.settings()[0];
                if (settings && !settings.rowGroup) {
                    // Если плагин RowGroup подключен глобально, он должен перехватить это
                    try {
                        new DataTable.RowGroup(dt, { dataSrc: 'hw_label' });
                    } catch(e) {
                        console.warn('RowGroup не поддерживается в этой сборке DataTables');
                    }
                }
            }
        }
    }, 300); // Небольшая задержка, чтобы таблица точно инициализировалась

    // === Каскад: роутер → VLAN ===
    var hwSelect   = document.getElementById('subnet-hw_id');
    var vlanSelect = document.getElementById('subnet-vlan_ref_id');

    if (hwSelect) {
        hwSelect.onchange = function () {
            if (this.value) {
                loadVlans(this.value, null);
            } else {
                vlanSelect.innerHTML = '<option value="">— сначала выберите роутер —</option>';
            }
        };
    }

    function loadVlans(hwId, selectedVlanId) {
        var fd = new FormData();
        fd.append('hw_id', hwId);
        fetch('/mod/ipam_subnets/get_vlans_by_hw.php', { method: 'POST', body: fd })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                vlanSelect.innerHTML = html;
                if (selectedVlanId) vlanSelect.value = selectedVlanId;
            });
    }
})();
function applyIpamSubnetsFilter() {
    var mod = window.ipam_subnets;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('ipam_subnets-filter').value).draw();
}
</script>

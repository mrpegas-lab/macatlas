<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// Список роутеров (для каскада)
$routers = [];
$r = $mysqli->query("SELECT h.id, h.switch_name, h.hardware_ip
                     FROM hardware h
                     JOIN model m ON m.id = h.id_model
                     JOIN model_type t ON t.id = m.model_type_id
                     WHERE t.name = 'Роутер'
                     ORDER BY h.switch_name");
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {
    $routers[] = ['id' => $row['id'], 'label' => $row['switch_name'] . ' (' . $row['hardware_ip'] . ')'];
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <!-- ЛЕВАЯ КОЛОНКА (ФОРМА) -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="ipam_addresses-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="ipam_addresses-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Роутер <span class="text-danger">*</span></label>
                        <select name="hw_id" id="addr-hw_id" class="form-select form-select-sm" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($routers as $rt): ?>
                                <option value="<?= (int)$rt['id'] ?>"><?= htmlspecialchars($rt['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">VLAN <span class="text-danger">*</span></label>
                        <select name="vlan_ref_id" id="addr-vlan_id" class="form-select form-select-sm" required>
                            <option value="">— сначала выберите роутер —</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Подсеть <span class="text-danger">*</span></label>
                        <select name="subnet_id" id="addr-subnet_id" class="form-select form-select-sm" required>
                            <option value="">— сначала выберите VLAN —</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">IP-адрес <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="ip" id="addr-ip" class="form-control" placeholder="10.0.5.10" maxlength="15" required>
                            <button type="button" class="btn btn-outline-primary" id="btn-free-ip" title="Занять первый свободный IP">
                                <i class="bi bi-magic"></i> Свободный
                            </button>
                        </div>
                        <div class="form-text">Введите вручную или нажмите «Свободный»</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Статус</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="assigned">Assigned (Занят)</option>
                            <option value="reserved">Reserved (Резерв)</option>
                            <option value="conflict">Conflict (Конфликт)</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Hostname (Имя устройства)</label>
                        <input type="text" name="hostname" class="form-control form-control-sm" maxlength="255" placeholder="pc-user-01">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">MAC-адрес</label>
                        <input type="text" name="mac" class="form-control form-control-sm" maxlength="17" placeholder="AA:BB:CC:DD:EE:FF">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Описание</label>
                        <textarea name="description" class="form-control form-control-sm" rows="1" maxlength="200" style="height: auto; min-height: 60px; resize: none;"></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="ipam_addresses-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА (ТАБЛИЦА) -->
        <div class="cmd-table">
            <!-- Панель управления таблицей -->
            <div class="panel mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding: 8px 12px;">
                <div class="fw-semibold">📍 IP Адреса</div>
                
                <div class="d-flex align-items-center gap-2">
                    <!-- Новое поле поиска -->
                    <input type="text" id="ipam-search-input" class="form-control form-control-sm" style="width: 250px;" placeholder="Поиск IP, MAC, Hostname...">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="ipam-search-reset" title="Сброс">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="ipam_addresses-table" class="table table-hover w-100"></table>
        </div>
    </div>
</div>

<script>
(function() {
    var ipam_addresses = new CrudModule({
        prefix:   'ipam_addresses',
        tableUrl: '/mod/ipam_addresses/data.php',
        formUrl:  '/mod/ipam_addresses/dataAED.php',
        order:    [[1, 'asc'], [2, 'asc']],   // подсеть, потом IP
        searching: false,
        columns: [
            { data: 'id',           title: 'ID',         visible: false },
            { data: 'subnet_label', title: 'Подсеть',    visible: false }, // скрываем для заголовка группы
            { data: 'ip_str',       title: 'IP-адрес',   width: '140px' },
            { data: 'ip_source', title: 'Тип IP', width: '120px',
              render: function(v) {
                  if (v === 'dhcp_dynamic') return '<span class="badge bg-info text-dark">DHCP (динам.)</span>';
                  if (v === 'dhcp_static')  return '<span class="badge bg-primary">DHCP (стат.)</span>';
                  if (v === 'arp_only')     return '<span class="badge bg-warning text-dark">Статика (ARP)</span>';
                  return '<span class="text-muted">—</span>';
              }
            },
            { data: 'status',       title: 'Статус',     className: 'text-center', width: '100px',
              render: function(v) {
                  if (v === 'assigned') return '<span class="badge bg-success">Занят</span>';
                  if (v === 'reserved') return '<span class="badge bg-warning text-dark">Резерв</span>';
                  if (v === 'conflict') return '<span class="badge bg-danger">Конфликт</span>';
                  if (v === 'offline')  return '<span class="badge bg-secondary">Оффлайн</span>';
                  return v;
              }
            },
            { data: 'hostname',     title: 'Hostname' }, // вместо устройства
            { data: 'mac',          title: 'MAC' },
            { data: 'description',  title: 'Описание' },
            { data: 'updated_at',   title: 'Изменён',    width: '150px' }
        ],
        formFields: ['id', 'subnet_id', 'ip', 'status', 'hostname', 'mac', 'description'],

        afterRowSelect: function (row) {
            var hwSelect   = document.getElementById('addr-hw_id');
            var vlanSelect = document.getElementById('addr-vlan_id');
            var subSelect  = document.getElementById('addr-subnet_id');

            if (row.hw_id) {
                hwSelect.value = row.hw_id;
                
                // Дожидаемся загрузки VLAN'ов и Подсетей, и только потом ставим значение
                loadVlans(row.hw_id).then(function() {
                    if (row.vlan_ref_id) {
                        vlanSelect.value = row.vlan_ref_id;
                        return loadSubnets(row.vlan_ref_id);
                    }
                }).then(function() {
                    if (row.subnet_id) {
                        subSelect.value = row.subnet_id;
                    }
                });
                
            } else {
                hwSelect.value = '';
                vlanSelect.innerHTML = '<option value="">— сначала выберите роутер —</option>';
                subSelect.innerHTML = '<option value="">— сначала выберите VLAN —</option>';
            }
            
            document.getElementById('addr-ip').value = row.ip_str || '';
        },

        afterReset: function () {
            document.getElementById('addr-hw_id').value = '';
            document.getElementById('addr-vlan_id').innerHTML = '<option value="">— сначала выберите роутер —</option>';
            document.getElementById('addr-subnet_id').innerHTML = '<option value="">— сначала выберите VLAN —</option>';
        }
    });

    // Принудительное включение группировки по подсети
    setTimeout(function() {
        var dt = ipam_addresses.table;
        if (dt) {
            if (dt.select && dt.select.style) dt.select.style('single');

            if (typeof dt.rowGroup === 'function') {
                dt.rowGroup().dataSrc('subnet_label').enable();
                dt.draw(false);
            }
        }
    }, 300);

    // === Каскады ===
    var hwSelect   = document.getElementById('addr-hw_id');
    var vlanSelect = document.getElementById('addr-vlan_id');
    var subSelect  = document.getElementById('addr-subnet_id');

    hwSelect.onchange = function () {
        if (this.value) {
            loadVlans(this.value);
        } else {
            vlanSelect.innerHTML = '<option value="">— сначала выберите роутер —</option>';
            subSelect.innerHTML = '<option value="">— сначала выберите VLAN —</option>';
        }
    };

    vlanSelect.onchange = function () {
        if (this.value) {
            loadSubnets(this.value);
        } else {
            subSelect.innerHTML = '<option value="">— сначала выберите VLAN —</option>';
        }
    };

    // === Кнопка поиска свободного IP ===
    document.getElementById('btn-free-ip').onclick = function() {
        var subnetId = subSelect.value;
        if (!subnetId) {
            alert('Сначала выберите подсеть!');
            return;
        }

        var btn = this;
        var originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        btn.disabled = true;

        var fd = new FormData();
        fd.append('subnet_id', subnetId);
        
        fetch('/mod/ipam_addresses/get_free_ip.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                if (data.ip) {
                    document.getElementById('addr-ip').value = data.ip;
                } else {
                    alert(data.error || 'Нет свободных адресов в этой подсети');
                }
            })
            .catch(function() {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                alert('Ошибка при запросе');
            });
    };

    // Теперь функции возвращают Promise, чтобы можно было выстроить цепочку
    function loadVlans(hwId) {
        return new Promise(function(resolve) {
            var fd = new FormData();
            fd.append('hw_id', hwId);
            fetch('/mod/ipam_addresses/get_vlans.php', { method: 'POST', body: fd })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    vlanSelect.innerHTML = html;
                    resolve(); // Сообщаем, что загрузка завершена
                });
        });
    }

    function loadSubnets(vlanId) {
        return new Promise(function(resolve) {
            var fd = new FormData();
            fd.append('vlan_id', vlanId);
            fetch('/mod/ipam_addresses/get_subnets.php', { method: 'POST', body: fd })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    subSelect.innerHTML = html;
                    resolve(); // Сообщаем, что загрузка завершена
                });
        });
    }
    // === Поиск по таблице (на стороне клиента) ===
    setTimeout(function() {
        var dt = ipam_addresses.table;
        var searchInput = document.getElementById('ipam-search-input');
        var searchReset = document.getElementById('ipam-search-reset');

        if (dt && searchInput) {
            // Очищаем предыдущий поиск (на всякий случай)
            dt.search('');

            searchInput.addEventListener('input', function() {
                // DataTables будет искать по тем данным, которые УЖЕ загружены на страницу
                dt.search(this.value).draw();
            });

            searchReset.onclick = function() {
                searchInput.value = '';
                dt.search('').draw();
            };
        }
    }, 300);
})();
</script>
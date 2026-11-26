<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
// Права передаём в JS, чтобы кнопки без прав были неактивны
?>
<script>
var CAN_ADD  = <?= $can_add  ? 'true' : 'false' ?>;
var CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;
var CAN_DEL  = <?= $can_del  ? 'true' : 'false' ?>;
</script>

<div class="container-fluid py-1 px-2">
    <div class="cmd-layout">

        <!-- ЛЕВАЯ КОЛОНКА: ВКЛАДКИ (Редактор, Конфиг, Статистика и т.д.) -->
        <div class="cmd-form" style="width: 40%; flex: 0 0 40%;">
            <div class="panel cmd-panel p-2" style="height: calc(100vh - 100px);">

                <!-- Вкладки -->
                <ul class="nav nav-tabs mb-1" id="configTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="edit-tab" data-bs-toggle="tab" data-bs-target="#edit-pane" type="button" role="tab">
                            ✏️ Редактировать
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="config-tab" data-bs-toggle="tab" data-bs-target="#config-pane" type="button" role="tab">
                            ⚙️ Конфиг
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="stats-tab" data-bs-toggle="tab" data-bs-target="#stats-pane" type="button" role="tab">
                            📊 Статистика
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="download-tab" data-bs-toggle="tab" data-bs-target="#download-pane" type="button" role="tab">
                            📥 Файлы
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="backup-tab" data-bs-toggle="tab" data-bs-target="#backup-pane" type="button" role="tab">
                            Backup
                        </button>
                    </li>
                </ul>

                <!-- Контент вкладок -->
                <div class="tab-content flex-grow-1" id="configTabsContent" style="overflow-y: auto; display: flex; flex-direction: column;">
                    <!-- Вкладка 1: Редактирование -->
                    <div class="tab-pane fade show active h-100" id="edit-pane" role="tabpanel">
                        <div class="p-2 d-flex flex-column h-100">
                            <!-- Кнопки перенесены наверх -->
                            <div class="d-flex gap-2 align-items-center mb-2 pb-2 border-bottom border-secondary">
                                <button type="button" class="btn btn-primary btn-sm" onclick="createNewSite()" <?= $can_add ? '' : 'disabled title="Нет прав на добавление"' ?>>
                                    ➕ Создать новый
                                </button>
                                <button type="button" class="btn btn-success btn-sm" onclick="saveEdit()" id="saveEditBtn" disabled <?= $can_edit ? '' : 'title="Нет прав на редактирование"' ?>>
                                    💾 Сохранить
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="deleteSite()" id="deleteSiteBtn" disabled <?= $can_del ? '' : 'title="Нет прав на удаление"' ?>>
                                    🗑 Удалить
                                </button>
                                <span class="align-self-center small text-muted ms-2" id="editStatus"></span>
                            </div>

                            <div class="edit-scroll" style="flex: 1; overflow-y: auto; padding-right: 5px;">
                                <div class="row g-2">
                                    <div class="col-md-12 mb-2">
                                        <label class="form-label fw-bold small">Группа (pool адресов)</label>
                                        <div class="input-group input-group-sm">
                                            <select class="form-select form-select-sm" id="edit_group_id">
                                                <option value="">Выберите...</option>
                                                <?php
                                                $res_groups = $mysqli->query("SELECT id, name FROM mt_pool_groups ORDER BY sort");
                                                while ($g = $res_groups->fetch_assoc()) {
                                                    echo "<option value='{$g['id']}'>{$g['name']}</option>";
                                                }
                                                ?>
                                            </select>
                                            <button class="btn btn-outline-primary" type="button" id="btnGenerateIp" onclick="fetchFreeIps()" title="Сгенерировать свободные IP">
                                                <i class="bi bi-magic"></i> IP
                                            </button>
                                        </div>
                                    </div>



                                    <div class="col-md-12">
                                        <label class="form-label fw-bold small">Локация</label>
                                        <select class="form-select form-select-sm" id="edit_locations_id" class="form-select">
                                            <option value="">Выберите...</option>
                                            <?php
                                            $result_locations_edit = $mysqli->query("SELECT id, name, new_name FROM locations WHERE type_id = 1 ORDER BY new_name");
                                            while ($row = $result_locations_edit->fetch_array(MYSQLI_ASSOC)) {
                                                echo '<option value="' . $row["id"] . '">' . $row["name"] . " --- " . $row["new_name"] . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="row g-2 mt-1">
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small">IP-1 (ether-1)</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_ip1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small">GW-1</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_gw1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small">DNS-1</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_dns1">
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox" id="edit_dhcpclient1" value="1">
                                            <label class="form-check-label fw-bold small" for="edit_dhcpclient1">Dhcp-client-1</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-2 mt-1">
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small">IP-2 (ether-2)</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_ip2">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small">GW-2</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_gw2">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small">DNS-2</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_dns2">
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox" id="edit_dhcpclient2" value="1">
                                            <label class="form-check-label fw-bold small" for="edit_dhcpclient2">Dhcp-client-2</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-2 mt-1">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">LAN (ether-3)</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_lan">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">IP Video (ether-5)</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_ip_video">
                                    </div>
                                </div>

                                <div class="row g-2 mt-1">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">IPIP Salons</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_ip_ipip_salons">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">IPIP Router1</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_ip_ipip_router1">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">LO</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_lo">
                                    </div>
                                </div>

                                <div class="row g-2 mt-1">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">RouterID VPN1</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_routerid_vpn1">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">RouterID VPN2</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_routerid_vpn2">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">IP WG</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_ip_wg">
                                    </div>
                                </div>

                                <div class="row g-2 mt-1">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">WG Private</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_wg_private">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">WG Public</label>
                                        <input type="text" class="form-control form-control-sm" id="edit_wg_public">
                                    </div>
                                </div>

                                <div class="row g-2 mt-1 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Credentials</label>
                                        <select class="form-select form-select-sm" id="edit_cred_id">
                                            <option value="">Выберите...</option>
                                            <?php
                                            $result_credentials_edit = $mysqli->query("SELECT id, name FROM credentials ORDER BY name");
                                            while ($row = $result_credentials_edit->fetch_array(MYSQLI_ASSOC)) {
                                                echo '<option value="' . $row["id"] . '">' . $row["name"] . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">USB Modem</label>
                                        <select class="form-select form-select-sm" id="edit_usb_modem">
                                            <option value="0">Нет</option>
                                            <option value="1">Да</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">LTE Router</label>
                                        <select class="form-select form-select-sm" id="edit_lte_router">
                                            <option value="0">Нет</option>
                                            <option value="1">Да</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row g-2 mt-1">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Время работы</label>
                                        <div class="border rounded p-2 bg-body-secondary" style="font-size:0.9rem;">
                                            <span id="edit_tz_offset" class="badge bg-secondary me-2">—</span>
                                            <span id="edit_work_time">—</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Комментарии</label>
                                        <textarea class="form-control form-control-sm" id="edit_comment" rows="3" placeholder="Введите комментарий..."></textarea>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Вкладка 2: Конфиг -->
                    <div class="tab-pane fade h-100" id="config-pane" role="tabpanel">
                        <div class="d-flex flex-column h-100 p-2">
                            <textarea id="configText" class="log-box" style="flex: 1 1 auto; width: 100%; resize: none; margin-bottom: 10px;" readonly placeholder="Нажмите на строку в таблице, чтобы сгенерировать конфиг..."></textarea>
                        </div>
                    </div>

                    <!-- Вкладка 3: Статистика -->
                    <div class="tab-pane fade h-100" id="stats-pane" role="tabpanel">
                        <div id="stats-content" class="p-2 h-100" style="overflow: auto;">
                            <div class="text-center text-muted" style="padding-top: 20%;">
                                <p>Выберите строку в таблице</p>
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка 4: Скачать -->
                    <div class="tab-pane fade h-100" id="download-pane" role="tabpanel">
                        <div class="d-flex flex-column h-100 p-2">

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold small">
                                    📁 Файлы для скачивания
                                    <span id="dl-count" class="badge bg-secondary ms-1">—</span>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="dl-refresh">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>

                            <div id="dl-list" class="dl-list" style="overflow-y:auto; flex:1 1 auto;">
                                <div class="text-center text-muted p-3">Загрузка...</div>
                            </div>
                        </div>
                    </div>



                    <!-- Вкладка 5: Backup -->
                    <div class="tab-pane fade h-100" id="backup-pane" role="tabpanel">
                        <div class="p-2 h-100 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted" id="bkp-title">Выберите строку в таблице</span>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-warning" id="bkp-now" title="Снять бэкап с этого объекта прямо сейчас">
                                        ⚡ Снять бэкап
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" id="bkp-refresh">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- НОВОЕ: Плашка для предупреждений -->
                            <div id="bkp-alert" class="d-none mb-2"></div>

                            <div id="bkp-list" class="flex-grow-1" style="overflow-y:auto;"></div>
                        </div>
                    </div>




                </div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: ТАБЛИЦА -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">Список конфигураций</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="mt-config-search" class="form-control form-control-sm" style="width: 250px;" placeholder="Поиск по таблице...">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mt-config-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="mt-config-table" class="table table-hover table-bordered w-100">
                <thead>
                    <tr>
                        <th>Статус</th>
                        <th>Код</th>
                        <th>Новое название</th>
                        <th>Адрес</th>
                        <th>IP</th>
                        <th>GW</th>
                        <th>LAN</th>
                    </tr>
                </thead>
            </table>
        </div>

    </div>
</div>

<!-- Модалка diff -->
<div class="modal fade" id="bkpDiffModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header py-2">
                <span class="small" id="bkpDiffTitle"></span>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="bkpDiffBody"
                style="font-family:monospace;font-size:.82rem;white-space:pre-wrap;word-break:break-all;"></div>
        </div>
    </div>
</div>

<script language="JavaScript">
    // Создаём экземпляр Tom Select и сохраняем в переменную,
    // чтобы потом можно было управлять им через API (setValue, clear и т.д.)
    var locationsSelect = new TomSelect('#edit_locations_id', {
        create: false, // не разрешать создание новых опций
        maxItems: 1, // одиночный выбор
        sortField: {
            field: 'text',
            direction: 'asc'
        },
        placeholder: 'Выберите локацию...'
    });

    // Инициализация таблицы DataTables вместо jqGrid
    var mtConfigTable = new DataTable('#mt-config-table', {
        ajax: {
            url: '/mod/mt_config/data.php',
            type: 'POST'
        },
        order: [
            [0, 'asc']
        ],
        columns: [{
                data: 'id',
                title: 'ID',
                visible: false
            },
            {
                data: 'code',
                title: 'Код',
                width: '80px'
            },
            {
                data: 'new_name',
                title: 'Новое название',
                width: '150px'
            },
            {
                data: 'address',
                title: 'Адрес',
                width: '300px'
            },
            {
                data: 'ip1',
                title: 'IP',
                width: '120px'
            },
            {
                data: 'gw1',
                title: 'GW',
                width: '120px'
            },
            {
                data: 'lan',
                title: 'LAN',
                width: '120px'
            }
        ]
    });

    mtConfigTable.on('select', function(e, dt, type, indexes) {
        if (type !== 'row') return;
        var rowData = mtConfigTable.row(indexes[0]).data();

        bkpLocId = rowData.locations_id || null; // ← НОВОЕ
        if (document.getElementById('backup-pane').classList.contains('active')) {
            bkpLoad();
        }

        generateConfig(rowData.id);
        loadEditData(rowData.id);
        loadStats(rowData.id);
    });

    // Функция генерации конфига
    function generateConfig(salonData) {
        document.getElementById('configText').value = '# Генерация конфига...';

        fetch('/mod/mt_config/generate_config.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + encodeURIComponent(salonData)
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Ошибка сервера');
                }
                return response.text();
            })
            .then(function(config) {
                document.getElementById('configText').value = config;
            })
            .catch(function(error) {
                console.error('Ошибка:', error);
                document.getElementById('configText').value = '# Ошибка генерации конфига\n' + error.message;
            });
    }

    function formatSchedule(jsonStr) {
        if (!jsonStr) return '—';
        var data;
        try {
            data = JSON.parse(jsonStr);
        } catch (e) {
            return jsonStr;
        }

        var labels = {
            mon: 'Пн',
            tue: 'Вт',
            wed: 'Ср',
            thu: 'Чт',
            fri: 'Пт',
            sat: 'Сб',
            sun: 'Вс'
        };
        var parts = [];
        for (var key in data) {
            var label = key.indexOf('-') !== -1 ?
                key.split('-').map(function(k) {
                    return labels[k] || k;
                }).join('-') :
                (labels[key] || key);
            var iv = data[key];
            parts.push(label + ': ' + (iv && iv.length ? iv.map(function(x) {
                return x[0] + '-' + x[1];
            }).join(', ') : 'вых'));
        }
        return parts.join('; ');
    }

    // Переменная для хранения ID редактируемой записи
    var currentEditId = null;
    var currentSelectedSalon = null;

    // Загрузка данных из БД по ID
    function loadEditData(rowid) {
        currentEditId = rowid;

        document.getElementById('editStatus').textContent = '⏳ Загрузка данных...';
        document.getElementById('editStatus').className = 'align-self-center small text-warning';
        document.getElementById('saveEditBtn').disabled = true;
        document.getElementById('deleteSiteBtn').disabled = true;

        fetch('/mod/mt_config/get_site_data.php?id=' + encodeURIComponent(rowid))
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Ошибка сервера');
                }
                return response.json();
            })
            .then(function(data) {
                if (data.error) {
                    throw new Error(data.error);
                }

                //document.getElementById('edit_locations_id').value = data.locations_id || '';
                // Устанавливаем значение через API Tom Select,
                // иначе визуально останется «Выберите...» (библиотека не видит прямых изменений <select>)
                locationsSelect.setValue(data.locations_id || '');
                document.getElementById('edit_group_id').value = data.group_id || '';
                document.getElementById('edit_usb_modem').value = data.usb_modem || '0';
                document.getElementById('edit_lte_router').value = data.lte_router || '0';

                document.getElementById('edit_ip1').value = data.ip1 || '';
                document.getElementById('edit_gw1').value = data.gw1 || '';
                document.getElementById('edit_dns1').value = data.dns1 || '';
                document.getElementById('edit_dhcpclient1').checked = (data.dhcpclient1 == 1);

                document.getElementById('edit_ip2').value = data.ip2 || '';
                document.getElementById('edit_gw2').value = data.gw2 || '';
                document.getElementById('edit_dns2').value = data.dns2 || '';
                document.getElementById('edit_dhcpclient2').checked = (data.dhcpclient2 == 1);

                document.getElementById('edit_lan').value = data.lan || '';

                document.getElementById('edit_ip_video').value = data.ip_video || '';
                document.getElementById('edit_ip_ipip_salons').value = data.ip_ipip_salons || '';
                document.getElementById('edit_ip_ipip_router1').value = data.ip_ipip_router1 || '';
                document.getElementById('edit_routerid_vpn1').value = data.routerid_vpn1 || '';
                document.getElementById('edit_routerid_vpn2').value = data.routerid_vpn2 || '';
                document.getElementById('edit_lo').value = data.lo || '';
                document.getElementById('edit_ip_wg').value = data.ip_wg || '';
                document.getElementById('edit_wg_private').value = data.wg_private || '';
                document.getElementById('edit_wg_public').value = data.wg_public || '';
                document.getElementById('edit_cred_id').value = data.cred_id || '';
                document.getElementById('edit_work_time').textContent = formatSchedule(data.work_schedule);
                var tz = parseInt(data.tz_offset_msk || 0, 10);
                document.getElementById('edit_tz_offset').textContent = tz === 0 ? 'МСК (0)' : (tz > 0 ? 'МСК+' + tz : 'МСК' + tz);

                document.getElementById('edit_comment').value = data.comment || '';

                // Кнопка «Сохранить» для существующей записи требует право «редактирование»
                document.getElementById('saveEditBtn').disabled = !CAN_EDIT;
                // Кнопка «Удалить» — только для существующей записи и с правом «удаление»
                document.getElementById('deleteSiteBtn').disabled = !CAN_DEL;
                document.getElementById('editStatus').textContent = '✏️ Редактирование ID: ' + rowid;
                document.getElementById('editStatus').className = 'align-self-center small text-secondary';

                // === БЛОКИРУЕМ КНОПКУ ГЕНЕРАЦИИ IP ===
                var btnGen = document.getElementById('btnGenerateIp');
                if (btnGen) {
                    btnGen.disabled = true;
                    btnGen.title = 'Для существующей записи генерация IP запрещена. Введите вручную.';
                }


            })
            .catch(function(error) {
                console.error('Ошибка:', error);
                document.getElementById('editStatus').textContent = '❌ Ошибка загрузки: ' + error.message;
                document.getElementById('editStatus').className = 'align-self-center small text-danger';
                document.getElementById('saveEditBtn').disabled = true;
            });
    }

    // Сохранение данных
    function saveEdit() {
        // Новая запись (кнопка "Создать") — oper=add без id,
        // существующая — oper=edit с id
        var isNew = !currentEditId;

        var formData = {
            oper: isNew ? 'add' : 'edit',
            locations_id: document.getElementById('edit_locations_id').value,
            group_id: document.getElementById('edit_group_id').value,
            usb_modem: document.getElementById('edit_usb_modem').value,
            lte_router: document.getElementById('edit_lte_router').value,

            ip1: document.getElementById('edit_ip1').value,
            gw1: document.getElementById('edit_gw1').value,
            dns1: document.getElementById('edit_dns1').value,
            dhcpclient1: document.getElementById('edit_dhcpclient1').checked ? 1 : 0,

            ip2: document.getElementById('edit_ip2').value,
            gw2: document.getElementById('edit_gw2').value,
            dns2: document.getElementById('edit_dns2').value,
            dhcpclient2: document.getElementById('edit_dhcpclient2').checked ? 1 : 0,

            lan: document.getElementById('edit_lan').value,

            ip_video: document.getElementById('edit_ip_video').value,
            ip_ipip_salons: document.getElementById('edit_ip_ipip_salons').value,
            ip_ipip_router1: document.getElementById('edit_ip_ipip_router1').value,
            routerid_vpn1: document.getElementById('edit_routerid_vpn1').value,
            routerid_vpn2: document.getElementById('edit_routerid_vpn2').value,
            lo: document.getElementById('edit_lo').value,
            ip_wg: document.getElementById('edit_ip_wg').value,
            wg_private: document.getElementById('edit_wg_private').value,
            wg_public: document.getElementById('edit_wg_public').value,
            cred_id: document.getElementById('edit_cred_id').value,
            comment: document.getElementById('edit_comment').value
        };

        if (!isNew) {
            formData.id = currentEditId;
        }

        fetch('/mod/mt_config/dataAED.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(formData).toString()
            })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function(text) {
                text = (text || '').trim();
                var st = document.getElementById('editStatus');
                if (text !== '') {
                    // Сервер вернул текст — это ошибка (например, дубль по локации).
                    // Показываем красным рядом с кнопкой, форма не сбрасывается.
                    if (st) {
                        st.textContent = '⚠️ ' + text.replace(/^Ошибка:\s*/, '');
                        st.className = 'align-self-center small fw-bold ms-2 text-danger';
                    }
                    return;
                }
                // Успех — зелёное подтверждение
                if (st) {
                    st.textContent = '✅ Сохранено';
                    st.className = 'align-self-center small ms-2 text-success';
                    setTimeout(function() { st.textContent = ''; st.className = 'align-self-center small text-muted ms-2'; }, 3000);
                }
                mtConfigTable.ajax.reload(null, false);
            })
            .catch(function() {
                var st = document.getElementById('editStatus');
                if (st) {
                    st.textContent = '⚠️ Ошибка при сохранении';
                    st.className = 'align-self-center small fw-bold ms-2 text-danger';
                }
            });
    }

    // Удаление объекта (каскад на сервере: пулы, слоты, метки, история,
    // статистика, реестр бэкапов; локация остаётся)
    function deleteSite() {
        if (!CAN_DEL || !currentEditId) return;

        if (!confirm('Удалить объект ID ' + currentEditId + '?\n\n'
                   + 'Будут освобождены IP из пулов, удалены: статистика, слоты mt_run, метки задач, история запусков и записи реестра бэкапов.\n'
                   + 'Файлы бэкапов на диске и локация останутся.\n\n'
                   + 'Действие необратимо.')) {
            return;
        }

        var st = document.getElementById('editStatus');
        if (st) {
            st.textContent = '⏳ Удаление...';
            st.className = 'align-self-center small text-warning ms-2';
        }
        document.getElementById('deleteSiteBtn').disabled = true;

        fetch('/mod/mt_config/dataAED.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({ oper: 'del', id: currentEditId }).toString()
            })
            .then(function(r) {
                return r.text().then(function(t) { return { ok: r.ok, text: (t || '').trim() }; });
            })
            .then(function(res) {
                if (!res.ok || res.text !== '') {
                    // Непустой ответ или HTTP-ошибка = ошибка удаления
                    if (st) {
                        st.textContent = '⚠️ ' + (res.text || ('HTTP-ошибка при удалении')).replace(/^Ошибка:\s*/, '');
                        st.className = 'align-self-center small fw-bold ms-2 text-danger';
                    }
                    document.getElementById('deleteSiteBtn').disabled = !CAN_DEL;
                    return;
                }
                // Успех: сбрасываем форму в «создание» и обновляем таблицу.
                // createNewSite() молча выйдет без права add — поэтому ID и кнопку
                // сбрасываем принудительно, чтобы форма не ссылалась на удалённый объект
                createNewSite();
                currentEditId = null;
                document.getElementById('deleteSiteBtn').disabled = true;
                if (st) {
                    st.textContent = '✅ Объект удалён';
                    st.className = 'align-self-center small ms-2 text-success';
                    setTimeout(function() { st.textContent = ''; st.className = 'align-self-center small text-muted ms-2'; }, 3000);
                }
                mtConfigTable.ajax.reload(null, false);
            })
            .catch(function() {
                if (st) {
                    st.textContent = '⚠️ Ошибка при удалении';
                    st.className = 'align-self-center small fw-bold ms-2 text-danger';
                }
                document.getElementById('deleteSiteBtn').disabled = !CAN_DEL;
            });
    }

    // Создание нового салона (сброс формы)
    function createNewSite() {
        if (!CAN_ADD) return; // кнопка и так неактивна, это страховка
        // Сбрасываем ID текущей записи
        currentEditId = null;

        // Очищаем все поля формы
        document.querySelectorAll('#edit-pane input, #edit-pane textarea, #edit-pane select').forEach(function(el) {
            if (el.tagName === 'SELECT') {
                el.selectedIndex = 0;
            } else if (el.type === 'checkbox') {
                el.checked = false;
            } else {
                el.value = '';
            }
        });

        // Сбрасываем визуальное состояние Tom Select (иначе останется старая локация)
        locationsSelect.clear();

        // Сбрасываем блок «Время работы» и часовой пояс (это <span>, а не input —
        // поэтому общий цикл очистки их не трогает)
        document.getElementById('edit_work_time').textContent = '—';
        document.getElementById('edit_tz_offset').textContent = '—';

        // Активируем кнопку "Сохранить"
        document.getElementById('saveEditBtn').disabled = false;

        // Новая запись — удалять нечего
        document.getElementById('deleteSiteBtn').disabled = true;

        // Меняем статус на "Создание"
        document.getElementById('editStatus').textContent = '✨ Создание новой записи';
        document.getElementById('editStatus').className = 'align-self-center small text-primary';

        // Фокус на первое поле
        document.getElementById('edit_group_id').focus();

        // === РАЗБЛОКИРОВЫВАЕМ КНОПКУ ГЕНЕРАЦИИ IP ===
        var btnGen = document.getElementById('btnGenerateIp');
        if (btnGen) {
            btnGen.disabled = false;
            btnGen.title = 'Сгенерировать свободные IP';
        }
    }




    function loadStats(parentRowKey) {
        var statsContent = document.getElementById('stats-content');
        statsContent.innerHTML = '<div class="text-center p-3">Загрузка...</div>';

        fetch('/mod/mt_config/child_data.php?parent_id=' + encodeURIComponent(parentRowKey))
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function(html) {
                document.getElementById('stats-content').innerHTML = html;
            })
            .catch(function() {
                document.getElementById('stats-content').innerHTML =
                    '<div class="alert alert-danger m-3">Ошибка загрузки</div>';
            });
    }


    document.getElementById('configText').value = '';

    window.downloadConfig = function() {
        const config = document.getElementById('configText').value;
        let filename = 'config.rsc';

        if (currentSelectedSalon && currentSelectedSalon.new_name) {
            filename = currentSelectedSalon.new_name + '.rsc';
        } else if (currentSelectedSalon && currentSelectedSalon.salon_code) {
            filename = currentSelectedSalon.salon_code + '.rsc';
        }
        const blob = new Blob([config], {
            type: 'text/plain'
        });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
    };

    // ============================================================
    // MIKROTIK: действия над устройствами
    // ============================================================

    if (!document.getElementById('mt-toast-container')) {
        var tc = document.createElement('div');
        tc.id = 'mt-toast-container';
        tc.className = 'toast-container position-fixed top-0 end-0 p-3';
        // Фиксируем размеры и добавляем скролл, чтобы тосты не уходили за экран
        tc.style.zIndex = '99999';
        tc.style.maxHeight = '100vh';
        tc.style.overflowY = 'auto';
        tc.style.pointerEvents = 'none'; // клики проходят сквозь пустое место
        document.body.appendChild(tc);
    }

    if (!document.getElementById('mtConfirmModal')) {
        var modalHtml = '' +
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content bg-dark text-light border-secondary">' +
            '<div class="modal-header border-secondary">' +
            '<h5 class="modal-title">Подтверждение</h5>' +
            '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>' +
            '</div>' +
            '<div class="modal-body" id="mtConfirmText">Вы уверены?</div>' +
            '<div class="modal-footer border-secondary">' +
            '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>' +
            '<button type="button" class="btn btn-warning btn-sm" id="mtConfirmBtn">Подтвердить</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        var m = document.createElement('div');
        m.id = 'mtConfirmModal';
        m.className = 'modal fade';
        m.tabIndex = -1;
        m.innerHTML = modalHtml;
        document.body.appendChild(m);
    }

    var mtConfirmModal = new bootstrap.Modal(document.getElementById('mtConfirmModal'));
    var mtConfirmResolve = null;

    document.getElementById('mtConfirmBtn').onclick = function() {
        mtConfirmModal.hide();
        if (mtConfirmResolve) {
            mtConfirmResolve(true);
            mtConfirmResolve = null;
        }
    };

    document.getElementById('mtConfirmModal').addEventListener('hidden.bs.modal', function() {
        if (mtConfirmResolve) {
            mtConfirmResolve(false);
            mtConfirmResolve = null;
        }
    });

    function mtAskConfirm(html) {
        return new Promise(function(resolve) {
            document.getElementById('mtConfirmText').innerHTML = html;
            mtConfirmResolve = resolve;
            mtConfirmModal.show();
        });
    }

    function mtToast(type, title, msg, delay, allowHide) {
        if (delay === undefined) {
            delay = 8000;
        }

        var icons = {
            success: '✅',
            error: '❌',
            info: '⏳'
        };
        var icon = icons[type] || '';

        var hideBtn = '';
        if (allowHide) {
            hideBtn = '<button type="button" class="btn btn-sm btn-outline-light me-2 mt-hide-toast">Скрыть</button>';
        }

        var el = document.createElement('div');
        el.className = 'toast align-items-center border-0 text-bg-' + type;
        el.innerHTML =
            '<div class="d-flex">' +
            '<div class="toast-body">' +
            '<div class="fw-bold mb-1">' + icon + ' ' + title + '</div>' +
            '<div class="small">' + msg + '</div>' +
            '</div>' +
            hideBtn +
            '<button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" title="Скрыть (процесс продолжится в фоне)"></button>' +
            '</div>';
        el.style.pointerEvents = 'auto'; // сам тост кликабелен
        el.style.maxWidth = '400px';
        el.style.marginBottom = '8px';

        document.getElementById('mt-toast-container').appendChild(el);

        var t = new bootstrap.Toast(el, {
            delay: delay,
            autohide: delay > 0
        });
        t.show();
        el.addEventListener('hidden.bs.toast', function() {
            el.remove();
        });

        var hb = el.querySelector('.mt-hide-toast');
        if (hb) {
            hb.addEventListener('click', function() {
                t.hide();
            });
        }
        return t;
    }

    var MT_ACTIONS = {
        'collect-info': {
            url: '/api/mikrotik/collect-info.php',
            title: 'Сбор информации'
        },
        'reboot': {
            url: '/api/mikrotik/reboot.php',
            title: 'Перезагрузка'
        },
        'update-ros': {
            url: '/api/mikrotik/update-ros.php',
            checkUrl: '/api/mikrotik/update-ros-check.php',
            title: 'Обновление ROS'
        },
        'update-firmware': {
            url: '/api/mikrotik/update-firmware.php',
            title: 'Обновление Firmware'
        }
    };

    function mtConfirmText(action, hostname) {
        hostname = escapeHtml(hostname); // имя с устройства/из БД — только текстом (аудит 2026-11-23, Н2)
        if (action === 'collect-info') {
            return 'Собрать информацию с роутера <b>' + hostname + '</b>?<br><br>' +
                '<span class="text-muted">Обновятся версия, uptime, модель, статус RADIUS/LTE.</span>';
        }
        if (action === 'reboot') {
            return 'Перезагрузить роутер <b>' + hostname + '</b>?<br><br>' +
                '<span class="text-warning">⚠️ Будет недоступен несколько минут.</span>';
        }
        if (action === 'update-ros') {
            return 'Обновить RouterOS на роутере <b>' + hostname + '</b>?<br><br>' +
                '<span class="text-warning">⚠️ Установится последняя стабильная версия.<br>Роутер перезагрузится.</span>';
        }
        if (action === 'update-firmware') {
            return 'Обновить прошивку роутера <b>' + hostname + '</b> из хранилища?<br><br>' +
                '<span class="text-warning">⚠️ Версия подберётся автоматически.<br>Роутер перезагрузится.</span>';
        }
        return 'Вы уверены?';
    }

    function runAction(btn, action, id, hostname, cfg) {
        var card = btn.closest('.mt-device-card') || document;
        var allBtns = card.querySelectorAll('.mt-action-btn');
        var i;

        for (i = 0; i < allBtns.length; i++) {
            allBtns[i].disabled = true;
        }

        var originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Выполняется...';

        var infoToast = mtToast(
            'info',
            cfg.title + ' — ' + hostname,
            'ID ' + id + '. Отправка команды...',
            0,
            true
        );

        if (action === 'update-ros') {
            postAction(cfg.url, id).then(function(data) {
                if (!data.success) {
                    infoToast.hide();
                    handleResult(data, cfg, hostname);
                    finish();
                    return;
                }

                if (data.done) {
                    infoToast.hide();
                    handleResult(data, cfg, hostname);
                    finish();
                    return;
                }

                infoToast._element.querySelector('.toast-body .small').innerHTML = 'ID ' + id + '. Загрузка прошивки...';
                startPolling(id, cfg, hostname, infoToast, finish);
            }).catch(function(err) {
                infoToast.hide();
                mtToast('error', cfg.title, 'Ошибка: ' + escapeHtml(err.message), 12000);
                finish();
            });
        } else {
            postAction(cfg.url, id)
                .then(function(data) {
                    infoToast.hide();
                    handleResult(data, cfg, hostname);
                })
                .catch(function(err) {
                    infoToast.hide();
                    mtToast('error', cfg.title, 'Ошибка: ' + escapeHtml(err.message), 12000);
                })
                .then(finish);
        }

        function finish() {
            for (var j = 0; j < allBtns.length; j++) {
                allBtns[j].disabled = false;
            }
            btn.innerHTML = originalHtml;
        }
    }

    function startPolling(id, cfg, hostname, infoToast, onFinish) {
        var attempts = 0;

        var timer = setInterval(function() {
            attempts++;

            if (attempts % 5 === 0) {
                var body = infoToast._element.querySelector('.toast-body .small');
                if (body) {
                    body.innerHTML = 'ID ' + id + '. Загрузка прошивки... (' + (attempts * 2) + ' сек)';
                }
            }

            postAction(cfg.checkUrl, id).then(function(data) {
                if (!data.success) {
                    clearInterval(timer);
                    infoToast.hide();
                    mtToast('error', cfg.title + ' — ' + hostname, escapeHtml(data.error || 'Ошибка'), 12000);
                    onFinish();
                    return;
                }

                if (!data.done) {
                    return;
                }

                clearInterval(timer);
                infoToast.hide();
                handleResult(data, cfg, hostname);
                onFinish();
            }).catch(function(err) {
                clearInterval(timer);
                infoToast.hide();
                mtToast('error', cfg.title, 'Ошибка опроса: ' + escapeHtml(err.message), 12000);
                onFinish();
            });
        }, 2000);
    }

    function postAction(url, id) {
        var params = new URLSearchParams();
        params.append('id', id);

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: params.toString()
        }).then(function(resp) {
            if (!resp.ok) {
                throw new Error('HTTP ' + resp.status);
            }
            return resp.json();
        });
    }

    function handleResult(data, cfg, hostname) {
        if (!data.success) {
            mtToast('error', cfg.title + ' — ' + hostname, escapeHtml(data.error || 'Неизвестная ошибка'), 12000);
            return;
        }

        var msg = escapeHtml(data.message || 'Готово');
        if (data.from && data.to) {
            msg += '<br><span class="opacity-75">' + escapeHtml(data.from) + ' → <b>' + escapeHtml(data.to) + '</b></span>';
        }

        if (data.dry_run) {
            mtToast('info', cfg.title + ' — режим теста — ' + hostname, msg, 6000);
        } else {
            mtToast('success', cfg.title + ' — ' + hostname, msg, 4000);
        }

        setTimeout(function() {
            if (currentEditId) {
                loadStats(currentEditId);
            }
        }, 800);
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.mt-action-btn');
        if (!btn || btn.disabled) {
            return;
        }
        e.preventDefault();

        var action = btn.dataset.action;
        var id = btn.dataset.id;
        // имя уходит в innerHTML тостов/подтверждений — экранируем один раз здесь (аудит 2026-11-23, Н2)
        var hostname = escapeHtml(btn.dataset.hostname || 'роутер');
        var cfg = MT_ACTIONS[action];
        if (!cfg) {
            return;
        }

        mtAskConfirm(mtConfirmText(action, hostname)).then(function(ok) {
            if (!ok) {
                return;
            }
            runAction(btn, action, id, hostname, cfg);
        });
    });


    // ============================================================
    // ОБРАБОТЧИКИ КНОПОК (выполняются после загрузки DOM)
    // ============================================================

    var btnRefresh = document.getElementById('mt-config-refresh');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function() {
            mtConfigTable.ajax.reload(null, false); // без window.
        });
    }

    var searchInput = document.getElementById('mt-config-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            mtConfigTable.search(this.value).draw(); // без window.
        });
    }

    var dlLoaded = false;

    function dlIcon(ext) {
        return ext === 'backup' ? '<i class="bi bi-hdd"></i>' :
            ext === 'rsc' ? '<i class="bi bi-file-code"></i>' :
            ext === 'json' ? '<i class="bi bi-filetype-json"></i>' :
            ext === 'npk' ? '<i class="bi bi-box"></i>' :
            ext === 'txt' ? '<i class="bi bi-file-text"></i>' :
            '<i class="bi bi-file-earmark"></i>';
    }

    function loadDownloads(force) {
        if (dlLoaded && !force) return;
        dlLoaded = true;

        var list = document.getElementById('dl-list');
        var cnt = document.getElementById('dl-count');
        if (!list) return;

        list.innerHTML = '<div class="text-center text-muted p-3">Загрузка...</div>';

        fetch('/mod/mt_config/list_downloads.php')
            .then(r => r.json())
            .then(data => {
                var items = Array.isArray(data) ? data : (data.items || []);
                cnt.textContent = items.length;

                if (!items.length) {
                    list.innerHTML = '<div class="text-center text-muted p-3">Файлов нет</div>';
                    return;
                }

                // Группировка по папке
                var groups = {};
                items.forEach(f => {
                    var name = f.rel || f.name || '';
                    var slash = name.lastIndexOf('/');
                    var dir = slash !== -1 ? name.slice(0, slash) : '';
                    var file = slash !== -1 ? name.slice(slash + 1) : name;
                    var ext = file.split('.').pop().toLowerCase();

                    if (!groups[dir]) groups[dir] = [];
                    groups[dir].push({
                        url: f.url,
                        file: file,
                        ext: ext,
                        time: f.time || f.mtime_fmt || ''
                    });
                });

                // Сортировка групп: корень первым, затем по алфавиту
                var dirs = Object.keys(groups).sort((a, b) => {
                    if (a === '' && b !== '') return -1;
                    if (b === '' && a !== '') return 1;
                    return a.localeCompare(b);
                });

                list.innerHTML = dirs.map(dir => {
                    var files = groups[dir];
                    var title = dir === '' ?
                        '<i class="bi bi-folder2-open"></i> Корень' :
                        '<i class="bi bi-folder2"></i> ' + escapeHtml(dir);

                    var rows = files.map(f => `
                    <div class="dl-item">
                        <div class="dl-icon">${dlIcon(f.ext)}</div>
                        <div class="dl-name" title="${escapeHtml(f.file)}">${escapeHtml(f.file)}</div>
                            <a href="${f.url}" download class="dl-download" title="Скачать">
                                <i class="bi bi-download"></i>
                            </a>
                        <div class="dl-meta">${escapeHtml(f.time)}</div>
                    </div>
                `).join('');

                    return `
                    <div class="dl-group">
                        <div class="dl-group-title">
                            ${title}
                            <span class="badge bg-secondary">${files.length}</span>
                        </div>
                        ${rows}
                    </div>
                `;
                }).join('');
            })
            .catch(err => {
                cnt.textContent = '—';
                list.innerHTML = '<div class="alert alert-danger m-0">Ошибка: ' +
                    escapeHtml(err.message) + '</div>';
            });
    }

// escapeHtml — общая реализация в /plugins/crud.js (аудит 2026-11-23, Н1)

    function fetchFreeIps() {
        if (currentEditId !== null) {
            alert('Генерация IP недоступна для существующих записей.\nЕсли нужно изменить IP, введите его вручную или удалите запись и создайте заново.');
            return;
        }
        var gid = document.getElementById('edit_group_id').value;
        if (!gid) return;

        // Очищаем ТОЛЬКО внутренние поля. WAN (ip1, gw1) не трогаем!
        var poolFields = [
            'edit_lan', 'edit_ip_video', 'edit_ip_ipip_salons',
            'edit_ip_ipip_router1', 'edit_lo', 'edit_ip_wg',
            'edit_routerid_vpn1', 'edit_routerid_vpn2',
            'edit_wg_private', 'edit_wg_public' // <-- Добавили ключи
        ];

        poolFields.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });

        fetch('/mod/mt_config/get_free_ips.php?group_id=' + gid)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                if (document.getElementById('edit_lan')) document.getElementById('edit_lan').value = data.lan || '';
                if (document.getElementById('edit_ip_video')) document.getElementById('edit_ip_video').value = data.ip_video || '';
                if (document.getElementById('edit_ip_ipip_salons')) document.getElementById('edit_ip_ipip_salons').value = data.ip_ipip_salons || '';
                if (document.getElementById('edit_ip_ipip_router1')) document.getElementById('edit_ip_ipip_router1').value = data.ip_ipip_router1 || '';
                if (document.getElementById('edit_lo')) document.getElementById('edit_lo').value = data.lo || '';
                if (document.getElementById('edit_ip_wg')) document.getElementById('edit_ip_wg').value = data.ip_wg || '';
                if (document.getElementById('edit_routerid_vpn1')) document.getElementById('edit_routerid_vpn1').value = data.routerid_vpn1 || '';
                if (document.getElementById('edit_routerid_vpn2')) document.getElementById('edit_routerid_vpn2').value = data.routerid_vpn2 || '';

                // Заполняем ключи WG
                if (document.getElementById('edit_wg_private')) document.getElementById('edit_wg_private').value = data.wg_private || '';
                if (document.getElementById('edit_wg_public')) document.getElementById('edit_wg_public').value = data.wg_public || '';
            })
            .catch(err => console.error('Ошибка получения IP:', err));
    }


    var tabBtn = document.getElementById('download-tab');
    if (tabBtn) {
        tabBtn.addEventListener('shown.bs.tab', () => loadDownloads(false));
    }

    var btn = document.getElementById('dl-refresh');
    if (btn) {
        btn.addEventListener('click', () => loadDownloads(true));
    }

    // ============================================================
    // BACKUP
    // ============================================================
    var bkpLocId = null;

       function bkpLoad() {
        var box = document.getElementById('bkp-list');
        var title = document.getElementById('bkp-title');
        var alertBox = document.getElementById('bkp-alert');

        if (!bkpLocId) {
            box.innerHTML = '';
            title.textContent = 'Выберите строку в таблице';
            alertBox.className = 'd-none';
            return;
        }

        box.innerHTML = '<div class="text-muted p-3">Загрузка...</div>';
        alertBox.className = 'd-none';

        fetch('/mod/mt_config/backup_list.php?locations_id=' + bkpLocId)
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    box.innerHTML = '<div class="text-danger p-2">' + d.error + '</div>';
                    return;
                }

                title.textContent = d.name || '';

                // --- Вывод алерта о свежести ---
                if (d.health && d.health.status !== 'ok') {
                    var alertClass = d.health.status === 'error' ? 'alert-danger' : 'alert-warning';
                    var icon = d.health.status === 'error' ? '🚨' : '⚠️';

                    var extraInfo = '';
                    if (d.health.rsc_age_hours !== null) {
                        extraInfo += `RSC: ${d.health.rsc_age_hours} ч. назад. `;
                    }
                    if (d.health.backup_age_hours !== null) {
                        extraInfo += `Backup: ${d.health.backup_age_hours} ч. назад.`;
                    }

                    alertBox.className = 'alert ' + alertClass + ' py-2 px-3 mb-2 small';
                    alertBox.innerHTML = `<strong>${icon} ${d.health.message}</strong><br><span class="opacity-75">${extraInfo}</span>`;
                    alertBox.classList.remove('d-none');
                }

                var all = (d.rsc || []).concat(d.backup || []);
                if (!all.length) {
                    box.innerHTML = '<div class="text-muted p-3">Файлов нет</div>';
                    return;
                }

                var rows = all.map(f => {
                    var typeBadge = f.name.endsWith('.rsc') ?
                        '<span class="badge bg-primary">RSC</span>' :
                        '<span class="badge bg-secondary">BACKUP</span>';

                    // --- Индикатор изменений ---
                    var statusBadge = '';
                    if (f.changed_vs_prev === true) {
                        statusBadge = '<span class="badge bg-warning text-dark" title="Конфиг изменился по сравнению с предыдущим">⚠️ изменён</span>';
                    } else if (f.changed_vs_prev === false) {
                        statusBadge = '<span class="badge bg-success" title="Файл идентичен предыдущему">✅ без изменений</span>';
                    } else {
                        statusBadge = '<span class="badge bg-secondary" title="Первый бэкап, сравнивать не с чем">🆕 первый</span>';
                    }

                    var diffBtn = f.prev_id ?
                        `<button class="btn btn-sm btn-outline-info bkp-diff"
                                data-to="${f.id}" data-from="${f.prev_id}">
                                diff</button>` :
                        '';

                    return `<tr>
                        <td>${typeBadge}</td>
                        <td class="text-nowrap">${f.mtime_fmt}</td>
                        <td><span class="text-truncate d-inline-block" style="max-width:180px;" title="${f.name}">${f.name}</span></td>
                        <td class="text-end">${(f.size/1024).toFixed(1)} KB</td>
                        <td class="text-center">${statusBadge}</td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-success"
                               href="/mod/mt_config/backup_download.php?locations_id=${bkpLocId}&file=${encodeURIComponent(f.name)}"
                               download>⬇</a>
                            ${diffBtn}
                        </td>
                    </tr>`;
                }).join('');

                box.innerHTML = `<table>
                    <thead><tr>
                        <th>Тип</th><th>Дата</th><th>Файл</th>
                        <th class="text-end">Размер</th><th class="text-center">Статус</th><th></th>
                    </tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;
            })
            .catch(e => box.innerHTML = '<div class="text-danger p-2">' + e.message + '</div>');
    }

    function bkpShowDiff(toId, fromId) {
        var body = document.getElementById('bkpDiffBody');
        document.getElementById('bkpDiffTitle').textContent = 'Сравнение';
        body.innerHTML = 'Загрузка...';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('bkpDiffModal')).show();

        fetch(`/mod/mt_config/backup_diff.php?to_id=${toId}&from_id=${fromId}`)
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    body.textContent = d.error;
                    return;
                }

                document.getElementById('bkpDiffTitle').textContent = d.file + ' vs ' + d.prev;

                if (d.type === 'binary') {
                    body.textContent = d.note + '\n\n' +
                        'Новый: ' + (d.size / 1024).toFixed(1) + ' KB\n' +
                        'Старый: ' + (d.prev_size / 1024).toFixed(1) + ' KB\n' +
                        'Изменился: ' + (d.changed ? 'ДА' : 'НЕТ');
                    return;
                }

                if (!d.changed) {
                    body.textContent = 'Файлы идентичны';
                    return;
                }

                body.innerHTML = d.lines.map(l =>
                    '<div class="' + (l[0] === '+' ? 'diff-add' : l[0] === '-' ? 'diff-del' : '') + '">' +
                    (l[0] === ' ' ? '  ' : l[0] + ' ') + (l[1] || '') + '</div>'
                ).join('');
            })
            .catch(e => body.textContent = e.message);
    }

    document.getElementById('bkp-refresh').addEventListener('click', bkpLoad);
    document.getElementById('backup-tab').addEventListener('shown.bs.tab', bkpLoad);

    // Разовый бэкап выбранного объекта (через очередь job_run_requests)
    document.getElementById('bkp-now').addEventListener('click', function () {
        var alertBox = document.getElementById('bkp-alert');
        if (!currentEditId) {
            alertBox.className = 'alert alert-warning py-2 px-3 mb-2 small';
            alertBox.textContent = 'Сначала выберите объект в таблице';
            return;
        }
        var btn = this;
        btn.disabled = true;
        fetch('/mod/mt_config/backup_now.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ site_id: currentEditId }).toString()
            })
            .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, text: t }; }); })
            .then(function (res) {
                alertBox.className = 'alert py-2 px-3 mb-2 small ' + (res.ok ? 'alert-success' : 'alert-danger');
                alertBox.textContent = res.text;
                if (res.ok) setTimeout(function () { alertBox.className = 'd-none'; }, 8000);
            })
            .catch(function () {
                alertBox.className = 'alert alert-danger py-2 px-3 mb-2 small';
                alertBox.textContent = 'Ошибка сети при постановке бэкапа в очередь';
            })
            .finally(function () { btn.disabled = false; });
    });
    document.addEventListener('click', function(e) {
        var b = e.target.closest('.bkp-diff');
        if (b) {
            e.preventDefault();
            bkpShowDiff(b.dataset.to, b.dataset.from);
        }
    });



    // При загрузке страницы сразу переводим форму в режим создания новой записи
    createNewSite();
</script>
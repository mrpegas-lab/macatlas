<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

 <div class="cmd-layout">
        
        <!-- ЛЕВАЯ КОЛОНКА: MAC-АДРЕСА -->
        <div class="cmd-form" style="width: 600px; flex: 0 0 600px;">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold"><i class="bi bi-ethernet me-1"></i> MAC-адреса</div>
                    <span id="sw_discovery-mac-title" class="badge bg-secondary text-truncate" style="max-width: 250px;"></span>
                </div>

                <table id="sw_discovery-mac-table" class="table table-hover table-bordered w-100 mb-0">
                    <thead>
                        <tr>
                            <th>MAC-адрес</th>
                            <th>Вендор</th>
                            <th>IP</th>
                            <th>Host</th>
                            <th>Телефон</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: ДВЕ ТАБЛИЦЫ -->
        <div class="cmd-table" style="display: flex; flex-direction: column; gap: 14px;">
            
            <!-- Таблица 1: Коммутаторы -->
            <div>
                <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                    <div class="fw-semibold"><i class="bi bi-hdd-network me-1"></i> Коммутаторы</div>
                </div>
                <table id="sw_discovery-switches-table" class="table table-hover w-100 mb-0">
                    <thead>
                        <tr>
                            <th>Имя</th>
                            <th>Локация</th>
                            <th>Помещение</th>
                            <th>IP</th>
                            <th>Модель</th>
                            <th>PoE</th>
                            <th>UP</th>
                            <th>DOWN</th>
                            <th>PoE ON</th>
                            <th>PoE OFF</th>
                        </tr>
                    </thead>
                </table>
            </div>

            <!-- Таблица 2: Порты -->
            <div>
                <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                    <div class="fw-semibold"><i class="bi bi-diagram-3 me-1"></i> Порты коммутатора</div>
                    <span id="sw_discovery-ports-title" class="text-muted small"></span>
                </div>
                <table id="sw_discovery-ports-table" class="table table-hover w-100 mb-0">
                    <thead>
                        <tr>
                            <th>Порт</th>
                            <th>MAC-адресов</th>
                            <th>Статус</th>
                            <th>PoE</th>
                            <th>Скорость</th>
                            <th>Активность</th>
                            <th>Отключение</th>
                            <th>Сканировано</th>
                        </tr>
                    </thead>
                </table>
            </div>

        </div>

    </div>

<script>
// ============================================================
// 1. ТАБЛИЦА КОММУТАТОРОВ
// ============================================================
var switchesTable = new DataTable('#sw_discovery-switches-table', {
    ajax: {
        url: '/mod/sw_discovery/data.php',
        type: 'POST'
    },
    serverSide: true,
    info: false,
    autoWidth: false,
    scrollX: false,
    scrollY: '350px',        // ← внутренняя прокрутка
    scrollCollapse: true,    // ← не растягивать при малом числе строк
    paging: true,            // ← пагинация остаётся
    lengthChange: false,     // ← убираем выбор «10/25/50»
    select: {
        style: 'single',
        selector: 'td'
    },
    order: [[3, 'asc']],
     layout: {
        topStart: null,       // заголовок теперь в HTML-панели над таблицей
        topEnd: 'paging',     // размещаем пагинацию в правом верхнем углу
        bottomStart: 'info',  // информация (если нужна) остаётся внизу слева
        bottomEnd: null       // убираем пагинацию снизу
    },
    columns: [
        { data: 'switch_name',   title: 'Имя' },
        { data: 'location_name', title: 'Локация' },
        { data: 'room_name',     title: 'Помещение' },
        { data: 'hardware_ip',   title: 'IP' },
        { data: 'model',         title: 'Модель' },
        { data: 'poe',           title: 'PoE', className: 'text-center',
          render: function (v) { return v == 1 ? '<i class="bi bi-check-lg text-success"></i>' : ''; } },
        { data: 'ports_up',      title: 'UP',   className: 'text-center' },
        { data: 'ports_down',    title: 'DOWN', className: 'text-center' },
        { data: 'poe_on',        title: 'PoE ON',  className: 'text-center' },
        { data: 'poe_off',       title: 'PoE OFF', className: 'text-center' }
    ]
});

// ============================================================
// 2. ТАБЛИЦА ПОРТОВ (пустая, грузится по клику)
// ============================================================
var portsTable = new DataTable('#sw_discovery-ports-table', {
    data: [],
    serverSide: false,
    info: false,
    autoWidth: false,
    scrollX: false,
    scrollY: '260px',        // ← внутренняя прокрутка
    scrollCollapse: true,    // ← не растягивать таблицу, если строк мало
    paging: false,           // ← без постраничного вывода
    order: [[0, 'asc']], // <--- СОРТИРОВКА ПО ПЕРВОЙ КОЛОНКЕ (Порт) ПРИ ЗАГРУЗКЕ
    select: {
        style: 'single',
        selector: 'td'
    },
    columns: [
        { 
            data: 'port_id', 
            title: 'Порт', 
            className: 'text-center',
            // Говорим DataTables: "когда будешь сортировать, бери скрытое число raw_port"
            render: function(data, type, row) {
                if (type === 'sort' || type === 'type') {
                    return row.raw_port;
                }
                return data; // а для отображения оставляем строку 'sfp28-3'
            }
        },
        { data: 'mac_count', title: 'MAC', className: 'text-center' },
        { data: 'status', title: 'Статус', className: 'text-center', render: function(v) {
            return v === 'up' ? '<span class="text-success">up</span>' : '<span class="text-danger">down</span>';
        }},
        { data: 'poe', title: 'PoE', className: 'text-center', render: function(v) {
            return v == 1 ? '<span class="text-success">on</span>' : '<span class="text-danger">off</span>';
        }},
        { data: 'speed', title: 'Скорость', className: 'text-center' },
        { data: 'last_active', title: 'Активность' },
        { data: 'last_down', title: 'Отключение' },
        { data: 'scanned_at', title: 'Сканировано' }
    ],
});

// ============================================================
// 3. ТАБЛИЦА MAC-АДРЕСОВ (пустая)
// ============================================================
var macTable = new DataTable('#sw_discovery-mac-table', {
    data: [],
    serverSide: false,
    paging: false,
    info: false,
    searching: false,
    columns: [
        { data: 'port_mac', title: 'MAC-адрес' },
        { data: 'vendor_name', title: 'Вендор' },
        { data: 'arp_ip', title: 'IP' },
        { data: 'arp_host', title: 'Host' },
        { data: 'phone_number', title: 'Телефон', className: 'text-center' }
    ]
});

// ============================================================
// 4. ОБРАБОТЧИКИ
// ============================================================

// Клик по коммутатору → грузим порты
switchesTable.on('select', function (e, dt, type, indexes) {
    if (type === 'row') {
        var data = dt.row(indexes[0]).data();
        if (!data) return;

        document.getElementById('sw_discovery-ports-title').textContent = '— ' + (data.switch_name || '');
        loadPorts(data.id);
    }
});

switchesTable.on('deselect', function (e, dt, type, indexes) {
    if (type === 'row' && dt.rows({ selected: true }).count() === 0) {
        document.getElementById('sw_discovery-ports-title').textContent = '';
        portsTable.clear().draw();
        macTable.clear().draw();
        document.getElementById('sw_discovery-mac-title').textContent = '';
    }
});

// Клик по порту → грузим MAC'и
portsTable.on('select', function (e, dt, type, indexes) {
    if (type === 'row') {
        var data = dt.row(indexes[0]).data();
        if (!data) return;

        var selectedSwitch = switchesTable.row({ selected: true }).data();
        if (!selectedSwitch) return;

        // В заголовке показываем красивое имя
        document.getElementById('sw_discovery-mac-title').textContent = '— порт ' + data.port_id;
        
        // А в функцию загрузки передаем ЧИСЛОВОЙ порт (raw_port)
        loadMacList(selectedSwitch.id, data.raw_port);
    }
});

// ============================================================
// 5. ФУНКЦИИ ЗАГРУЗКИ
// ============================================================
function loadPorts(switchId) {
    portsTable.clear().draw();
    macTable.clear().draw();
    document.getElementById('sw_discovery-mac-title').textContent = '';

    fetch('/mod/sw_discovery/subdata.php?id_switch=' + encodeURIComponent(switchId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            portsTable.rows.add(data).draw();
        })
        .catch(function (err) {
            console.error('Ошибка загрузки портов:', err);
        });
}

function loadMacList(switchId, portId) {
    macTable.clear().draw();

    fetch('/mod/sw_discovery/maclist.php?id_switch=' + encodeURIComponent(switchId) + '&port_id=' + encodeURIComponent(portId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            macTable.rows.add(data).draw();
        })
        .catch(function (err) {
            console.error('Ошибка загрузки MAC:', err);
        });
}
</script>
<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">

    <!-- ВЕРХНЯЯ ПАНЕЛЬ: Заголовок + Поиск -->
    <div class="panel mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding: 8px 12px;">
        <div class="d-flex align-items-center gap-3">
            <h5 class="mb-0 fw-bold">🔍 Поиск по оборудованию</h5>
        </div>
        <div class="d-flex align-items-center gap-2">
            <input type="text" id="swsearch-input" class="form-control form-control-sm" style="width: 300px;" placeholder="MAC, IP, Hostname, ФИО...">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="swsearch-reset" title="Сброс">
                <i class="bi bi-x-lg"></i> Сброс
            </button>
        </div>
    </div>

    <!-- ТАБЛИЦА РЕЗУЛЬТАТОВ -->
    <div class="cmd-table">
        <table id="swsearch-table" class="table table-hover w-100"></table>
    </div>

</div>

<script>
var swSearchTable = null;

// ============================================================
// Инициализация таблицы с группировкой
// ============================================================
swSearchTable = new DataTable('#swsearch-table', {
    ajax: {
        url:  '/mod/sw_search/data.php',
        type: 'POST'
    },
    serverSide: false,
    processing: true,
    searching: true,
    pageLength: 250,
    lengthMenu: [100, 250, 500, 1000, 2000],
    
    // Отключаем автоматическую сортировку при инициализации
    order: [], 
    
    autoWidth:  false,
    columns: [
        { data: 'model_full',    title: 'Модель [Локация, Коммутатор]', visible: false },
        { data: 'port',          title: 'Порт',      className: 'text-center' },
        { data: 'mac_count',     title: 'MAC',       className: 'text-center' },
        { data: 'port_mac',      title: 'MAC-адрес' },
        { data: 'vendor_name',   title: 'Вендор' },
        { data: 'arp_ip',        title: 'IP' },
        { data: 'arp_host',      title: 'Host' },
        { data: 'phone_number',  title: 'Телефон',   className: 'text-center' },
        { data: 'fio',           title: 'Ф.И.О' },
        { data: 'poe',           title: 'PoE',       className: 'text-center',
          render: function (v) {
              if (v === 'on' || v === '1') return '<span class="text-success">on</span>';
              if (v === 'off' || v === '0') return '<span class="text-danger">off</span>';
              return v || '';
          }
        },
        { data: 'speed',         title: 'Скорость',  className: 'text-center' },
        { data: 'port_status',   title: 'Статус',    className: 'text-center',
          render: function (v) {
              if (v === 'up' || v === '1') return '<span class="text-success">up</span>';
              if (v === 'down' || v === '0') return '<span class="text-danger">down</span>';
              return v || '';
          }
        },
        { data: 'last_active',   title: 'Активность' },
        { data: 'last_down',     title: 'Отключение' },
        
        // Скрытые колонки (чтобы DataTables не выдавал ошибку)
        { data: 'port_name',     title: 'Port Name',  visible: false },
        { data: 'location_name', title: 'Location',   visible: false }
    ],
    rowGroup: {
        dataSrc: 'model_full'
    }
});
// ============================================================
// Поиск и сброс
// ============================================================
document.getElementById('swsearch-input').addEventListener('input', function () {
    swSearchTable.search(this.value).draw();
});

document.getElementById('swsearch-reset').onclick = function () {
    document.getElementById('swsearch-input').value = '';
    swSearchTable.search('').draw();
};
</script>
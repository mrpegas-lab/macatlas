<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">

    <!-- ВЕРХНЯЯ ПАНЕЛЬ: Заголовок + Фильтры -->
    <div class="panel mb-2" style="padding: 10px 15px;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0 fw-bold">🕘 История изменений IPAM</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <select id="filter-period" class="form-select form-select-sm" style="width:120px;">
                    <option value="today">Сегодня</option>
                    <option value="7d">7 дней</option>
                    <option value="30d" selected>30 дней</option>
                    <option value="all">Всё</option>
                </select>
                <select id="filter-action" class="form-select form-select-sm" style="width:140px;">
                    <option value="">Все действия</option>
                    <option value="assign">Выдан</option>
                    <option value="release">Освобождён</option>
                    <option value="reserve">Резерв</option>
                    <option value="change">Изменение</option>
                    <option value="conflict">Конфликт</option>
                </select>
                <select id="filter-user" class="form-select form-select-sm" style="width:160px;">
                    <option value="0">Все</option>
                    <option value="-1">система</option>
                    <!-- Пользователи подгрузятся AJAX -->
                </select>
                <input type="text" id="filter-search" class="form-control form-control-sm" style="width:220px;"
                       placeholder="IP, hostname, MAC, комментарий…">
                <button class="btn btn-sm btn-primary" id="btn-apply-filter">Найти</button>
            </div>
        </div>
    </div>

    <!-- таблица без панели-обёртки: единый стиль — рамок вокруг таблиц нет -->
    <div id="ipam-history-body">
        <table id="ipam-history-table" class="table table-hover table-bordered w-100">
            <thead>
                <tr>
                    <th>Когда</th>
                    <th>IP</th>
                    <th>Подсеть</th>
                    <th>Действие</th>
                    <th>Статус</th>
                    <th>Hostname</th>
                    <th>MAC</th>
                    <th>Кто</th>
                    <th>Комментарий</th>
                </tr>
            </thead>
        </table>
        <div class="small text-muted mt-1">Показаны последние 500 записей, подходящих под фильтры.</div>
    </div>
</div>

<script>
var ACTION_BADGE = {
    assign:   'bg-success',
    release:  'bg-secondary',
    reserve:  'bg-info text-dark',
    change:   'bg-warning text-dark',
    conflict: 'bg-danger'
};

var ipamHistoryTable = new DataTable('#ipam-history-table', {
    serverSide: false,
    ajax: {
        url: '/mod/ipam_history/data.php',
        type: 'GET',
        data: function (d) {
            d.period  = document.getElementById('filter-period').value;
            d.action  = document.getElementById('filter-action').value;
            d.user_id = document.getElementById('filter-user').value;
            d.search  = document.getElementById('filter-search').value;
        },
        dataSrc: function (json) {
            // Заполняем селект пользователей (один раз)
            var userSel = document.getElementById('filter-user');
            if (userSel.options.length <= 2 && json.users) {
                json.users.forEach(function (u) {
                    var opt = document.createElement('option');
                    opt.value = u.user_id;
                    opt.textContent = u.user_fio;
                    userSel.appendChild(opt);
                });
            }
            return json.data;
        }
    },
    order: [[0, 'desc']],
    pageLength: 50,
    // высота — по фактической позиции таблицы: от её верха до низа окна минус
    // служебные строки DataTables (info, pageLength+пагинация), сноска под таблицей
    // и паддинги; иначе низ таблицы уходит за экран (скролл всей страницы)
    scrollY: (function () {
        // якорь — стабильная обёртка, а не сама таблица: после инициализации
        // DataTables переносит её внутрь scroll-контейнера и позиция смещается
        var p = document.getElementById('ipam-history-body');
        return Math.max(150, Math.floor(window.innerHeight - p.getBoundingClientRect().top - 160)) + 'px';
    })(),
    columns: [
        { data: 'created_at', className: 'font-monospace small', width: '150px' },
        { data: 'ip', className: 'font-monospace', width: '110px' },
        { data: 'subnet', className: 'font-monospace small', width: '130px' },
        {
            data: null, width: '110px',
            render: function (row) {
                var cls = ACTION_BADGE[row.action] || 'bg-secondary';
                return '<span class="badge ' + cls + '">' + escapeHtml(row.action_ru) + '</span>';
            }
        },
        { data: 'status_ch', className: 'small text-muted', width: '130px', render: DataTable.render.text() },
        { data: 'hostname', className: 'small', render: DataTable.render.text() },
        { data: 'mac', className: 'font-monospace small', width: '140px' },
        { data: 'user_fio', width: '130px', render: DataTable.render.text() },
        { data: 'comment', className: 'small text-muted', render: DataTable.render.text() }
    ]
});

// пересчёт высоты таблицы при изменении размера окна
// (модуль грузится в #divfr; если раздел закрыт — таблицы нет в DOM, выходим)
window.addEventListener('resize', function () {
    var t = document.getElementById('ipam-history-table');
    if (!t || !document.body.contains(t)) return;
    var h = Math.max(150, Math.floor(window.innerHeight - document.getElementById('ipam-history-body').getBoundingClientRect().top - 160));
    var st = ipamHistoryTable.settings()[0];
    if (st.oScroll && st.oScroll.sY !== h + 'px') {
        st.oScroll.sY = h + 'px';
        ipamHistoryTable.draw(false);
    }
});

document.getElementById('btn-apply-filter').addEventListener('click', function () {
    ipamHistoryTable.ajax.reload();
});
document.getElementById('filter-search').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') ipamHistoryTable.ajax.reload();
});
</script>

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
            <h5 class="mb-0 fw-bold">📉 Отчёт по инцидентам</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <select id="filter-period" class="form-select form-select-sm" style="width:120px;">
                    <option value="today">Сегодня</option>
                    <option value="7d">7 дней</option>
                    <option value="30d" selected>30 дней</option>
                    <option value="90d">90 дней</option>
                    <option value="all">Всё</option>
                </select>
                <select id="filter-cause" class="form-select form-select-sm" style="width:140px;">
                    <option value="">Все причины</option>
                    <option value="power">Питание</option>
                    <option value="access">Доступ</option>
                    <option value="provider">Провайдер</option>
                    <option value="hardware">Железо</option>
                    <option value="config">Конфиг</option>
                    <option value="other">Другое</option>
                </select>
                <input type="text" id="filter-search" class="form-control form-control-sm" style="width:200px;"
                       placeholder="Устройство, IP…">
                <button class="btn btn-sm btn-primary" id="btn-apply-filter">Найти</button>
            </div>
        </div>
    </div>

    <!-- СВОДКА -->
    <div class="row g-2 mb-2" id="inc-summary">
        <div class="col">
            <div class="panel text-center py-2">
                <div class="fs-4 fw-bold" id="sum-total">—</div>
                <div class="small text-muted">инцидентов</div>
            </div>
        </div>
        <div class="col">
            <div class="panel text-center py-2">
                <div class="fs-4 fw-bold" id="sum-devices">—</div>
                <div class="small text-muted">устройств затронуто</div>
            </div>
        </div>
        <div class="col">
            <div class="panel text-center py-2">
                <div class="fs-4 fw-bold" id="sum-sum">—</div>
                <div class="small text-muted">суммарный простой</div>
            </div>
        </div>
        <div class="col">
            <div class="panel text-center py-2">
                <div class="fs-4 fw-bold" id="sum-avg">—</div>
                <div class="small text-muted">средняя длительность</div>
            </div>
        </div>
        <div class="col">
            <div class="panel text-center py-2">
                <div class="fs-4 fw-bold text-danger" id="sum-open">—</div>
                <div class="small text-muted">открыто сейчас</div>
            </div>
        </div>
    </div>

    <div class="cmd-layout">
        <!-- ЛЕВАЯ КОЛОНКА: топ проблемных (шире стандартной колонки — 6 колонок) -->
        <div class="cmd-form" style="flex-basis: 620px;">
            <div class="panel cmd-panel">
                <div class="mb-2 fw-semibold">Топ проблемных устройств</div>
                <!-- скролл внутри колонки: список топа не ограничен по числу устройств -->
                <div style="flex: 1 1 auto; min-height: 0; overflow-y: auto;">
                    <table class="table table-sm table-hover w-100" id="inc-top-table">
                        <thead>
                            <tr>
                                <th>Устройство</th>
                                <th>Локация</th>
                                <th title="Количество инцидентов">Кол-во</th>
                                <th title="Суммарный простой">Простой</th>
                                <th title="Самый долгий инцидент">Макс.</th>
                                <th title="Последний инцидент">Последний</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: все инциденты периода -->
        <div class="cmd-table">
            <table id="inc-table" class="table table-hover table-bordered w-100">
                <thead>
                    <tr>
                        <th>Начало</th>
                        <th>Окончание</th>
                        <th>Длит.</th>
                        <th>Устройство</th>
                        <th>Локация</th>
                        <th>Причина</th>
                        <th>Комментарий</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<script>
function fmtDur(min) {
    if (min < 60) return min + ' мин';
    if (min < 1440) return (Math.round(min / 60 * 10) / 10) + ' ч';
    return (Math.round(min / 1440 * 10) / 10) + ' дн';
}

var incTable = new DataTable('#inc-table', {
    serverSide: false,
    ajax: {
        url: '/mod/incidents_report/data.php',
        type: 'GET',
        data: function (d) {
            d.period = document.getElementById('filter-period').value;
            d.cause  = document.getElementById('filter-cause').value;
            d.search = document.getElementById('filter-search').value;
        },
        dataSrc: function (json) {
            // сводка
            document.getElementById('sum-total').textContent   = json.summary.total;
            document.getElementById('sum-devices').textContent = json.summary.devices;
            document.getElementById('sum-sum').textContent     = fmtDur(json.summary.sum_min);
            document.getElementById('sum-avg').textContent     = fmtDur(json.summary.avg_min);
            document.getElementById('sum-open').textContent    = json.summary.open;

            // топ проблемных
            var tb = document.querySelector('#inc-top-table tbody');
            tb.innerHTML = '';
            json.top.forEach(function (t) {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td class="small fw-semibold">' + escapeHtml(t.device) +
                        (t.ip ? '<div class="text-muted font-monospace" style="font-size:0.8em">' + escapeHtml(t.ip) + '</div>' : '') + '</td>' +
                    '<td class="small">' + escapeHtml(t.loc || '—') + '</td>' +
                    '<td class="small text-center">' + t.cnt + '</td>' +
                    '<td class="small">' + fmtDur(t.sum_min) + '</td>' +
                    '<td class="small">' + fmtDur(t.max_min) + '</td>' +
                    '<td class="small text-muted font-monospace" style="font-size:0.8em">' + escapeHtml(t.last) + '</td>';
                tb.appendChild(tr);
            });
            if (!json.top.length) {
                tb.innerHTML = '<tr><td colspan="6" class="text-muted text-center">Нет инцидентов за период</td></tr>';
            }
            return json.data;
        }
    },
    order: [[0, 'desc']],
    pageLength: 50,
    // высота — по фактической позиции колонки, а не «на глаз»: от верха колонки
    // до низа окна минус служебные строки DataTables (info, pageLength+пагинация)
    // и нижний паддинг модуля; иначе низ таблицы уходит за экран (скролл страницы)
    scrollY: (function () {
        var wrap = document.getElementById('inc-table').closest('.cmd-table');
        return Math.max(150, Math.floor(window.innerHeight - wrap.getBoundingClientRect().top - 160)) + 'px';
    })(),
    columns: [
        { data: 'started', className: 'font-monospace small', width: '140px' },
        {
            data: null, className: 'font-monospace small', width: '140px',
            render: function (row) {
                return row.open ? '<span class="badge bg-danger">лежит</span>' : row.ended;
            }
        },
        { data: null, width: '80px', render: function (row) { return fmtDur(row.dur_min); } },
        {
            data: null,
            render: function (row) {
                return escapeHtml(row.device) + (row.ip ? '<div class="text-muted font-monospace" style="font-size:0.8em">' + escapeHtml(row.ip) + '</div>' : '');
            }
        },
        { data: 'loc', className: 'small', defaultContent: '', render: DataTable.render.text() },
        { data: 'cause_ru', className: 'small', width: '100px' },
        { data: 'comment', className: 'small text-muted', render: DataTable.render.text() }
    ]
});

// пересчёт высоты таблицы при изменении размера окна
// (модуль грузится в #divfr; если раздел закрыт — таблицы нет в DOM, выходим)
window.addEventListener('resize', function () {
    var t = document.getElementById('inc-table');
    if (!t || !document.body.contains(t)) return;
    var wrap = t.closest('.cmd-table');
    var h = Math.max(150, Math.floor(window.innerHeight - wrap.getBoundingClientRect().top - 160));
    var st = incTable.settings()[0];
    if (st.oScroll && st.oScroll.sY !== h + 'px') {
        st.oScroll.sY = h + 'px';
        incTable.draw(false);
    }
});

// левая колонка: глобальный fitCmdForms ставит «до низа минус 12px»,
// здесь нужно чуть короче (−32px), иначе страница чуть-чуть скроллится.
// глобальный обработчик resize зарегистрирован раньше → наш срабатывает после него
function incFitLeft() {
    var f = document.querySelector('#divfr .cmd-form');
    if (!f) return;
    f.style.height = (window.innerHeight - f.getBoundingClientRect().top - 32) + 'px';
}
window.addEventListener('resize', incFitLeft);
setTimeout(incFitLeft, 0); // после fitCmdForms (он вызывается следом за скриптом модуля)

document.getElementById('btn-apply-filter').addEventListener('click', function () {
    incTable.ajax.reload();
});
document.getElementById('filter-search').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') incTable.ajax.reload();
});
</script>

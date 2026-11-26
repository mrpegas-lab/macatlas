<?php
include_once("../../loadinc.php");
// Проверка прав на просмотр раздела (аудит 2026-11-23)
if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

?>

<div class="container-fluid py-3">
    
    <!-- ВЕРХНЯЯ ПАНЕЛЬ: Заголовок + Фильтры -->
    <div class="panel mb-2" style="padding: 10px 15px;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0 fw-bold">📜 История запусков MikroTik</h5>
            <div class="d-flex align-items-center gap-2">
                <select id="filter-period" class="form-select form-select-sm" style="width:130px;">
                    <option value="today">Сегодня</option>
                    <option value="7d" selected>7 дней</option>
                    <option value="30d">30 дней</option>
                    <option value="all">Всё</option>
                </select>
                <select id="filter-user" class="form-select form-select-sm" style="width:150px;">
                    <option value="0">Все</option>
                    <!-- Пользователи подгрузятся AJAX -->
                </select>
                <input type="text" id="filter-search" class="form-control form-control-sm" style="width:200px;" placeholder="Команда, hostname, IP...">
                <label class="form-check-label small">
                    <input type="checkbox" id="filter-only-fail" class="form-check-input me-1"> Ошибки
                </label>
                <button class="btn btn-sm btn-primary" id="btn-apply-filter">Найти</button>
            </div>
        </div>
    </div>

    <div class="cmd-layout">
        <!-- ЛЕВАЯ КОЛОНКА: ДЕТАЛИ ВЫБРАННОГО ЗАПУСКА -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 fw-semibold">Детали запуска</div>
                <div id="run-details" class="log-box">
                    <div class="text-muted p-3">Выберите запись в таблице справа</div>
                </div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: ТАБЛИЦА -->
        <div class="cmd-table">
            <table id="mt-history-table" class="table table-hover table-bordered w-100">
                <thead>
                    <tr>
                        <th>Когда</th>
                        <th>Кто</th>
                        <th>Режим</th>
                        <th>Устройств</th>
                        <th>OK / FAIL</th>
                        <th>Шаблон (первые строки)</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<script>
var mtHistoryTable = null;

// Инициализация таблицы
mtHistoryTable = new DataTable('#mt-history-table', {
    serverSide: false,
    ajax: {
        url: '/mod/mt_history/data.php',
        type: 'GET',
        data: function(d) {
            d.period = document.getElementById('filter-period').value;
            d.user_id = document.getElementById('filter-user').value;
            d.search = document.getElementById('filter-search').value;
            d.only_fail = document.getElementById('filter-only-fail').checked ? 1 : 0;
        },
        dataSrc: function(json) {
            // Заполняем селект пользователей
            var userSel = document.getElementById('filter-user');
            if (userSel.options.length <= 1) {
                json.users.forEach(function(u) {
                    var opt = document.createElement('option');
                    opt.value = u.user_id;
                    opt.textContent = u.user_fio;
                    userSel.appendChild(opt);
                });
            }
            return json.data;
        }
    },
    select: {
        style: 'single',
        selector: 'td'
    },
    order: [[0, 'desc']],
    pageLength: 50,
    columns: [
        { data: 'created_at', className: 'font-monospace small', width: '150px' },
        { data: 'user_fio', width: '140px' },
        {
            data: 'mode',
            width: '80px',
            render: function(data) {
                var cls = 'badge-' + data;
                return '<span class="badge ' + cls + '">' + data + '</span>';
            }
        },
        { data: 'total', width: '90px' },
        {
            data: null,
            width: '130px',
            render: function(data) {
                var html = '<span class="badge badge-ok">' + data.ok_count + '</span>';
                if (data.fail_count > 0) {
                    html += ' <span class="badge badge-fail">' + data.fail_count + '</span>';
                }
                return html;
            }
        },
        { data: 'cmd_preview', className: 'small text-muted', style: 'font-family: ui-monospace, monospace;' }
    ],
    drawCallback: function() {
        // При перерисовке (например, при поиске) сбрасываем детали
        document.getElementById('run-details').innerHTML =
            '<div class="text-muted p-3">Выберите запись в таблице справа</div>';
    }
});

// Клик по строке — загружаем детали в левую панель
mtHistoryTable.on('select', function(e, dt, type, indexes) {
    if (type === 'row') {
        var rowData = dt.row(indexes[0]).data();
        if (!rowData) return;

        var panel = document.getElementById('run-details');
        panel.innerHTML = '<div class="text-muted p-3">Загрузка...</div>';

        fetch('/mod/mt_history/details.php?id=' + rowData.id)
            .then(function(r) { return r.text(); })
            .then(function(html) {
                panel.innerHTML = html;
            })
            .catch(function(err) {
                panel.innerHTML = '<div class="text-danger p-3">Ошибка: ' + err.message + '</div>';
            });
    }
});

// Кнопка "Найти" — перезагружаем таблицу с новыми фильтрами
document.getElementById('btn-apply-filter').addEventListener('click', function() {
    mtHistoryTable.ajax.reload();
});
</script>
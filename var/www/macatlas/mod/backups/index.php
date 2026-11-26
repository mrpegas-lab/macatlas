<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">

    <!-- === ДАШБОРД: сводка по бэкапам === -->
    <div class="row g-2 mb-3" id="bkp-dash">
        <div class="col-6 col-xl-3">
            <div class="panel text-center py-2 bkp-dash-card" data-filter="all" role="button">
                <div class="fs-4 fw-bold" id="bkp-dash-total">—</div>
                <div class="small text-muted">Всего локаций</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="panel text-center py-2 bkp-dash-card" data-filter="ok" role="button">
                <div class="fs-4 fw-bold text-success" id="bkp-dash-ok">—</div>
                <div class="small text-muted">Свежие (до 24 ч)</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="panel text-center py-2 bkp-dash-card" data-filter="stale" role="button">
                <div class="fs-4 fw-bold text-danger" id="bkp-dash-stale">—</div>
                <div class="small text-muted">Нет бэкапа &gt; 24 ч</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="panel text-center py-2 bkp-dash-card" data-filter="changed" role="button">
                <div class="fs-4 fw-bold text-warning" id="bkp-dash-changed">—</div>
                <div class="small text-muted">Есть изменения</div>
            </div>
        </div>
    </div>

    <!-- === ДАШБОРД: по устройствам (в локации может быть много железа) === -->
    <div class="row g-2 mb-3">
        <div class="col-4">
            <div class="panel text-center py-2">
                <div class="fs-4 fw-bold" id="bkp-dash-dev-total">—</div>
                <div class="small text-muted">Всего устройств</div>
            </div>
        </div>
        <div class="col-4">
            <div class="panel text-center py-2">
                <div class="fs-4 fw-bold text-success" id="bkp-dash-dev-fresh">—</div>
                <div class="small text-muted">Свежие (до 24 ч)</div>
            </div>
        </div>
        <div class="col-4">
            <div class="panel text-center py-2">
                <div class="fs-4 fw-bold text-danger" id="bkp-dash-dev-none">—</div>
                <div class="small text-muted">Без бэкапа</div>
            </div>
        </div>
    </div>

    <div class="row g-3">

        <!-- === ЛЕВАЯ КОЛОНКА: локации === -->
        <div class="col-12 col-xl-7">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">💾 Локации</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="bkp-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по локациям...">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="bkp-reload">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="bkp-loc-table" class="table table-hover w-100" style="cursor:pointer;">
                <thead>
                    <tr>
                        <th>Локация</th>
                        <th>Тип</th>
                        <th class="text-center">Файлов</th>
                        <th>Последний бэкап</th>
                        <th class="text-center">Статус</th>
                        <th class="text-center">Изменения</th>
                        <th style="display:none;"></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: файлы выбранной локации === -->
        <div class="col-12 col-xl-5">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold" id="bkp-files-title">Файлы бэкапов</div>
                <button class="btn btn-sm btn-outline-secondary" id="bkp-files-refresh" disabled>
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>

            <div id="bkp-alert" class="d-none mb-2"></div>
            <div id="bkp-files" class="panel p-2" style="min-height: 300px;">
                <div class="text-muted p-3">Выберите локацию в таблице слева</div>
            </div>
        </div>

    </div>
</div>

<!-- Модалка diff (та же разметка, что в конфигураторе) -->
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

<script>
(function() {
    var currentLocId   = null;
    var currentLocName = '';
    var currentDevice  = null;    // null = вся локация; '' = общие файлы; имя = устройство
    var dashFilter     = 'all';   // активный фильтр дашборда: all / ok / stale / changed
    var locData        = {};      // id локации -> строка данных (для подстрок устройств)

    // escapeHtml — общая реализация в /plugins/crud.js (аудит 2026-11-23, Н1+Н2)

    // Бейджи статуса/изменений (общие для таблицы и подстрок устройств)
    function statusBadge(v) {
        if (v === 'ok')      return '<span class="badge bg-success">свежий</span>';
        if (v === 'warning') return '<span class="badge bg-warning text-dark">&gt; 24 ч</span>';
        if (v === 'error')   return '<span class="badge bg-danger">&gt; 48 ч</span>';
        return '<span class="badge bg-secondary">нет бэкапов</span>';
    }
    function changedBadge(v) {
        if (v === true)  return '<span class="badge bg-warning text-dark" title="Последний .rsc отличается от предыдущего">⚠️ изменён</span>';
        if (v === false) return '<span class="badge bg-success">без изменений</span>';
        return '<span class="text-muted">—</span>';
    }

    // ---------- Таблица локаций ----------
    // serverSide/select переопределяем явно: в index.php заданы глобальные
    // дефолты (serverSide: true, select), которые ломают клиентскую таблицу
    var table = new DataTable('#bkp-loc-table', {
        serverSide: false,
        processing: false,
        select: false,
        scrollY: 'calc(100vh - 470px)',   // ниже из-за двух рядов карточек дашборда
        ajax: { url: '/mod/backups/data.php', dataSrc: 'data' },
        columns: [
            { data: 'new_name',
              render: function (v, type, row) {
                  // у локации с несколькими устройствами — стрелка раскрытия
                  if (row.devices && row.devices.length > 0) {
                      return '<span class="bkp-exp" title="Устройства локации"><i class="bi bi-chevron-right"></i></span> ' + v;
                  }
                  return '<span class="bkp-exp-space"></span> ' + v;
              } },
            { data: 'type_name', defaultContent: '' },
            { data: 'files_cnt', className: 'text-center' },
            { data: 'last_rsc', defaultContent: '',
              render: function (v, type, row) {
                  if (!v) return '<span class="text-muted">—</span>';
                  return '<span title="' + row.age_hours + ' ч. назад">' + v + '</span>';
              } },
            { data: 'status', className: 'text-center',
              render: function (v) { return statusBadge(v); } },
            { data: 'last_changed', className: 'text-center',
              render: function (v) { return changedBadge(v); } },
            { data: 'fkey', visible: false }   // скрытая колонка для фильтра дашборда
        ],
        // Начальная сортировка отключена: порядок задаёт SQL (ORDER BY type_id, new_name)
        order: [],
        language: { url: '/plugins/DataTables/ru.json' },
        pageLength: 25,
        initComplete: function () { reloadDash(); }
    });

    // Сохраняем строки в карту для подстрок устройств
    table.on('xhr.dt', function (e, settings, json) {
        locData = {};
        (json.data || []).forEach(r => locData[r.id] = r);
    });

    // Подстрока с устройствами локации (с отступом)
    function deviceChild(d) {
        var rows = d.devices.map(dev =>
            '<tr class="bkp-devsel" data-loc="' + d.id + '" data-dev="' + dev.device + '">' +
                '<td>' + (dev.device || '— общее —') + '</td>' +
                '<td class="text-center">' + dev.files_cnt + '</td>' +
                '<td>' + (dev.last_rsc || '—') + '</td>' +
                '<td class="text-center">' + statusBadge(dev.status) + '</td>' +
                '<td class="text-center">' + changedBadge(dev.last_changed) + '</td>' +
            '</tr>'
        ).join('');
        return '<table class="bkp-dev-table">' +
            '<thead><tr><th>Устройство</th><th class="text-center">Файлов</th>' +
            '<th>Последний бэкап</th><th class="text-center">Статус</th>' +
            '<th class="text-center">Изменения</th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table>';
    }

    // Выбор строки: локация целиком или раскрытие устройств
    table.on('click', 'tbody tr', function (e) {
        var tr  = this;
        var row = table.row(tr);
        var d   = row.data();
        if (!d) return;

        // Клик по стрелке — раскрыть/свернуть список устройств
        if (e.target.closest('.bkp-exp') && d.devices.length > 0) {
            var icon = tr.querySelector('.bkp-exp i');
            if (row.child.isShown()) {
                row.child.hide();
                icon.className = 'bi bi-chevron-right';
            } else {
                row.child(deviceChild(d)).show();
                icon.className = 'bi bi-chevron-down';
            }
            return;
        }

        selectLocation(tr, d.id, d.new_name, null);
    });

    // Клик по устройству в раскрытой подстроке
    document.addEventListener('click', function (e) {
        var el = e.target.closest('.bkp-devsel');
        if (!el) return;
        e.stopPropagation();
        var locId = parseInt(el.dataset.loc, 10);
        var d = locData[locId];
        // строка локации = предыдущая строка основной таблицы перед раскрытым блоком
        var childTr = el.closest('#bkp-loc-table > tbody > tr');
        var tr = childTr ? childTr.previousElementSibling : null;
        selectLocation(tr, locId, d ? d.new_name : '', el.dataset.dev);
    });

    function selectLocation(tr, locId, locName, device) {
        document.querySelectorAll('#bkp-loc-table tbody tr')
            .forEach(t => t.classList.remove('table-active'));
        if (tr) tr.classList.add('table-active');
        currentLocId   = locId;
        currentLocName = locName || '';
        currentDevice  = device;
        document.getElementById('bkp-files-refresh').disabled = false;
        loadFiles();
    }

    // Поиск
    document.getElementById('bkp-filter').addEventListener('input', function () {
        table.search(this.value).draw();
    });
    document.getElementById('bkp-reload').addEventListener('click', function () {
        table.ajax.reload(function () { reloadDash(); }, false);
    });
    document.getElementById('bkp-files-refresh').addEventListener('click', loadFiles);

    // ---------- Дашборд ----------
    function reloadDash() {
        fetch('/mod/backups/data.php')
            .then(r => r.json())
            .then(d => {
                document.getElementById('bkp-dash-total').textContent   = d.dash.total;
                document.getElementById('bkp-dash-ok').textContent      = d.dash.ok;
                document.getElementById('bkp-dash-stale').textContent   = d.dash.stale;
                document.getElementById('bkp-dash-changed').textContent = d.dash.changed;
                document.getElementById('bkp-dash-dev-total').textContent = d.dash.dev_total;
                document.getElementById('bkp-dash-dev-fresh').textContent = d.dash.dev_fresh;
                document.getElementById('bkp-dash-dev-none').textContent  = d.dash.dev_none;
            });
    }

    // Клик по карточке дашборда фильтрует таблицу по скрытой колонке fkey
    var DASH_RE = {
        all:     '',
        ok:      '^ok',
        stale:   'warning|error|none',
        changed: 'changed'
    };
    document.querySelectorAll('.bkp-dash-card').forEach(function (card) {
        card.addEventListener('click', function () {
            dashFilter = card.dataset.filter;
            document.querySelectorAll('.bkp-dash-card').forEach(c => c.classList.remove('border', 'border-info'));
            if (dashFilter !== 'all') card.classList.add('border', 'border-info');
            table.column(6).search(DASH_RE[dashFilter] || '', true, false).draw();
        });
    });

    // ---------- Файлы выбранной локации (переиспользуем API конфигуратора) ----------
    function loadFiles() {
        if (!currentLocId) return;
        var box      = document.getElementById('bkp-files');
        var alertBox = document.getElementById('bkp-alert');
        document.getElementById('bkp-files-title').textContent =
            currentLocName + (currentDevice !== null ? ' · ' + (currentDevice || 'общее') : '');
        box.innerHTML = '<div class="text-muted p-3">Загрузка...</div>';
        alertBox.className = 'd-none';

        fetch('/mod/mt_config/backup_list.php?locations_id=' + currentLocId)
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    box.innerHTML = '<div class="text-danger p-2">' + escapeHtml(d.error) + '</div>';
                    return;
                }

                // Плашка о свежести
                if (d.health && d.health.status !== 'ok') {
                    var alertClass = d.health.status === 'error' ? 'alert-danger' : 'alert-warning';
                    var icon = d.health.status === 'error' ? '🚨' : '⚠️';
                    var extraInfo = '';
                    if (d.health.rsc_age_hours !== null)    extraInfo += 'RSC: ' + d.health.rsc_age_hours + ' ч. назад. ';
                    if (d.health.backup_age_hours !== null) extraInfo += 'Backup: ' + d.health.backup_age_hours + ' ч. назад.';
                    alertBox.className = 'alert ' + alertClass + ' py-2 px-3 mb-2 small';
                    alertBox.innerHTML = '<strong>' + icon + ' ' + escapeHtml(d.health.message) + '</strong><br>' +
                                         '<span class="opacity-75">' + escapeHtml(extraInfo) + '</span>';
                }

                var all = (d.rsc || []).concat(d.backup || []);

                // Выбрано конкретное устройство — показываем только его файлы
                if (currentDevice !== null) {
                    all = all.filter(f => (f.device || '') === currentDevice);
                }

                if (!all.length) {
                    box.innerHTML = '<div class="text-muted p-3">Файлов нет</div>';
                    return;
                }

                // Сортировка по дате (новые сверху): пара rsc+backup одного
                // бэкапа оказывается рядом, а не в разных концах списка
                all.sort((a, b) => (a.mtime_fmt < b.mtime_fmt ? 1 : -1));

                // Строка файла; бейдж изменений — только для текстовых (.rsc/.cfg)
                // (бинарный .backup всегда чуть отличается, это не изменение конфига)
                function fileRow(f) {
                    var isText = f.name.endsWith('.rsc') || f.name.endsWith('.cfg');
                    var typeBadge = f.name.endsWith('.rsc')
                        ? '<span class="badge bg-primary">RSC</span>'
                        : (f.name.endsWith('.cfg')
                            ? '<span class="badge bg-info text-dark">CFG</span>'
                            : '<span class="badge bg-secondary">BACKUP</span>');

                    var statusBadge = '<span class="text-muted">—</span>';
                    if (isText) {
                        if (f.changed_vs_prev === true) {
                            statusBadge = '<span class="badge bg-warning text-dark">⚠️ изменён</span>';
                        } else if (f.changed_vs_prev === false) {
                            statusBadge = '<span class="badge bg-success">✅ без изменений</span>';
                        } else {
                            statusBadge = '<span class="badge bg-secondary">🆕 первый</span>';
                        }
                    }

                    var diffBtn = f.prev_id
                        ? '<button class="btn btn-sm btn-outline-info bkp-diff" data-to="' + f.id + '" data-from="' + f.prev_id + '">diff</button>'
                        : '';

                    return '<tr>' +
                        '<td>' + typeBadge + '</td>' +
                        '<td class="text-nowrap">' + f.mtime_fmt + '</td>' +
                        '<td><span class="text-truncate d-inline-block" style="max-width:180px;" title="' + escapeHtml(f.name) + '">' + escapeHtml(f.name) + '</span></td>' +
                        '<td class="text-end">' + (f.size / 1024).toFixed(1) + ' KB</td>' +
                        '<td class="text-center">' + statusBadge + '</td>' +
                        '<td class="text-end text-nowrap">' +
                            '<a class="btn btn-sm btn-outline-success" href="/mod/mt_config/backup_download.php?locations_id=' + currentLocId + '&file=' + encodeURIComponent(f.name) + '" download>⬇</a> ' +
                            diffBtn +
                        '</td>' +
                    '</tr>';
                }

                var thead = '<thead><tr>' +
                    '<th>Тип</th><th>Дата</th><th>Файл</th>' +
                    '<th class="text-end">Размер</th><th class="text-center">Статус</th><th></th>' +
                    '</tr></thead>';

                // Группировка по устройству (у площадок в локации несколько железок)
                var groups = {};
                var gOrder = [];
                all.forEach(f => {
                    var g = f.device || '';
                    if (!(g in groups)) { groups[g] = []; gOrder.push(g); }
                    groups[g].push(f);
                });

                // Одна группа без имени (салон) — простая таблица
                if (gOrder.length === 1 && gOrder[0] === '') {
                    box.innerHTML = '<table>' + thead +
                        '<tbody>' + all.map(fileRow).join('') + '</tbody></table>';
                    return;
                }

                // Несколько устройств — сворачиваемые блоки (первый раскрыт)
                var gi = 0;
                box.innerHTML = gOrder.map(g => {
                    var cid = 'bkp-dev-' + (gi++);
                    var title = g === '' ? 'Общие' : escapeHtml(g);
                    var open = gi === 1 ? ' show' : '';
                    return '<div class="bkp-dev-group mb-2">' +
                        '<div class="bkp-dev-head" data-bs-toggle="collapse" data-bs-target="#' + cid + '">' +
                            '<i class="bi bi-chevron-down"></i> ' + title +
                            ' <span class="text-muted small">(' + groups[g].length + ')</span>' +
                        '</div>' +
                        '<div class="collapse' + (open ? ' show' : '') + '" id="' + cid + '">' +
                            '<table>' + thead + '<tbody>' + groups[g].map(fileRow).join('') + '</tbody></table>' +
                        '</div>' +
                    '</div>';
                }).join('');
            })
            .catch(e => box.innerHTML = '<div class="text-danger p-2">' + e.message + '</div>');
    }

    // ---------- Diff (переиспользуем API конфигуратора) ----------
    function showDiff(toId, fromId) {
        var body = document.getElementById('bkpDiffBody');
        document.getElementById('bkpDiffTitle').textContent = 'Сравнение';
        body.innerHTML = 'Загрузка...';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('bkpDiffModal')).show();

        fetch('/mod/mt_config/backup_diff.php?to_id=' + toId + '&from_id=' + fromId)
            .then(r => r.json())
            .then(d => {
                if (d.error) { body.textContent = d.error; return; }

                document.getElementById('bkpDiffTitle').textContent = d.file + ' vs ' + d.prev;

                if (d.type === 'binary') {
                    body.textContent = d.note + '\n\n' +
                        'Новый: ' + (d.size / 1024).toFixed(1) + ' KB\n' +
                        'Старый: ' + (d.prev_size / 1024).toFixed(1) + ' KB\n' +
                        'Изменился: ' + (d.changed ? 'ДА' : 'НЕТ');
                    return;
                }
                if (!d.changed) { body.textContent = 'Файлы идентичны'; return; }

                body.innerHTML = d.lines.map(l =>
                    '<div class="' + (l[0] === '+' ? 'diff-add' : l[0] === '-' ? 'diff-del' : '') + '">' +
                    (l[0] === ' ' ? '  ' : l[0] + ' ') + escapeHtml(l[1] || '') + '</div>'
                ).join('');
            })
            .catch(e => body.textContent = e.message);
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('.bkp-diff');
        if (b) {
            e.preventDefault();
            showDiff(b.dataset.to, b.dataset.from);
        }
    });
})();
</script>

/**
 * TreeView — таблица DataTables с раскрывающимися child rows.
 * Максимум 2 уровня: родитель + дети.
 *
 * Использование:
 *   new TreeView({
 *       prefix:   'pscan',
 *       tableUrl: '/mod/pscan/data.php',
 *       columns:  [ ... ],              // колонки верхнего уровня
 *       level2: {
 *           url:              '/mod/pscan/subdata.php',
 *           param:            'id_switch',
 *           idField:          'id',
 *           hasChildrenField: 'ports_total'   // опционально
 *       }
 *   });
 */

// ============================================================
// AJAX GET с коллбэками
// ============================================================
function treeAjaxGet(url, params, onSuccess, onError) {
    var query = [];
    for (var k in params) {
        if (params.hasOwnProperty(k)) {
            query.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
        }
    }
    var fullUrl = url + (query.length ? '?' + query.join('&') : '');

    fetch(fullUrl, { method: 'GET' })
        .then(function (response) {
            return response.text().then(function (text) {
                if (response.ok) onSuccess(text);
                else onError(text || ('HTTP ' + response.status));
            });
        })
        .catch(function (err) { onError(err.message); });
}

// ============================================================
// Конструктор
// ============================================================
function TreeView(cfg) {
    this.cfg     = cfg;
    this.tableEl = document.getElementById(cfg.prefix + '-table');
    this.table   = null;

    this.init();
}

// ============================================================
// Инициализация DataTables
// ============================================================
TreeView.prototype.init = function () {
    var self = this;

    // Высота таблицы = высота окна минус место под навбар и карточку
    var tableHeight = window.innerHeight - 260;
    if (tableHeight < 300) tableHeight = 300;

    // Первая колонка — контрол раскрытия
    var columns = [
        {
            data:      null,
            title:     '',
            width:     '30px',
            orderable: false,
            className: 'text-center',
            render: function (data, type, row) {
                if (self.hasChildren(row)) {
                    return '<a href="#" class="tv-toggle text-decoration-none">' +
                           '<i class="bi bi-plus-square"></i></a>';
                }
                return '';
            }
        }
    ];

    for (var i = 0; i < this.cfg.columns.length; i++) {
        columns.push(this.cfg.columns[i]);
    }

    this.table = new DataTable(this.tableEl, {
        ajax: {
            url:  this.cfg.tableUrl,
            type: 'POST'
        },
        serverSide: true,
        processing: true,
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100, 250, 500],
        order: this.cfg.order || [[1, 'asc']],
        columns: columns,
        language: { url: '/plugins/DataTables/ru.json' },
        scrollY:        tableHeight + 'px',
        scrollCollapse: true,
        autoWidth:      false
    });

    // Клик по иконке «+» первого уровня
    this.tableEl.addEventListener('click', function (e) {
        var toggle = e.target.closest('a.tv-toggle');
        if (!toggle) return;
        e.preventDefault();

        var tr  = toggle.closest('tr');
        var row = self.table.row(tr);
        self.toggleLevel2(row, tr);
    });
};

// ============================================================
// Есть ли у строки дети?
// ============================================================
TreeView.prototype.hasChildren = function (row) {
    if (!this.cfg.level2) return false;
    if (this.cfg.level2.hasChildrenField) {
        return row[this.cfg.level2.hasChildrenField] > 0;
    }
    return true;
};

// ============================================================
// Открытие/закрытие второго уровня
// ============================================================
TreeView.prototype.toggleLevel2 = function (row, tr) {
    var self = this;

    // Состояние храним на самой строке — надёжнее, чем row.child.isShown()
    var isOpen = tr.getAttribute('data-tv-open') === '1';

    if (isOpen) {
        row.child.hide();
        tr.setAttribute('data-tv-open', '0');
        this.setToggleIcon(tr, true);
        return;
    }

    row.child('<div class="text-muted p-2">Загрузка...</div>').show();
    tr.setAttribute('data-tv-open', '1');
    this.setToggleIcon(tr, false);

    var rowData = row.data();
    var params  = {};
    params[this.cfg.level2.param] = rowData[this.cfg.level2.idField];

    treeAjaxGet(this.cfg.level2.url, params,
        function (html) {
            row.child('<div class="tv-level2 p-2">' + html + '</div>').show();
        },
        function (err) {
            row.child('<div class="text-danger p-2">Ошибка: ' + err + '</div>').show();
        }
    );
};

// ============================================================
// Переключение иконки + / −
// ============================================================
TreeView.prototype.setToggleIcon = function (tr, isPlus) {
    var icon = tr.querySelector('a.tv-toggle i');
    if (!icon) return;
    icon.classList.toggle('bi-plus-square',  isPlus);
    icon.classList.toggle('bi-dash-square', !isPlus);
};
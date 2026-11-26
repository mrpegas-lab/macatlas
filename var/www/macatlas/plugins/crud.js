/**
 * CrudModule — универсальный движок таблицы + формы для DataTables.
 * Все базовые настройки (язык, пагинация, серверный режим) 
 * берутся из глобального index.php.
 */

// ============================================================
// AJAX-обёртка: снаружи — коллбэки, внутри — fetch
// ============================================================
function ajaxPost(url, formData, onSuccess, onError) {
    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(function (response) {
        return response.text().then(function (text) {
            if (response.ok) {
                onSuccess(text);
            } else {
                onError(text || ('HTTP ' + response.status));
            }
        });
    })
    .catch(function (err) {
        onError(err.message);
    });
}

// ============================================================
// Конструктор
// ============================================================
function CrudModule(cfg) {
    this.cfg = cfg;

    // === Ссылки на элементы ===
    this.tableEl    = document.getElementById(cfg.prefix + '-table');
    this.formEl     = document.getElementById(cfg.prefix + '-form');
    this.msgEl      = document.getElementById(cfg.prefix + '-msg');
    this.modeEl     = document.getElementById(cfg.prefix + '-mode');
    this.btnSave    = document.getElementById('btn-save');
    this.btnNew     = document.getElementById('btn-new');
    this.btnDel     = document.getElementById('btn-del');
    this.btnRefresh = document.getElementById('btn-refresh');

    // === Права (из data-атрибутов формы) ===
    this.canAdd  = this.formEl.getAttribute('data-can-add')  === '1';
    this.canEdit = this.formEl.getAttribute('data-can-edit') === '1';
    this.canDel  = this.formEl.getAttribute('data-can-del')  === '1';

    this.table = null;
    this._msgTimer        = null;
    this._drawn           = false;   // первый draw пропускаем
    this._skipResetOnDraw = false;   // флаг «не сбрасывать форму при draw»

    // === Запуск ===
    this.initTable();
    this.initForm();
    this.initButtons();
}

// ============================================================
// Инициализация таблицы DataTables
// ============================================================
CrudModule.prototype.initTable = function () {
    var self = this;

    // Оставляем только специфичные для модуля настройки.
    // serverSide, processing, pageLength, language и autoWidth 
    // автоматически подтянутся из глобального DataTable.defaults в index.php
    this.table = new DataTable(this.tableEl, {
        ajax: {
            url:  this.cfg.tableUrl,
            type: 'POST'
        },
        order: this.cfg.order || [[0, 'desc']],
        columns: this.cfg.columns        
    });

    this.table.on('select', function (e, dt, type, indexes) {
        if (type !== 'row') return;
        self.onRowSelect(dt.row(indexes[0]).data());
    });

    // Сброс формы при перерисовке таблицы (смена страницы, сортировка, поиск)
    this.table.on('draw', function () {
        if (!self._drawn) {
            self._drawn = true;
            return;
        }
        if (self._skipResetOnDraw) {
            return;
        }
        self.resetForm();
    });
};

// ============================================================
// Инициализация формы
// ============================================================
CrudModule.prototype.initForm = function () {
    var self = this;
    this.formEl.onsubmit = function (e) {
        e.preventDefault();
        self.onSubmit();
    };
};

// ============================================================
// Инициализация кнопок
// ============================================================
CrudModule.prototype.initButtons = function () {
    var self = this;

    if (this.btnNew) {
        this.btnNew.onclick = function () {
            self.resetForm();
        };
    }

    if (this.btnDel) {
        this.btnDel.onclick = function () {
            self.onDelete();
        };
    }

    if (this.btnRefresh) {
        this.btnRefresh.onclick = function () {
            self.table.ajax.reload(null, false);
        };
    }
};

// ============================================================
// Заполнение формы при выборе строки
// ============================================================
CrudModule.prototype.onRowSelect = function (row) {
    var fields = this.cfg.formFields;

    for (var i = 0; i < fields.length; i++) {
        var name = fields[i];
        var el   = this.formEl.elements[name];
        if (!el) continue;
        el.value = (row[name] !== undefined && row[name] !== null) ? row[name] : '';
    }

    if (this.modeEl) {
        this.modeEl.textContent = 'Редактирование #' + row.id;
        this.modeEl.className   = 'badge bg-warning text-dark';
    }
    if (this.btnDel) {
        this.btnDel.disabled = !this.canDel;
    }

    this.clearMsg();

    if (typeof this.cfg.afterRowSelect === 'function') {
        this.cfg.afterRowSelect.call(this, row);
    }
};

// ============================================================
// Сброс формы («Новый»)
// ============================================================
CrudModule.prototype.resetForm = function () {
    this.formEl.reset();

    var idEl = this.formEl.elements['id'];
    if (idEl) idEl.value = '';

    if (this.modeEl) {
        this.modeEl.textContent = 'Новая запись';
        this.modeEl.className   = 'badge bg-secondary';
    }
    if (this.btnDel) {
        this.btnDel.disabled = true;
    }

    this.clearMsg();
    this.table.rows().deselect();
    if (typeof this.cfg.afterReset === 'function') {
        this.cfg.afterReset.call(this);
    }
};

// ============================================================
// Отправка формы (add / edit)
// ============================================================
CrudModule.prototype.onSubmit = function () {
    var self    = this;
    var idEl    = this.formEl.elements['id'];
    var isEdit  = idEl && idEl.value !== '';
    var savedId = idEl ? idEl.value : null;

    if (isEdit && !this.canEdit) { this.showMsg('Нет прав на редактирование', 'danger'); return; }
    if (!isEdit && !this.canAdd) { this.showMsg('Нет прав на добавление',    'danger'); return; }

    if (this.btnSave) this.btnSave.disabled = true;

    var fd = new FormData(this.formEl);
    fd.append('oper', isEdit ? 'edit' : 'add');

    ajaxPost(this.cfg.formUrl, fd, function (text) {
        if (self.btnSave) self.btnSave.disabled = false;

        if (text.trim() === '') {
            self.showMsg(isEdit ? 'Запись обновлена' : 'Запись добавлена', 'success');

            if (isEdit) {
                // Возвращаем выделение на ту же запись
                self.reloadAndReselect(savedId);
            } else {
                // Новая запись — просто сброс
                self.table.ajax.reload(null, false);
                self.resetForm();
            }
        } else {
            self.showMsg('Ошибка: ' + text, 'danger');
        }
    }, function (err) {
        if (self.btnSave) self.btnSave.disabled = false;
        self.showMsg('Сеть: ' + err, 'danger');
    });
};

// ============================================================
// Перезагрузка таблицы с восстановлением выделения
// ============================================================
CrudModule.prototype.reloadAndReselect = function (id) {
    var self = this;

    this._skipResetOnDraw = true;

    this.table.ajax.reload(function () {
        self._skipResetOnDraw = false;

        if (!id) {
            self.resetForm();
            return;
        }

        // Ищем строку с нужным id на текущей странице
        var found = false;
        self.table.rows().every(function () {
            var rowData = this.data();
            if (String(rowData.id) === String(id)) {
                this.select();
                found = true;
            }
        });

        // Если строки нет на текущей странице — сбрасываем форму
        if (!found) {
            self.resetForm();
        }
    }, false);
};

// ============================================================
// Удаление
// ============================================================
CrudModule.prototype.onDelete = function () {
    var self = this;
    var idEl = this.formEl.elements['id'];
    var id   = idEl ? idEl.value : '';

    if (!id) return;
    if (!this.canDel) { this.showMsg('Нет прав на удаление', 'danger'); return; }
    if (!confirm('Удалить запись #' + id + '?')) return;

    if (this.btnDel) this.btnDel.disabled = true;

    var fd = new FormData();
    fd.append('oper', 'del');
    fd.append('id', id);

    ajaxPost(this.cfg.formUrl, fd, function (text) {
        if (self.btnDel) self.btnDel.disabled = false;

        if (text.trim() === '') {
            self.showMsg('Запись удалена', 'success');
            self.table.ajax.reload(null, false);
            self.resetForm();
        } else {
            self.showMsg('Ошибка: ' + text, 'danger');
        }
    }, function (err) {
        if (self.btnDel) self.btnDel.disabled = false;
        self.showMsg('Сеть: ' + err, 'danger');
    });
};

// ============================================================
// Сообщения
// ============================================================
CrudModule.prototype.showMsg = function (text, kind) {
    if (!this.msgEl) return;
    this.msgEl.innerHTML = '<div class="alert alert-' + kind + ' py-2 mb-0">' +
                           '<i class="bi bi-info-circle me-1"></i>' + text + '</div>';

    // Автоскрытие успешных сообщений через 3 секунды
    if (kind === 'success') {
        var self = this;
        if (this._msgTimer) clearTimeout(this._msgTimer);
        this._msgTimer = setTimeout(function () {
            self.clearMsg();
        }, 3000);
    }
};

CrudModule.prototype.clearMsg = function () {
    if (this._msgTimer) {
        clearTimeout(this._msgTimer);
        this._msgTimer = null;
    }
    if (this.msgEl) this.msgEl.innerHTML = '';
};
// ============================================================
// escapeHtml — единая реализация для всех модулей (аудит 2026-11-23, Н1).
// Раньше копия жила в mod/{mt_config,mt_maps,mt_run,mt_update,backups}/index.php.
// Экранирует & < > " ' — безопасно и для текстовых узлов, и для атрибутов.
// ============================================================
if (typeof window.escapeHtml !== 'function') {
    window.escapeHtml = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
}

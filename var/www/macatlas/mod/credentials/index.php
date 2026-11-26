<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">
        
        <!-- === ЛЕВАЯ КОЛОНКА: ФОРМА === -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="credentials-mode" class="badge bg-secondary">Новая запись</span>
                </div>
                
                <form id="credentials-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Название <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="200">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Логин</label>
                        <input type="text" name="login" class="form-control form-control-sm" maxlength="200">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Пароль</label>
                        <input type="password" name="pas" class="form-control form-control-sm" maxlength="200"
                               placeholder="оставьте пустым, чтобы не менять"
                               autocomplete="new-password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Enable пароль</label>
                        <input type="password" name="enable_pas" class="form-control form-control-sm" maxlength="200"
                               placeholder="оставьте пустым, чтобы не менять"
                               autocomplete="new-password">
                    </div>

                    <!-- Порты подключения. Пусто = порт по умолчанию -->
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label small">Порт SSH</label>
                            <input type="number" name="port_ssh" class="form-control form-control-sm"
                                   min="1" max="65535" placeholder="22">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Порт API</label>
                            <input type="number" name="port_api" class="form-control form-control-sm"
                                   min="1" max="65535" placeholder="8728">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Порт API-SSL</label>
                            <input type="number" name="port_api_ssl" class="form-control form-control-sm"
                                   min="1" max="65535" placeholder="8729">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="credentials-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">🔐 Учётные данные</div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                    <i class="bi bi-arrow-clockwise"></i> Обновить
                </button>
            </div>
            
            <table id="credentials-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    new CrudModule({
        prefix:   'credentials',
        tableUrl: '/mod/credentials/data.php',
        formUrl:  '/mod/credentials/dataAED.php',
        order:    [[1, 'asc']],
        columns: [
            { data: 'id',           title: 'ID',     visible: false },
            { data: 'name',         title: 'Название' },
            { data: 'login',        title: 'Логин' },
            { data: 'port_ssh',     title: 'SSH',     className: 'text-center', width: '70px',
              render: v => v ?? '' },
            { data: 'port_api',     title: 'API',     className: 'text-center', width: '70px',
              render: v => v ?? '' },
            { data: 'port_api_ssl', title: 'API-SSL', className: 'text-center', width: '80px',
              render: v => v ?? '' },
            { data: 'hw_count',     title: 'Устройств', className: 'text-center', width: '100px', orderable: false,
              render: function (v, type, row) {
                  // устройств на кредах = hardware + роутеры объектов (mt_sites)
                  var hw    = parseInt(v, 10) || 0;
                  var sites = parseInt(row.sites_count, 10) || 0;
                  var total = hw + sites;
                  if (total <= 0) return '<span class="text-muted">—</span>';
                  return '<span title="hardware: ' + hw + ', роутеры объектов: ' + sites + '">'
                         + total + '</span>';
              } }
        ],
        formFields: ['id', 'name', 'login', 'pas', 'enable_pas', 'port_ssh', 'port_api', 'port_api_ssl']
    });
})();
</script>
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
                    <span id="role-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="role-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Код <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control form-control-sm"
                               required maxlength="64" pattern="[a-z0-9_]+"
                               placeholder="root, admin, salon">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Название <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               required maxlength="200">
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="role-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">👥 Роли</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="role-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по названию..."
                           oninput="applyRoleFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="role-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    window.role = new CrudModule({
        prefix:   'role',
        tableUrl: '/mod/acl_roles/data.php',
        formUrl:  '/mod/acl_roles/dataAED.php',
        order:    [[2, 'asc']],   // name
        columns: [
            { data: 'id',          title: 'ID',          visible: false },
            { data: 'code',        title: 'Код' },
            { data: 'name',        title: 'Название' },
            { data: 'is_system',   title: '🔒',          width: '40px', className: 'text-center',
              render: function(d) { return d ? '<i class="bi bi-lock-fill"></i>' : ''; } },
            { data: 'staff_count', title: 'Сотрудников', width: '110px', className: 'text-center' }
        ],
        formFields: ['id', 'code', 'name']
    });
})();

function applyRoleFilter() {
    var mod = window.role;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('role-filter').value).draw();
}
</script>
<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

$roles = [];
$res = $mysqli->query("SELECT id, name FROM acl_roles ORDER BY name");
while ($r = $res->fetch_assoc()) $roles[] = $r;

$myId = (int)($_SESSION['auth'] ?? 0);
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="staff-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="staff-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Логин <span class="text-danger">*</span></label>
                        <input type="text" name="login" class="form-control form-control-sm"
                               required maxlength="50" autocomplete="off">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Пароль</label>
                        <input type="password" name="password" class="form-control form-control-sm"
                               maxlength="100" autocomplete="new-password">
                        <div class="form-text small" id="pwd-hint">
                            При редактировании — пусто = не менять
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">ФИО <span class="text-danger">*</span></label>
                        <input type="text" name="fio" class="form-control form-control-sm"
                               required maxlength="100">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Роль <span class="text-danger">*</span></label>
                        <select name="role_id" class="form-select form-select-sm" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Тип авторизации <span class="text-danger">*</span></label>
                        <select name="auth_type" class="form-select form-select-sm" required>
                            <option value="local">Локальная (пароль в БД)</option>
                            <option value="ldap">Доменная (LDAP)</option>
                        </select>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="hidden" name="is_active" id="staff-active-hidden" value="1">
                        <input type="checkbox" class="form-check-input" id="staff-active" checked
                        onchange="document.getElementById('staff-active-hidden').value = this.checked ? 1 : 0;">
                        <label class="form-check-label small" for="staff-active">Учётка активна</label>
                    </div>

                    <div class="mb-2" id="protected-note" style="display:none;">
                        <span class="badge bg-warning text-dark">🔒 Защищённая учётная запись</span>
                        <div class="form-text small">Логин, тип авторизации и активность менять нельзя.</div>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="staff-msg" class="mt-3 small"></div>
            </div>
        </div>

        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">👤 Сотрудники</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="staff-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по логину / ФИО..."
                           oninput="applyStaffFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="staff-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    window.staff = new CrudModule({
        prefix:   'staff',
        tableUrl: '/mod/acl_staff/data.php',
        formUrl:  '/mod/acl_staff/dataAED.php',
        order:    [[1, 'asc']],   // логин — сдвинулся из-за скрытого id
        columns: [
            { data: 'id',           visible: false },
            { data: 'login',        title: 'Логин' },
            { data: 'fio',          title: 'ФИО' },
            { data: 'role_name',    title: 'Роль' },
            { data: 'auth_type',    title: 'Авторизация', width: '120px',
              render: function(v) {
                  return v === 'ldap'
                      ? '<span class="badge bg-info text-dark">LDAP</span>'
                      : '<span class="badge bg-secondary">Локальная</span>';
              }
            },
            { data: 'is_active',    title: 'Активна', width: '90px',
              render: function(v) {
                  return Number(v) === 1
                      ? '<span class="badge bg-success">Да</span>'
                      : '<span class="badge bg-danger">Нет</span>';
              }
            },
            { data: 'is_protected', title: '', width: '40px',
              render: function(v) { return Number(v) === 1 ? '🔒' : ''; }
            },
            { data: 'login_date',   title: 'Последний вход', width: '160px' }
        ],
        formFields: ['id', 'login', 'password', 'fio', 'role_id', 'auth_type', 'is_active'],

         afterRowSelect: function (row) {
            var f = this.formEl;

            f.elements['password'].value = '';
            f.elements['password'].required = false;
            f.elements['password'].placeholder = 'оставьте пустым = не менять';

            f.elements['auth_type'].value = row.auth_type || 'local';

            // --- Работа с чекбоксом "Активна" ---
            var isActive = (Number(row.is_active) === 1);
            var chk = document.getElementById('staff-active');
            var hid = document.getElementById('staff-active-hidden');
            
            chk.checked = isActive;
            hid.value = isActive ? 1 : 0;

            var protectedRow = Number(row.is_protected) === 1;
            document.getElementById('protected-note').style.display = protectedRow ? '' : 'none';

            f.elements['login'].readOnly     = protectedRow;
            f.elements['auth_type'].disabled = protectedRow;
            
            // Блокируем чекбокс защищенного пользователя
            chk.disabled = protectedRow;
            // Принудительно ставим 1, чтобы сервер не ругался
            if (protectedRow) hid.value = 1;

            // нельзя удалить без права / себя / защищённую
            var myId = <?= $myId ?>;
            if (this.btnDel) {
                var self   = (Number(row.id) === myId);
                var isProt = protectedRow;
                this.btnDel.disabled = !this.canDel || self || isProt;
                this.btnDel.title = !this.canDel
                    ? 'Нет прав на удаление'
                    : (self
                        ? 'Нельзя удалить свою учётную запись'
                        : (isProt ? 'Нельзя удалить защищённую учётную запись' : ''));
            }
        },

        afterReset: function () {
            var f = this.formEl;

            f.elements['password'].value = '';
            f.elements['password'].required = true;
            f.elements['password'].placeholder = '';

            f.elements['login'].readOnly     = false;
            f.elements['auth_type'].disabled = false;
            f.elements['auth_type'].value    = 'local';
            
            // --- Сброс чекбокса ---
            var chk = document.getElementById('staff-active');
            var hid = document.getElementById('staff-active-hidden');
            
            chk.checked = true;
            chk.disabled = false;
            hid.value = 1;

            document.getElementById('protected-note').style.display = 'none';
            if (this.btnDel) this.btnDel.title = '';
        }
    });
})();

function applyStaffFilter() {
    window.staff.table.search(document.getElementById('staff-filter').value).draw();
}
</script>
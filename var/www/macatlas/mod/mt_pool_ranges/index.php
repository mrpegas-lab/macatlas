<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// Получаем группы
$groups = [];
$res = $mysqli->query("SELECT id, name FROM mt_pool_groups ORDER BY sort");
while ($r = $res->fetch_assoc()) $groups[] = $r;

// Получаем назначения
$purposes = [];
$res = $mysqli->query("SELECT code, name FROM mt_pool_purposes ORDER BY name");
while ($r = $res->fetch_assoc()) $purposes[] = $r;
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <!-- === ЛЕВАЯ КОЛОНКА: ФОРМА === -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование пула</div>
                    <span id="mt_pool_ranges-mode" class="badge bg-secondary">Новый пул</span>
                </div>

                <form id="mt_pool_ranges-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Группа (Объект/Регион)</label>
                        <select name="group_id" class="form-select form-select-sm">
                            <option value="">Сквозной (для всех)</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Назначение <span class="text-danger">*</span></label>
                        <select name="purpose" class="form-select form-select-sm" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($purposes as $p): ?>
                                <option value="<?= htmlspecialchars($p['code']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Имя пула <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="64" placeholder="lan_msk, ipip_global...">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">CIDR (Сеть) <span class="text-danger">*</span></label>
                        <input type="text" name="cidr" class="form-control form-control-sm" required placeholder="10.77.0.0/16">
                        <div class="form-text small">Формат: IP/Маска</div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small">Тип</label>
                            <select name="kind" class="form-select form-select-sm">
                                <option value="subnet">subnet</option>
                                <option value="host">host</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Шаг (бит) <span class="text-danger">*</span></label>
                            <input type="number" name="step_bits" class="form-control form-control-sm" required min="1" max="32" value="24">
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small">Приоритет</label>
                            <input type="number" name="priority" class="form-control form-control-sm" value="100">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" name="enabled" value="1" id="range_enabled" checked>
                                <label class="form-check-label small" for="range_enabled">Активен</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="mt_pool_ranges-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: ТАБЛИЦА === -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">🗂️ Пулы адресов</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="mt_pool_ranges-filter" class="form-control form-control-sm"
                           style="width:250px;" placeholder="Поиск по имени или CIDR..."
                           oninput="applyRangeFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="mt_pool_ranges-table" class="table table-hover w-100"></table>
        </div>

    </div>
</div>

<script>
(function() {
    window.mt_pool_ranges = new CrudModule({
        prefix:   'mt_pool_ranges',
        tableUrl: '/mod/mt_pool_ranges/data.php',
        formUrl:  '/mod/mt_pool_ranges/dataAED.php',
        order:    [[7, 'asc']], // Сортировка по приоритету
        columns: [
            { data: 'id',           title: 'ID', visible: false },
            { data: 'group_name',   title: 'Группа', width: '150px', defaultContent: '<span class="text-muted">Сквозной</span>' },
            { data: 'purpose_name', title: 'Назначение', width: '150px' },
            { data: 'name',         title: 'Имя пула' },
            { data: 'cidr',         title: 'CIDR', width: '150px' },
            { data: 'step_bits',    title: 'Шаг (бит)', className: 'text-center', width: '90px' },
            { data: 'priority',     title: 'Приоритет', className: 'text-center', width: '90px' },
            { data: 'enabled',      title: 'Активен',  className: 'text-center', width: '80px',
                render: function(v) { return v == 1 ? '<i class="bi bi-check-lg text-success"></i>' : ''; }
            },
            { data: 'allocations_count', title: 'Выдано', className: 'text-center', width: '90px', orderable: false }
        ],
        formFields: ['id', 'group_id', 'purpose', 'name', 'cidr', 'kind', 'step_bits', 'priority', 'enabled']
    });
})();

function applyRangeFilter() {
    var mod = window.mt_pool_ranges;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('mt_pool_ranges-filter').value).draw();
}
</script>
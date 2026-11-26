<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// === Список устройств (hardware) для выпадающего списка ===
// Показываем модель + имя + IP, чтобы было понятно, что выбираешь
$hardware = [];
$r = $mysqli->query("SELECT h.id,
                            h.switch_name,
                            h.hardware_ip,
                            CONCAT(IFNULL(b.brand_name,''), ' ', IFNULL(m.model_name,'')) AS model_full
                     FROM hardware h
                     LEFT JOIN model      m ON m.id = h.id_model
                     LEFT JOIN brand      b ON b.id = m.id_brand
                     LEFT JOIN model_type t ON t.id = m.model_type_id
                     WHERE t.name = 'Роутер'
                     ORDER BY h.switch_name, h.hardware_ip, b.brand_name, m.model_name");
while ($row = $r->fetch_array(MYSQLI_ASSOC)) {

    // Сначала имя, потом IP, потом модель
    $parts = [];

    if ($row['switch_name'] !== '' && $row['switch_name'] !== null) {
        $parts[] = $row['switch_name'];
    }
    if ($row['hardware_ip'] !== '' && $row['hardware_ip'] !== null) {
        $parts[] = $row['hardware_ip'];
    }
    $model_full = trim($row['model_full']);
    if ($model_full !== '') {
        $parts[] = $model_full;
    }

    $label = implode(' — ', $parts);
    if ($label === '') {
        $label = '#' . $row['id'];   // запасной вариант, если всё пусто
    }

    $hardware[] = [
        'id'    => $row['id'],
        'label' => $label
    ];
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <!-- ЛЕВАЯ КОЛОНКА (ФОРМА) -->
        <div class="cmd-form">
            <div class="panel cmd-panel">
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <div class="fw-semibold">Редактирование</div>
                    <span id="ipam_vlans-mode" class="badge bg-secondary">Новая запись</span>
                </div>

                <form id="ipam_vlans-form" autocomplete="off"
                      data-can-add="<?=  $can_add  ? 1 : 0 ?>"
                      data-can-edit="<?= $can_edit ? 1 : 0 ?>"
                      data-can-del="<?=  $can_del  ? 1 : 0 ?>">
                    <input type="hidden" name="id">

                    <div class="mb-2">
                        <label class="form-label small">Устройство <span class="text-danger">*</span></label>
                        <select name="hw_id" class="form-select form-select-sm" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($hardware as $h): ?>
                                <option value="<?= (int)$h['id'] ?>"><?= htmlspecialchars($h['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">VLAN ID <span class="text-danger">*</span></label>
                        <input type="number" name="vlan_id" class="form-control form-control-sm"
                               min="1" max="4094" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Имя</label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               maxlength="64">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Описание</label>
                        <textarea name="description" class="form-control form-control-sm" rows="2"
                                  maxlength="255"></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-3 align-items-center">
                        <?= $form_buttons ?>
                    </div>
                </form>

                <div id="ipam_vlans-msg" class="mt-3 small"></div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА (ТАБЛИЦА) -->
        <div class="cmd-table">
            <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
                <div class="fw-semibold">🔀 VLAN'ы</div>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" id="ipam_vlans-filter" class="form-control form-control-sm"
                    style="width:250px;" placeholder="Поиск по VLAN..."
                    oninput="applyIpamVlansFilter()">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                        <i class="bi bi-arrow-clockwise"></i> Обновить
                    </button>
                </div>
            </div>

            <table id="ipam_vlans-table" class="table table-hover w-100"></table>
        </div>
    </div>
</div>

<script>
(function() {
    window.ipam_vlans = new CrudModule({
        prefix:   'ipam_vlans',
        tableUrl: '/mod/ipam_vlans/data.php',
        formUrl:  '/mod/ipam_vlans/dataAED.php',
        order:    [[1, 'asc'], [2, 'asc']],
        columns: [
            { data: 'id',              title: 'ID',         visible: false },
            { data: 'hw_label',        title: 'Устройство', visible: false },
            { data: 'vlan_id',         title: 'VLAN ID',    className: 'text-center', width: '90px' },
            { data: 'name',            title: 'Имя' },
            { data: 'description',     title: 'Описание' },
            { data: 'subnets_count',   title: 'Подсети',    className: 'text-center', width: '90px' },
            { data: 'created_at',      title: 'Создан',     width: '150px' },
            { data: 'updated_at',      title: 'Изменён',    width: '150px' }
        ],
        formFields: ['id', 'hw_id', 'vlan_id', 'name', 'description']
    });

    setTimeout(function() {
        var dt = ipam_vlans.table;
        
        if (dt) {
            if (dt.select && dt.select.style) {
                dt.select.style('single');
            }

            if (typeof dt.rowGroup === 'function') {
                dt.rowGroup().dataSrc('hw_label').enable();
                dt.draw(false);
            } else {
                var settings = dt.settings()[0];
                if (settings && !settings.rowGroup) {
                    try {
                        new DataTable.RowGroup(dt, { dataSrc: 'hw_label' });
                        dt.draw(false);
                    } catch(e) {}
                }
            }
        }
    }, 300);
})();
function applyIpamVlansFilter() {
    var mod = window.ipam_vlans;
    if (!mod || !mod.table) return;
    mod.table.search(document.getElementById('ipam_vlans-filter').value).draw();
}
</script>

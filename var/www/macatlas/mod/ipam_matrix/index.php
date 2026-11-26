<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к матрице VLAN</div>');
}

// ===================== Данные =====================

// VLAN из IPAM с привязкой к площадке (через оборудование) и подсетью.
// Если у VLAN несколько подсетей — берётся первая (с наименьшим префиксом).
$vlans = [];
$res = $mysqli->query(
    "SELECT v.id, v.vlan_id, v.name, v.description,
            COALESCE(l.new_name, '(без площадки)') AS loc,
            INET_NTOA(s.network) AS net, s.prefix_len, INET_NTOA(s.gateway) AS gw
       FROM ipam_vlans v
       JOIN hardware h ON h.id = v.hw_id
       LEFT JOIN locations l ON l.id = h.locations_id
       LEFT JOIN ipam_subnets s ON s.vlan_ref_id = v.id
      ORDER BY loc, v.vlan_id, s.prefix_len"
);
while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    if (!isset($vlans[$id])) {
        $vlans[$id] = [
            'id'      => $id,
            'vlan_id' => (int)$row['vlan_id'],
            'name'    => $row['name'],
            'desc'    => $row['description'] ?? '',
            'loc'     => $row['loc'],
            'subnet'  => $row['net'] ? ($row['net'] . '/' . $row['prefix_len']) : '',
            'gw'      => $row['gw'] ?? '',
        ];
    }
}
$res->close();
$vlans = array_values($vlans);

// Действующие правила: [src_id]['v{dst_id}'|'inet'] => action
$rules = [];
$r = $mysqli->query("SELECT src_vlan_id, dst_type, dst_vlan_id, action FROM ipam_matrix");
while ($row = $r->fetch_assoc()) {
    $key = $row['dst_type'] === 'internet' ? 'inet' : 'v' . (int)$row['dst_vlan_id'];
    $rules[(int)$row['src_vlan_id']][$key] = $row['action'];
}
$r->close();

// Группировка по площадкам (порядок — как в отсортированном списке)
$groups = [];
foreach ($vlans as $v) {
    $groups[$v['loc']][] = $v;
}

if (!$vlans) {
    exit('<div class="alert alert-info m-3">В IPAM пока нет ни одного VLAN — матрице нечего показать.</div>');
}

// Подписи состояний
$MX_CLS = ['accept' => 'mx-a', 'drop' => 'mx-d', 'none' => 'mx-n'];
$MX_CH  = ['accept' => '✓',    'drop' => '✕',   'none' => '—'];

// Лёгкие карты подписей для JS-тултипов (вместо title на каждой ячейке)
$jsRows = [];
$jsCols = ['inet' => 'Internet'];
foreach ($vlans as $v) {
    $label = "{$v['loc']} {$v['vlan_id']} {$v['name']}";
    $jsRows[$v['id']]      = $label;
    $jsCols['v' . $v['id']] = $label;
}

// Ячейка матрицы
function mxCell($srcId, $key, $dstId, $type, $col, $sep, $rules, $MX_CLS, $MX_CH) {
    $state = $rules[$srcId][$key] ?? 'none';
    echo '<td class="mx-c ' . $MX_CLS[$state] . ($sep ? ' mx-vs' : '') . '"'
       . ' data-src="' . $srcId . '" data-type="' . $type . '" data-dst="' . $dstId . '"'
       . ' data-col="' . $col . '"'
       . '>' . $MX_CH[$state] . '</td>';
}
?>

<div class="container-fluid py-2">
    <div class="panel mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="padding: 8px 12px;">
        <h5 class="mb-0 fw-bold">🧭 Матрица VLAN</h5>
        <div class="d-flex align-items-center gap-3 small text-muted">
            <span><span class="mx-lg mx-a">✓</span> Accept</span>
            <span><span class="mx-lg mx-d">✕</span> Drop</span>
            <span><span class="mx-lg mx-n">—</span> не задано</span>
            <?php if ($can_edit): ?>
                <span class="d-none d-xl-inline">· клик по ячейке переключает состояние</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel p-0 mx-wrap">
        <table class="mx" id="mx-table">
            <thead>
                <tr>
                    <th colspan="5" class="mx-l mx-corner"></th>
                    <?php $gcol = 0; $gfirst = true; ?>
                    <?php foreach ($groups as $loc => $items): ?>
                        <th colspan="<?= count($items) ?>" class="mx-grp<?= $gfirst ? '' : ' mx-vs' ?>"><?= htmlspecialchars($loc) ?></th>
                    <?php $gfirst = false; endforeach; ?>
                    <th rowspan="2" class="mx-col mx-inet mx-vs" data-col="<?= count($vlans) ?>" title="Доступ в Internet"><span class="mx-col-txt">Internet</span></th>
                </tr>
                <tr>
                    <th class="mx-l mx-c1">Площадка</th>
                    <th class="mx-l mx-c2">VLAN</th>
                    <th class="mx-l mx-c3">Подсеть</th>
                    <th class="mx-l mx-c4">Назначение</th>
                    <th class="mx-l mx-c5">Шлюз</th>
                    <?php $prevLoc = null; $col = 0; ?>
                    <?php foreach ($vlans as $dst): ?>
                        <th class="mx-col<?= ($prevLoc !== null && $dst['loc'] !== $prevLoc) ? ' mx-vs' : '' ?>"
                            data-col="<?= $col ?>"
                            title="<?= htmlspecialchars($dst['loc'] . ' · VLAN ' . $dst['vlan_id'] . ' ' . $dst['name'], ENT_QUOTES) ?>">
                            <span class="mx-col-txt mx-col-id"><?= $dst['vlan_id'] ?></span><span class="mx-col-txt mx-col-nm"><?= htmlspecialchars($dst['name']) ?></span>
                        </th>
                    <?php $prevLoc = $dst['loc']; $col++; endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $loc => $items): ?>
                    <?php $first = true; ?>
                    <?php foreach ($items as $src): ?>
                        <tr<?= $first ? ' class="mx-gs"' : '' ?>>
                            <?php if ($first): $first = false; ?>
                                <th rowspan="<?= count($items) ?>" class="mx-l mx-c1 mx-loc"><?= htmlspecialchars($loc) ?></th>
                            <?php endif; ?>
                            <td class="mx-l mx-c2"><?= $src['vlan_id'] ?></td>
                            <td class="mx-l mx-c3"><?= htmlspecialchars($src['subnet']) ?></td>
                            <td class="mx-l mx-c4" title="<?= htmlspecialchars($src['desc'], ENT_QUOTES) ?>"><?= htmlspecialchars($src['name']) ?></td>
                            <td class="mx-l mx-c5"><?= htmlspecialchars($src['gw']) ?></td>

                            <?php $prevLoc = null; $col = 0; ?>
                            <?php foreach ($vlans as $dst): ?>
                                <?php
                                $sep = ($prevLoc !== null && $dst['loc'] !== $prevLoc);
                                $prevLoc = $dst['loc'];
                                ?>
                                <?php if ($src['id'] === $dst['id']): ?>
                                    <td class="mx-c mx-self<?= $sep ? ' mx-vs' : '' ?>" data-col="<?= $col ?>"></td>
                                <?php else: ?>
                                    <?php mxCell($src['id'], 'v' . $dst['id'], $dst['id'], 'vlan', $col, $sep, $rules, $MX_CLS, $MX_CH); ?>
                                <?php endif; ?>
                            <?php $col++; endforeach; ?>

                            <?php mxCell($src['id'], 'inet', 0, 'internet', $col, true, $rules, $MX_CLS, $MX_CH); ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.MX_CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;
window.MX_ROWS = <?= json_encode($jsRows, JSON_UNESCAPED_UNICODE) ?>;
window.MX_COLS = <?= json_encode($jsCols, JSON_UNESCAPED_UNICODE) ?>;

(function () {
    var tbl = document.getElementById('mx-table');
    if (!tbl) return;

    var LABELS = { accept: 'Accept', drop: 'Drop', none: 'не задано' };
    var CLS    = { accept: 'mx-a',   drop: 'mx-d', none: 'mx-n' };
    var CH     = { accept: '✓',      drop: '✕',    none: '—' };

    var colCache = {};
    var hlCells = null, hlRow = null, hlTd = null;

    function stateOf(td) {
        return td.classList.contains('mx-a') ? 'accept'
             : td.classList.contains('mx-d') ? 'drop' : 'none';
    }

    function makeTitle(td) {
        var s = window.MX_ROWS[td.dataset.src] || ('#' + td.dataset.src);
        var d = window.MX_COLS[td.dataset.type === 'internet' ? 'inet' : 'v' + td.dataset.dst] || '';
        return s + ' → ' + d + ': ' + LABELS[stateOf(td)];
    }

    function setCell(td, st) {
        td.classList.remove('mx-a', 'mx-d', 'mx-n');
        td.classList.add(CLS[st]);
        td.textContent = CH[st];
        td.title = makeTitle(td);
    }

    function clearHl() {
        if (hlCells) {
            for (var i = 0; i < hlCells.length; i++) hlCells[i].classList.remove('mx-hl');
            hlCells = null;
        }
        if (hlRow) { hlRow.classList.remove('mx-hlr'); hlRow = null; }
        if (hlTd)  { hlTd.classList.remove('mx-cur');  hlTd = null; }
    }

    // Перекрестная подсветка + тултип на лету
    tbl.addEventListener('mouseover', function (e) {
        var td = e.target.closest('td[data-src]');
        clearHl();
        if (!td || !tbl.contains(td)) return;

        var col = td.dataset.col;
        var cells = colCache[col];
        if (!cells) {
            cells = colCache[col] = tbl.querySelectorAll('[data-col="' + col + '"]');
        }
        for (var i = 0; i < cells.length; i++) cells[i].classList.add('mx-hl');
        hlCells = cells;

        hlRow = td.parentElement;
        hlRow.classList.add('mx-hlr');

        hlTd = td;
        hlTd.classList.add('mx-cur');
        td.title = makeTitle(td);
    });

    tbl.addEventListener('mouseleave', clearHl);

    // Клик-цикл: — → Accept → Drop → —
    tbl.addEventListener('click', function (e) {
        var td = e.target.closest('td[data-src]');
        if (!td || !tbl.contains(td)) return;
        if (!window.MX_CAN_EDIT) return;
        if (td.dataset.busy) return;
        td.dataset.busy = '1';

        fetch('/mod/ipam_matrix/matrix_toggle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                src:      td.dataset.src,
                dst_type: td.dataset.type,
                dst:      td.dataset.dst
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            delete td.dataset.busy;
            if (j.ok) {
                setCell(td, j.state);
            } else {
                alert(j.error || 'Ошибка сохранения');
            }
        })
        .catch(function () {
            delete td.dataset.busy;
            alert('Ошибка сети');
        });
    });
})();
</script>

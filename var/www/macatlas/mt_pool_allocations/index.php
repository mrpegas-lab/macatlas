<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}
?>

<div class="container-fluid py-3">
    <div class="cmd-layout" style="display: block;"> <!-- Убираем сетку в две колонки -->
        
        <div class="panel mb-2 d-flex justify-content-between align-items-center" style="padding: 8px 12px;">
            <div class="fw-semibold">🔒 Выданные адреса (Allocations)</div>
            <div class="d-flex align-items-center gap-2">
                <input type="text" id="alloc-filter" class="form-control form-control-sm"
                       style="width:300px;" placeholder="Поиск по IP, объекту или пулу..."
                       oninput="applyAllocFilter()">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-refresh">
                    <i class="bi bi-arrow-clockwise"></i> Обновить
                </button>
            </div>
        </div>

        <table id="mt_pool_allocations-table" class="table table-hover table-bordered w-100"></table>
    </div>
</div>

<script>
(function() {
    // Инициализация DataTables напрямую (без CrudModule, так как нет формы)
    window.mt_pool_allocations = new DataTable('#mt_pool_allocations-table', {
        ajax: {
            url: '/mod/mt_pool_allocations/data.php',
            type: 'POST'
        },
        processing: true,
        serverSide: true,
        order: [[5, 'desc']], // Сортировка по дате
        columns: [
            { data: 'id',           title: 'ID', visible: false },
            { data: 'network_ip',   title: 'IP / Сеть', width: '150px' },
            { data: 'block_bits',   title: 'Маска', className: 'text-center', width: '70px' },
            { data: 'purpose_name', title: 'Назначение', width: '120px' },
            { data: 'pool_name',    title: 'Пул' },
            { data: 'site_name',    title: 'Объект', width: '250px',
              render: function(data, type, row) {
                  if (!data) return '<span class="text-danger">Не найден (ID: ' + row.site_id + ')</span>';
                  return row.site_code + ' — ' + data;
              }
            },
            { data: 'allocated_at', title: 'Дата выдачи', width: '150px' },
                        {
                data: null,
                title: 'Действие',
                className: 'text-center',
                width: '100px',
                orderable: false,
                render: function(data, type, row) {
                    // Если объект существует (site_exists > 0), запрещаем удаление
                    if (row.site_exists > 0) {
                        return '<button class="btn btn-sm btn-outline-secondary" disabled title="Сначала удалите объект из Конфигуратора"><i class="bi bi-lock"></i></button>';
                    }
                    // Если объекта нет, разрешаем освободить «висячий» IP
                    return '<button class="btn btn-sm btn-outline-danger btn-free-ip" data-id="' + row.id + '" title="Освободить IP"><i class="bi bi-trash"></i></button>';
                }
            }
        ]
    });

    // Обработка клика по кнопке "Освободить"
    document.getElementById('mt_pool_allocations-table').addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-free-ip');
        if (!btn) return;

        var id = btn.getAttribute('data-id');

        if (confirm('Вы уверены, что хотите освободить этот IP?\nОбъект потеряет привязку к этому адресу.')) {
            var formData = new URLSearchParams();
            formData.append('oper', 'del');
            formData.append('id', id);

            fetch('/mod/mt_pool_allocations/dataAED.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.text())
            .then(res => {
                if (res.trim() !== '') {
                    alert('Ошибка: ' + res); // Если сервер вернул текст, значит ошибка
                } else {
                    window.mt_pool_allocations.ajax.reload(null, false);
                }
            })
            .catch(err => alert('Ошибка сети: ' + err.message));
        }
    });
})();

function applyAllocFilter() {
    var mod = window.mt_pool_allocations;
    if (!mod) return;
    mod.search(document.getElementById('alloc-filter').value).draw();
}
</script>
<?php
include_once("../../loadinc.php");

if (!$can_view) {
    exit('<div class="alert alert-danger m-3">Нет доступа к разделу</div>');
}

// Структура справки: ключ файла => (группа, название)
$HELP = [
    'Общее' => [
        'overview'  => 'Обзор системы',
        'scenarios' => 'Типовые сценарии',
    ],
    'Модули' => [
        'mt_config' => 'Конфигуратор объектов',
        'mt_run'    => 'Массовые команды',
        'mt_update' => 'Обновление устройств',
        'backups'   => 'Бэкапы',
        'ipam'      => 'IPAM и матрица VLAN',
        'hardware'  => 'Оборудование и поиск',
        'mt_maps'   => 'Карта локаций',
        'maps_net'  => 'Карта сети (топология)',
        'health'    => 'Здоровье сети',
        'acl'       => 'Права доступа',
    ],
    'Администрирование' => [
        'install' => 'Установка и конфигурация',
        'cron'    => 'Фоновые задачи (cron)',
        'license' => 'Лицензия',
        'faq'     => 'Решение проблем',
    ],
];
?>

<div class="container-fluid py-3">
    <div class="cmd-layout">

        <!-- === ЛЕВАЯ КОЛОНКА: оглавление === -->
        <div class="cmd-form">
            <div class="panel cmd-panel" style="padding: 10px;">
                <div class="fw-semibold mb-2">📖 Справка</div>
                <?php foreach ($HELP as $group => $items): ?>
                    <div class="small text-muted mt-2 mb-1 text-uppercase"><?= htmlspecialchars($group) ?></div>
                    <?php foreach ($items as $key => $title): ?>
                        <div class="help-toc-item" data-page="<?= $key ?>"><?= htmlspecialchars($title) ?></div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- === ПРАВАЯ КОЛОНКА: содержимое === -->
        <div class="cmd-table">
            <div class="panel p-3" id="help-content" style="min-height: 300px;">
                <div class="text-muted">Выберите раздел справки слева</div>
            </div>
        </div>

    </div>
</div>

<script>
(function() {
    var content = document.getElementById('help-content');

    function loadPage(key) {
        document.querySelectorAll('.help-toc-item').forEach(function (el) {
            el.classList.toggle('help-toc-active', el.dataset.page === key);
        });
        content.innerHTML = '<div class="text-muted">Загрузка...</div>';

        fetch('/mod/help/pages/' + encodeURIComponent(key) + '.html', { cache: 'no-store' })
            .then(function (r) {
                if (!r.ok) throw new Error('Раздел не найден');
                return r.text();
            })
            .then(function (html) { content.innerHTML = html; })
            .catch(function (e) {
                content.innerHTML = '<div class="text-danger">' + e.message + '</div>';
            });
    }

    document.querySelectorAll('.help-toc-item').forEach(function (el) {
        el.addEventListener('click', function () { loadPage(el.dataset.page); });
    });

    // По умолчанию открываем обзор
    loadPage('overview');
})();
</script>

<?php
include_once("loadinc.php");
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <title>MacAtlas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta charset="utf-8"/>
    <meta content="no-cache" http-equiv="Cache-Control"/>
    <link rel="stylesheet" type="text/css" href="/plugins/bootstrap-5.3.8/css/bootstrap.min.css"/>
    <link rel="stylesheet" type="text/css" href="/plugins/bootstrap-icons-1.7.1/bootstrap-icons.css"/>
    <link rel="stylesheet" type="text/css" href="/plugins/DataTables/datatables.min.css">
    <link rel="stylesheet" type="text/css" href="/plugins/tomselect/tom-select.bootstrap5.min.css">
    <link rel="stylesheet" type="text/css" href="/plugins/leaflet/leaflet.css" />
    <link rel="stylesheet" type="text/css" href="/plugins/style.css?v=<?= filemtime(__DIR__ . '/plugins/style.css') ?>" />
    <link rel="stylesheet" type="text/css" href="/plugins/maps.css?v=<?= filemtime(__DIR__ . '/plugins/maps.css') ?>" />
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="/">MacAtlas</a>
        <?php
        // Лицензия — только информационный бейдж (2026-11-24), без блокировок
        ?>
        <?php if (!$appst['valid']): ?>
            <span class="badge bg-warning text-dark ms-2 menuurl" data-url="about" data-name="О программе"
                  style="cursor:pointer;" title="Лицензия не оформлена — подробности на странице «О программе»">не лицензировано</span>
        <?php elseif ($appst['days_left'] !== null && $appst['days_left'] <= 30): ?>
            <span class="badge bg-info text-dark ms-2" title="Лицензия действует до <?= htmlspecialchars($appst['expires_at']) ?>">лицензия: <?= $appst['days_left'] ?> дн.</span>
        <?php endif; ?>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarCollapse">
            <!-- Основное меню слева -->
            <div class="navbar-nav me-3 border-end pe-3" style="border-color: rgba(255,255,255,0.3) !important;">
                <?php
                $welcome_groups = []; // Теперь это массив групп, а не плоский список
                $icon_pool = ['bi-folder2', 'bi-table', 'bi-clipboard-data', 'bi-diagram-3',
                              'bi-geo-alt', 'bi-people', 'bi-box-seam', 'bi-bar-chart',
                              'bi-gear', 'bi-files', 'bi-map', 'bi-list-check'];
                $icon_i = 0;

                $role_id = $_SESSION['acl_groups_id'] ?? 0;
                
                $SQL_groups = "SELECT DISTINCT g.id, g.title
                               FROM acl_menu_groups g
                               INNER JOIN acl_resources r ON r.group_id = g.id
                               INNER JOIN acl_permissions p ON p.resource_id = r.id
                               WHERE p.role_id = ? AND p.can_view = 1
                               ORDER BY g.sort";
                
                $stmt_g = $mysqli->prepare($SQL_groups);
                $stmt_g->bind_param('i', $role_id);
                $stmt_g->execute();
                $result_groups = $stmt_g->get_result();

                while ($row_groups = $result_groups->fetch_assoc()) {
                    // Выводим меню в навбар
                    echo "
                    <div class='nav-item dropdown'>
                        <a class='nav-link dropdown-toggle' href='#' role='button' data-bs-toggle='dropdown' aria-expanded='false'>{$row_groups['title']}</a>
                        <ul class='dropdown-menu'>";

                    // Массив для элементов текущей группы на welcome-экране
                    $group_items = [];

                    $SQL_tables = "SELECT r.id, r.name, r.code, r.sort
                                   FROM acl_resources r
                                   INNER JOIN acl_permissions p ON p.resource_id = r.id
                                   WHERE r.group_id = ? AND p.role_id = ? AND p.can_view = 1
                                   ORDER BY r.sort, r.name";
                    
                    $stmt_t = $mysqli->prepare($SQL_tables);
                    $stmt_t->bind_param('ii', $row_groups['id'], $role_id);
                    $stmt_t->execute();
                    $result_tables = $stmt_t->get_result();

                    while ($row_tables = $result_tables->fetch_assoc()) {
                        $badge = "<span id='m_{$row_tables['code']}_span' class='badge bg-secondary ms-1'></span>";
                        
                        echo "<li><a class='dropdown-item menuurl' data-url='{$row_tables['code']}' data-name='{$row_tables['name']}'>{$row_tables['name']} {$badge}</a></li>";
                        
                        // Собираем для welcome
                        $group_items[] = [
                            'url' => $row_tables['code'], 
                            'name' => $row_tables['name'], 
                            'icon' => $icon_pool[$icon_i % count($icon_pool)]
                        ];
                        $icon_i++;
                    }
                    echo "</ul></div>";

                    // Сохраняем в общий массив, если в группе есть модули
                    if (!empty($group_items)) {
                        $welcome_groups[] = [
                            'title' => $row_groups['title'],
                            'items' => $group_items
                        ];
                    }
                }
                ?>
            </div>

            <!-- Правая часть -->
                   <div class="navbar-nav ms-auto align-items-center">
                <!-- Кнопка "Выход" -->
                <div class="nav-item">
                    <button id="m_exit" type="button" class="btn btn-outline-light btn-sm">
                        Выход
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid pt-3">
    <div id="welcome-menu-wrap">
        <?php foreach ($welcome_groups as $group): ?>
            <!-- Вернули отличные белые заголовки -->
            <h5 class="mb-3 mt-4 text-light" style="border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 8px;">
                <?= htmlspecialchars($group['title']) ?>
            </h5>

            <!-- Карточки модулей (уменьшенные иконки) -->
            <div class="row row-cols-3 row-cols-sm-4 row-cols-md-5 row-cols-lg-6 row-cols-xl-8 g-2 mb-4">
                <?php foreach ($group['items'] as $item): ?>
                    <div class="col">
                        <a href="#" class="welcome-card menuurl text-decoration-none" data-url="<?php echo htmlspecialchars($item['url']); ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>">
                            <div class="icon-wrap mb-1">
                                <!-- Уменьшили только иконку до 1.3rem -->
                                <i class="bi <?php echo $item['icon']; ?>" style="font-size: 1.3rem;"></i>
                            </div>
                            <div class="welcome-title text-center text-light" style="font-size: 0.8rem; line-height: 1.2;">
                                <?php echo htmlspecialchars($item['name']); ?>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="divfr" style="display:none;"></div>
</div>

<script type="text/javascript" src="/plugins/bootstrap-5.3.8/js/bootstrap.bundle.min.js"></script>
<script type="text/javascript" src="/plugins/DataTables/datatables.min.js"></script>
<script type="text/javascript" src="/plugins/tomselect/tom-select.complete.min.js"></script>
<script type="text/javascript" src="/plugins/crud.js"></script>
<script type="text/javascript" src="/plugins/treeview.js"></script>
<script type="text/javascript" src="/plugins/leaflet/leaflet.js"></script>
<script type="text/javascript" src="/plugins/vis-network/vis-network.min.js"></script>

<script>
    // Глобальные настройки для ВСЕХ таблиц DataTables
    Object.assign(DataTable.defaults, {
        language: {
            url: '/plugins/DataTables/ru.json'
        },
        pageLength: 500,
        lengthMenu: [50, 100, 250, 500, 1000, 2000],
        autoWidth: false,
        
        // Выделение строк
        select: {
            style: 'single',
            selector: 'tr'
        },
        layout: {
            topStart: null,          
            topEnd: 'info',            
            bottomStart: 'pageLength', // Селект "Показать N" слева
            bottom2Start: null,       // Текст "Показано..." по центру
            bottomEnd: 'paging',       // Пагинация справа
            bottom2End: null
        },
        
        stripeClasses: [], 
        
        // Серверная обработка (если модуль передает ajax)
        serverSide: true,
        processing: true,
        
        
        // Скролл
        scrollY: 'calc(100vh - 290px)', // Лучше использовать calc() вместо JS
        scrollCollapse: true,
        scrollX: false
    });

    // Функция для безопасного выполнения скриптов, загруженных через HTML
    function executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
            newScript.appendChild(document.createTextNode(oldScript.innerHTML));
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    // УНИВЕРСАЛЬНАЯ подгонка левых колонок всех модулей под высоту экрана:
    // колонка (.cmd-form) дотягивается до низа, её div'ы верхнего уровня
    // делят высоту поровну (правила .col-fit — в plugins/style.css)
    function fitCmdForms() {
        document.querySelectorAll('#divfr .cmd-form').forEach(function (f) {
            f.classList.add('col-fit');
            f.style.height = (window.innerHeight - f.getBoundingClientRect().top - 12) + 'px';
        });
    }
    window.addEventListener('resize', fitCmdForms);

    function getParam(name) {
        return new URLSearchParams(window.location.search).get(name);
    }

    function showContent() {
        document.getElementById('welcome-menu-wrap').style.display = 'none';
        document.getElementById('divfr').style.display = '';
    }

    function showWelcome() {
        const divfr = document.getElementById('divfr');
        divfr.style.display = 'none';
        divfr.innerHTML = '';
        document.getElementById('welcome-menu-wrap').style.display = '';
    }

    function loadTable(table, name) {
        // заголовок вкладки — по текущему разделу меню
        document.title = name ? ('MacAtlas — ' + name) : 'MacAtlas';
        const urlnew = 'http://' + window.location.host + '/mod/' + table + '/';

        fetch(urlnew, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            cache: 'no-store',
            body: 'name=' + encodeURIComponent(name || '') + '&table=' + encodeURIComponent(table)
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function (html) {
            const divfr = document.getElementById('divfr');
            divfr.innerHTML = html;
            
            // ВОТ ГЛАВНАЯ МАГИЯ: запускаем скрипты, которые пришли в HTML
            showContent();

            executeScripts(divfr);

            // Подгоняем левую колонку модуля под высоту экрана
            fitCmdForms();
        })
        .catch(function () {
            document.getElementById('divfr').innerHTML =
                '<div class="alert alert-danger m-3">' +
                '  <i class="bi bi-exclamation-triangle-fill me-2"></i>' +
                '  Пункт меню «' + (name || table) + '» не найден или недоступен (раздел «' + table + '»).' +
                '</div>';
            showContent();
        });
    }

    function furl() {
        // Путь модуля: /mod/<код>/ (прямой) или /<код>/ (красивый, nginx переписывает на /mod/)
        const parts = location.pathname.split('/');
        const table = parts[1] === 'mod' ? parts[2] : parts[1];
        const name = getParam('name');
        if (table) {
            loadTable(table, name);
        } else {
            document.title = 'MacAtlas';
            showWelcome();
        }
    }

    window.addEventListener('popstate', function () {
        furl();
    });

    document.addEventListener('click', function (e) {
        const link = e.target.closest('.menuurl');
        if (!link) return;
        e.preventDefault();

        const table = link.dataset.url;
        const name  = link.dataset.name;
        if (!table) return;

        // Красивый адрес — /<код>/; nginx переписывает его на /mod/<код>/ (как раньше .htaccess)
        const urlnew = '/' + table + '/?name=' + encodeURIComponent(name || '') + '&table=' + encodeURIComponent(table);
        history.pushState(null, null, urlnew);
        loadTable(table, name);
    });

    document.addEventListener('DOMContentLoaded', function () {
        furl();

        document.getElementById('m_exit').addEventListener('click', function () {
            fetch('/exit.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store',
                body: 'exit=1'
            })
            .then(function () { window.location.href = '/login.php'; })
            .catch(function () { window.location.href = '/login.php'; });
        });
    });

// ============================================================
// 1. ГЛОБАЛЬНЫЙ ПЕРЕХВАТ FETCH (чтобы добавлять X-Requested-With и ловить 401)
// ============================================================
(function() {
    var originalFetch = window.fetch;
    window.fetch = function() {
        var args = Array.prototype.slice.call(arguments);
        
        // Добавляем заголовок X-Requested-With для AJAX
        if (args.length > 1 && typeof args[1] === 'object') {
            if (!args[1].headers) args[1].headers = {};
            if (args[1].headers instanceof Headers) {
                args[1].headers.set('X-Requested-With', 'XMLHttpRequest');
            } else {
                args[1].headers['X-Requested-With'] = 'XMLHttpRequest';
            }
        } else {
            args[1] = args[1] || {};
            args[1].headers = { 'X-Requested-With': 'XMLHttpRequest' };
        }
        
        return originalFetch.apply(window, args).then(function(response) {
            if (response.status === 401) {
                // Сессия истекла — уводим на логин
                window.location.href = '/login.php?expired=1';
                throw new Error('Session expired');
            }
            return response;
        });
    };
})();

// ============================================================
// ТАЙМЕР БЕЗДЕЙСТВИЯ: settings.session_idle_hours часов без активности → выход
// ============================================================
var idleTimeout = <?php
    $idleHrs = 3;
    $r = $mysqli->query("SELECT value FROM settings WHERE `key` = 'session_idle_hours'");
    if ($r && ($row = $r->fetch_assoc())) $idleHrs = max(1, (int)$row['value']);
    echo $idleHrs * 3600;
?> * 1000;
var idleTimer;

function resetIdleTimer() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(function () {
        window.location.href = '/exit.php';
    }, idleTimeout);
}

['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function(event) {
    document.addEventListener(event, resetIdleTimer, { passive: true });
});

resetIdleTimer();
    
</script>
</html>
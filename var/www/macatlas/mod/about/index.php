<?php
// Страница вызывается через AJAX из корневого index.php.
// loadinc.php здесь не подключаем (страница должна работать и при проблемах с БД),
// но авторизацию проверяем: без сессии — 403 (аудит 2026-11-23).
require_once(__DIR__ . '/../../core/session_boot.php');
if (empty($_SESSION['auth'])) {
    http_response_code(403);
    exit('Нет доступа');
}
$authorName  = 'Покровский Евгений Александрович';
$authorEmail = 'mr.pegas2010@yandex.ru';

// Конфиг вынесен из webroot (/etc/macatlas/config.json)
$config = json_decode(file_get_contents(getenv('MACATLAS_CONFIG') ?: '/etc/macatlas/config.json'), true);

// Версия продукта — единый источник: секция app в /etc/macatlas/config.json
$appVersion = $config['app']['version'] ?? '1.4.0';

// Статус лицензии
require_once(__DIR__ . '/../../core/functions.php');
$license = sys_state();
?>

<div class="container-fluid py-3">
    <div class="panel p-4">

        <!-- Шапка -->
        <div class="d-flex align-items-center mb-4">
            <i class="bi bi-hdd-network-fill text-primary" style="font-size:2.75rem; margin-right:1rem;"></i>
            <div>
                <h3 class="mb-0">MacAtlas</h3>
                <div class="text-muted small">
                    Управление сетевой инфраструктурой и IP-пространством
                </div>
            </div>
        </div>

        <div class="row g-4">

            <!-- О программе -->
            <div class="col-md-12">
                <h6 class="text-uppercase text-muted small">О программе</h6>
                <p class="small mb-0">
                    <strong>MacAtlas</strong> — платформа для комплексного управления распределённой
                    сетевой инфраструктурой. Объединяет инвентаризацию коммутаторов, поиск подключённых
                    устройств, централизованное управление парком MikroTik по объектам и полноценную
                    систему IPAM. Главная ценность системы — <strong>массовые операции с контролем
                    рисков</strong>: задачи, которые вручную занимали недели (смена конфигураций,
                    обновление прошивок на сотнях устройств), выполняются за часы — с тестовым режимом,
                    ограничением размера партии и полным аудитом каждого запуска.
                </p>
            </div>

            <!-- Ключевые возможности -->
            <div class="col-md-12">
                <h6 class="text-uppercase text-muted small mt-2">Ключевые возможности</h6>

                <div class="row small g-3">
                    <!-- Массовые операции -->
                    <div class="col-md-6">
                        <div class="p-3 border rounded h-100">
                            <div class="fw-semibold mb-2">
                                <i class="bi bi-lightning-charge text-primary"></i> Массовые операции
                            </div>
                            <ul class="mb-0 ps-3">
                                <li>Выполнение команд на парке роутеров MikroTik — до 20 устройств за проход.</li>
                                <li>Тестовый режим (dry-run): проверка без реальных изменений.</li>
                                <li>Слоты заходов: до 5 параллельных сценариев с отметками выполнения.</li>
                                <li>Массовое обновление ROS и Firmware с контролем перезагрузки.</li>
                                <li>История запусков: кто, когда, что и с каким результатом выполнил.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Салоны и MikroTik -->
                    <div class="col-md-6">
                        <div class="p-3 border rounded h-100">
                            <div class="fw-semibold mb-2">
                                <i class="bi bi-shop text-primary"></i> Объекты и MikroTik
                            </div>
                            <ul class="mb-0 ps-3">
                                <li>Управление распределённой сетью салонов и точек продаж.</li>
                                <li>Работа по шаблонам: генерация готовой конфигурации MikroTik
                                    из шаблона с автоматической подстановкой данных объекта.</li>
                                <li>Автоматическая генерация свободных IP-адресов из пула.</li>
                                <li>Сбор данных с роутеров: модели, IP, ресурсы, статусы.</li>
                                <li>Географическая карта объектов и статистика парка устройств.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Коммутаторы -->
                    <div class="col-md-6">
                        <div class="p-3 border rounded h-100">
                            <div class="fw-semibold mb-2">
                                <i class="bi bi-diagram-3 text-primary"></i> Коммутаторы и порты
                            </div>
                            <ul class="mb-0 ps-3">
                                <li>Поддержка MikroTik, Ubiquiti (UBNT), TP-Link.</li>
                                <li>Сбор MAC-адресов со всех портов коммутаторов.</li>
                                <li>Поиск «что где подключено» по MAC, порту, устройству.</li>
                                <li>Привязка сотрудников к устройствам — поиск по людям и рабочим местам.</li>
                                <li>Инвентаризация моделей, серийных номеров, IP-адресов.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- IPAM -->
                    <div class="col-md-6">
                        <div class="p-3 border rounded h-100">
                            <div class="fw-semibold mb-2">
                                <i class="bi bi-hdd-network text-primary"></i> IPAM
                            </div>
                            <ul class="mb-0 ps-3">
                                <li>Единый реестр IP-адресов офиса и всех объектов.</li>
                                <li>Учёт занятости и назначения адресов за устройствами.</li>
                                <li>Управление VLAN, подсетями и пулами адресов.</li>
                                <li>Работа через справочники: устройства, локации, ответственные.</li>
                                <li>Быстрый поиск свободных и занятых адресов.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Резервное копирование -->
                    <div class="col-md-6">
                        <div class="p-3 border rounded h-100">
                            <div class="fw-semibold mb-2">
                                <i class="bi bi-cloud-arrow-down text-primary"></i> Резервное копирование
                            </div>
                            <ul class="mb-0 ps-3">
                                <li>Ежедневное снятие резервных копий со всех MikroTik.</li>
                                <li>Автоматический сбор бэкапов со всего парка (MikroTik, Ubiquiti, TP-Link).</li>
                                <li>Хранение копий в бинарном формате и в виде <code>.rsc</code>.</li>
                                <li>Просмотр и сравнение (diff) версий конфигураций в веб-интерфейсе.</li>
                                <li>Восстановление конфигурации при сбоях и замене оборудования.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Здоровье сети -->
                    <div class="col-md-6">
                        <div class="p-3 border rounded h-100">
                            <div class="fw-semibold mb-2">
                                <i class="bi bi-heart-pulse text-primary"></i> Здоровье сети
                            </div>
                            <ul class="mb-0 ps-3">
                                <li>Сводная оценка сети 0–100: доступность, бэкапы, прошивки, инциденты.</li>
                                <li>«Красная зона»: серьёзная проблема ограничивает оценку, пока не устранена.</li>
                                <li>Контроль бэкапов по локациям со списком проблемных устройств.</li>
                                <li>Актуальность прошивок по производителям со списком отстающих.</li>
                                <li>Журнал инцидентов с причинами: организационные простои не снижают оценку.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Доступ и безопасность -->
                    <div class="col-md-6">
                        <div class="p-3 border rounded h-100">
                            <div class="fw-semibold mb-2">
                                <i class="bi bi-shield-lock text-primary"></i> Доступ и безопасность
                            </div>
                            <ul class="mb-0 ps-3">
                                <li>Локальная авторизация и вход через LDAP (доменные учётные записи).</li>
                                <li>Ролевая модель: гибкая матрица прав «роль × раздел».</li>
                                <li>Учётные данные оборудования — в едином защищённом справочнике.</li>
                                <li>Журналирование всех массовых операций для аудита.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- В планах -->
            <div class="col-md-12">
                <h6 class="text-uppercase text-muted small mt-2">В планах развития</h6>
                <ul class="small mb-0">
                    <li>Расширение списка поддерживаемых моделей коммутаторов (Cisco и другие вендоры).</li>
                    <li>Расширение возможностей IPAM и отчётности.</li>
                </ul>
            </div>

            <!-- Автор + Технологии -->
            <div class="col-md-6 mt-3">
                <h6 class="text-uppercase text-muted small">Автор</h6>
                <ul class="list-unstyled small mb-0">
                    <li class="mb-1"><i class="bi bi-person"></i> <?= htmlspecialchars($authorName) ?></li>
                    <li><i class="bi bi-envelope"></i> <a href="mailto:<?= htmlspecialchars($authorEmail) ?>"><?= htmlspecialchars($authorEmail) ?></a></li>
                </ul>
            </div>

            <div class="col-md-6 mt-3">
                <h6 class="text-uppercase text-muted small">Технологии</h6>
                <ul class="small mb-0">
                    <li>Backend: PHP, MySQL / MariaDB</li>
                    <li>Frontend: Bootstrap 5, DataTables, Leaflet (карты)</li>
                    <li>Сбор данных: Python-коллектор, MikroTik REST API</li>
                    <li>Автоматизация: Python-сборщики, планировщик задач в вебе</li>
                    <li>Доступ: локальная авторизация + LDAP, ACL</li>
                </ul>
            </div>

            <!-- Версия -->
            <div class="col-md-12 mt-3">
                <h6 class="text-uppercase text-muted small">Версия и окружение</h6>
                <table class="table table-sm small mb-0" style="max-width: 500px;">
                    <tr><td class="text-muted" style="width: 150px;">Версия</td><td><?= htmlspecialchars($appVersion) ?></td></tr>
                    <tr><td class="text-muted">PHP</td><td><?= PHP_VERSION ?></td></tr>
                </table>
            </div>

            <!-- Лицензия -->
            <div class="col-md-12 mt-3">
                <h6 class="text-uppercase text-muted small">Лицензия</h6>
                <?php if ($license['valid']): ?>
                    <table class="table table-sm small mb-0" style="max-width: 500px;">
                        <tr><td class="text-muted" style="width: 150px;">Статус</td><td><span class="badge bg-success">Активна</span></td></tr>
                        <tr><td class="text-muted">Выдана</td><td><?= htmlspecialchars($license['company']) ?></td></tr>
                        <tr>
                            <td class="text-muted">Действует</td>
                            <td>
                                <?= $license['expires_at'] ? ('до ' . htmlspecialchars($license['expires_at'])) : 'бессрочно' ?>
                                <?= $license['days_left'] !== null ? (' <span class="text-muted">(осталось ' . $license['days_left'] . ' дн.)</span>') : '' ?>
                            </td>
                        </tr>
                    </table>
                <?php else: ?>
                    <div class="alert alert-warning small mb-0" style="max-width: 500px;">
                        <b>Лицензия не оформлена.</b><br>
                        Частные лица могут пользоваться программой бесплатно
                        в некоммерческих целях. Организациям требуется платная
                        лицензия — для оформления обратитесь к поставщику:
                        <a href="mailto:<?= htmlspecialchars($authorEmail) ?>"><?= htmlspecialchars($authorEmail) ?></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <hr class="my-4">
        <div class="small text-muted">
            © <?= date('Y') ?> MacAtlas. Автор: Покровский Е.А.
        </div>
    </div>
</div>

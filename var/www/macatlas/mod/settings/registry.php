<?php
// Реестр настроек модуля «Настройки системы».
// Используется и index.php (отрисовка), и dataAED.php (сохранение).
// Чтобы добавить новую настройку — добавьте запись сюда (и ключ в settings).

// type: bool | text | number | select_roles
$SECTIONS = [
    'system' => [
        'title' => 'Система',
        'icon'  => 'bi-gear',
        'keys'  => [
            'session_idle_hours' => [
                'type'  => 'number', 'min' => 1, 'max' => 720, 'default' => '3',
                'label' => 'Автовыход по неактивности, часов',
                'hint'  => 'Если пользователь ничего не делает это время — сессия завершается и выкидывает на логин.',
            ],
        ],
    ],
    'ldap' => [
        'title' => 'Доменная авторизация (LDAP)',
        'icon'  => 'bi-diagram-3',
        'keys'  => [
            'ldap_enabled' => [
                'type'  => 'bool',
                'label' => 'Доменная авторизация (LDAP)',
                'hint'  => 'Пользователи с типом «доменная» входят через LDAP; неизвестные доменные пользователи создаются автоматически с ролью ниже. Локальные учётные записи работают всегда.',
            ],
            'ldap_domain' => [
                'type'  => 'text', 'half' => true,
                'label' => 'Домен (суффикс логина)',
                'hint'  => 'К логину без «@» добавляется @домен при подключении к контроллеру.',
                'placeholder' => 'example.local',
            ],
            'ldap_host' => [
                'type'  => 'text', 'half' => true,
                'label' => 'Контроллер домена (host/IP)',
                'placeholder' => 'dc.example.local',
            ],
            'ldap_port' => [
                'type'  => 'number', 'min' => 1, 'max' => 65535, 'default' => '389',
                'half'  => true,
                'label' => 'Порт LDAP',
            ],
            'ldap_tls' => [
                'type'  => 'choice',
                'options' => [
                    '0' => 'Без шифрования',
                    '1' => 'StartTLS (порт 389)',
                    '2' => 'LDAPS (порт 636)',
                ],
                'default' => '0',
                'half'  => true,
                'label' => 'Шифрование LDAP',
                'hint'  => 'Без шифрования доменный пароль идёт открытым текстом до контроллера. Сертификат КД не проверяется (обычно самоподписанный) — канал всё равно шифруется. Если включено, но TLS не поднялся — вход не выполняется.',
            ],
            'ldap_default_role_id' => [
                'type'  => 'select_roles', 'half' => true,
                'label' => 'Роль для новых доменных пользователей',
            ],
        ],
    ],
    'monitoring' => [
        'title' => 'Мониторинг доступности',
        'icon'  => 'bi-activity',
        'keys'  => [
            'ping_log_days' => [
                'type'  => 'number', 'min' => 1, 'max' => 90, 'default' => '7',
                'label' => 'Детальный лог опросов, дней',
                'hint'  => 'Поминутные результаты опросов (таблица hw_ping_log). Используются для аптайма за 24 ч в «Здоровье сети». Каждый день хранения ≈ 300–400 тыс. строк (~50 МБ).',
            ],
            'ping_events_days' => [
                'type'  => 'number', 'min' => 0, 'max' => 3650, 'default' => '365',
                'label' => 'История падений (события), дней',
                'hint'  => 'Только моменты смены состояния up/down (таблица hw_state_events) — занимает в сотни раз меньше места, позволяет считать аптайм за долгие периоды. 0 = хранить вечно.',
            ],
        ],
    ],
    // Порядок секций определяет раскладку masonry: маленькая «maps» между
    // большими ldap/health выравнивает колонки (2026-11-25)
    'maps' => [
        'title' => 'Карты сети',
        'icon'  => 'bi-map',
        'keys'  => [
            'maps_refresh_sec' => [
                'type'  => 'number', 'min' => 15, 'max' => 600, 'default' => '60',
                'label' => 'Период автообновления карты, сек',
            ],
        ],
    ],
    'health' => [
        'title' => 'Здоровье сети',
        'icon'  => 'bi-heart-pulse',
        'keys'  => [
            'health_w_avail' => [
                'type'  => 'number', 'min' => 0, 'max' => 100, 'default' => '40',
                'label' => 'Вес «Доступность»',
                'hint'  => 'Сумма всех весов должна быть 100.',
            ],
            'health_w_backup' => [
                'type'  => 'number', 'min' => 0, 'max' => 100, 'default' => '25',
                'label' => 'Вес «Бэкапы»',
            ],
            'health_w_fw' => [
                'type'  => 'number', 'min' => 0, 'max' => 100, 'default' => '20',
                'label' => 'Вес «Актуальность прошивок»',
            ],
            'health_w_inc' => [
                'type'  => 'number', 'min' => 0, 'max' => 100, 'default' => '15',
                'label' => 'Вес «Инциденты»',
            ],
            'health_backup_hrs' => [
                'type'  => 'number', 'min' => 1, 'max' => 720, 'default' => '48',
                'label' => 'Свежесть бэкапа, часов',
                'hint'  => 'Бэкап старше этого срока считается просроченным.',
            ],
            'health_incident_min_min' => [
                'type'  => 'number', 'min' => 1, 'max' => 1440, 'default' => '2',
                'label' => 'Мин. длительность инцидента, мин',
                'hint'  => 'Более короткие провалы связи инцидентом не считаются.',
            ],
            'health_inc_k' => [
                'type'  => 'number', 'float' => true, 'min' => 0.1, 'max' => 10, 'step' => 0.1, 'default' => '2',
                'label' => 'Строгость штрафа за инциденты (K)',
                'hint'  => 'Штраф = доля затронутого парка × 100 × K. 1 — мягко, 2 — умеренно, 3 и выше — жёстко.',
            ],
            'health_inc_w_router' => [
                'type'  => 'number', 'float' => true, 'min' => 0.1, 'max' => 5, 'step' => 0.1, 'default' => '1',
                'label' => 'Вес устройства: роутер',
            ],
            'health_inc_w_switch' => [
                'type'  => 'number', 'float' => true, 'min' => 0.1, 'max' => 5, 'step' => 0.1, 'default' => '1',
                'label' => 'Вес устройства: коммутатор',
            ],
            'health_inc_w_ap' => [
                'type'  => 'number', 'float' => true, 'min' => 0, 'max' => 5, 'step' => 0.1, 'default' => '0.3',
                'label' => 'Вес устройства: точка доступа',
                'hint'  => 'AP часто отключают или повреждают — вес ниже, чтобы они не роняли оценку.',
            ],
            'health_inc_w_other' => [
                'type'  => 'number', 'float' => true, 'min' => 0, 'max' => 5, 'step' => 0.1, 'default' => '0.3',
                'label' => 'Вес устройства: прочее',
            ],
        ],
    ],
    'zabbix' => [
        'title' => 'Внешние системы (Zabbix)',
        'icon'  => 'bi-plug',
        'keys'  => [
            'monitoring_source' => [
                'type'  => 'choice',
                'options' => [
                    'ping'   => 'Только свой опрос (ping_monitor)',
                    'auto'   => 'Zabbix для сопоставленных, остальные — ping',
                    'zabbix' => 'Только Zabbix',
                ],
                'default' => 'ping',
                'label' => 'Источник мониторинга',
                'hint'  => 'auto: устройства, найденные в Zabbix, мониторятся им, остальные — своим ping_monitor.',
            ],
            'zabbix_url' => [
                'type'  => 'text', 'half' => true,
                'label' => 'Адрес Zabbix',
                'placeholder' => 'http://zabbix.local',
                'hint'  => 'Без слэша в конце. API: <адрес>/api_jsonrpc.php',
            ],
            'zabbix_user' => [
                'type'  => 'text', 'half' => true,
                'label' => 'Пользователь API Zabbix',
                'hint'  => 'Достаточно роли только на чтение хостов и метрик.',
            ],
            'zabbix_password' => [
                'type'  => 'password', 'half' => true,
                'label' => 'Пароль API Zabbix',
                'hint'  => 'Хранится шифрованным (AES-256). Пустое поле = не менять.',
            ],
        ],
    ],
    'perf' => [
        'title' => 'Производительность сборщиков',
        'icon'  => 'bi-speedometer2',
        'keys'  => [
            'workers_backup' => [
                'type'  => 'number', 'min' => 1, 'max' => 50, 'default' => '5',
                'label' => 'Потоки бэкапов (backup.py)',
                'hint'  => 'Сколько устройств бэкапится одновременно. 1 = строго по очереди (щадяще), 5–10 = быстрее, но больше SSH-сессий.',
            ],
            'workers_run_stat' => [
                'type'  => 'number', 'min' => 1, 'max' => 50, 'default' => '20',
                'label' => 'Потоки статистики объектов (run_stat.py)',
            ],
            'workers_harvest' => [
                'type'  => 'number', 'min' => 1, 'max' => 50, 'default' => '8',
                'label' => 'Потоки опроса свичей (harvest.py)',
            ],
        ],
    ],
];

// плоский список всех зарегистрированных ключей
$REG = [];
foreach ($SECTIONS as $sec) {
    foreach ($sec['keys'] as $k => $def) $REG[$k] = $def;
}

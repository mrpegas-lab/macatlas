# MacAtlas

**Система управления сетью: инвентаризация, IPAM, бэкапы с контролем изменений, живая карта сети и отслеживание VLAN прямо на линках.**

Полный цикл для MikroTik (конфигуратор объектов, массовые команды, обновления RouterOS). Мониторинг, бэкапы, карта и IPAM работают с любым оборудованием — поддерживаются MikroTik, TP-Link и Ubiquiti (UBNT).

- 🌐 Сайт: [macatlas.ru](https://macatlas.ru)
- ▶️ Живое демо: [demo.macatlas.ru](http://demo.macatlas.ru) (логин `demo`, пароль `demo`)
- 📄 Лицензия: [BSL 1.1](LICENSE) — личное использование бесплатно ([по-русски кратко](LICENSE.ru.md))

---

## Ключевые возможности

- **VLAN прямо на карте сети** — выбираете VLAN, и карта подсвечивает каждый линк: где он настроен с обеих сторон, а где только с одной. По щелчку на линке — режим портов (access/trunk), PVID, tagged-списки обоих концов и LLDP-сосед. Данные собираются живым опросом оборудования (MikroTik, TP-Link, UBNT), а не разбором конфигов.
- **Инвентаризация и IPAM** — устройства, локации, подсети, VLAN и пулы адресов с автоматической выдачей; ARP-синхронизация подтягивает реально используемые адреса.
- **Конфигуратор MikroTik** — новый объект за минуты: локация + пул адресов → адресация и конфигурация из шаблона готовы к загрузке на устройство.
- **Массовые команды** — одна команда RouterOS на всю группу устройств, с ответом каждого в реальном времени.
- **Бэкапы с контролем изменений** — автоматические ночные бэкапы, индексация версий, построчный diff: кто и что изменил — всегда на виду.
- **Обновление RouterOS** — массовое обновление партиями: система сама определяет архитектуру и подкладывает нужный пакет.
- **Карта сети с мониторингом** — интерактивная топология, проверки ping/HTTP/DNS/SSH/SNMP, статусы узлов в реальном времени.
- **Матрица VLAN** — наглядная матрица доступа между сегментами; изменение правила одним щелчком.
- **Здоровье сети** — сводная оценка 0–100 (доступность, бэкапы, прошивки, инциденты) с журналом инцидентов и причинами простоев.
- **Поиск и обнаружение** — по IP/MAC показывает коммутатор и порт подключения; обнаружение новых устройств в сети.
- **Права и безопасность** — роли, гранулярные права на модули, доменная авторизация (LDAP); пароли оборудования хранятся зашифрованными.

## Скриншоты

| | |
|---|---|
| ![Карта сети](https://macatlas.ru/screenshots/04-map.png) | ![Здоровье сети](https://macatlas.ru/screenshots/06-health.png) |
| Карта сети с мониторингом | Здоровье сети — один экран |
| ![Конфигуратор](https://macatlas.ru/screenshots/01-configurator.png) | ![Бэкапы](https://macatlas.ru/screenshots/05-backups.png) |
| Конфигуратор объектов | Бэкапы по всем локациям |
| ![Матрица VLAN](https://macatlas.ru/screenshots/07-vlan-matrix.png) | ![IPAM](https://macatlas.ru/screenshots/08-ipam.png) |
| Матрица VLAN | IPAM |

## Требования

- Linux (установщик ориентирован на AlmaLinux/RHEL-подобные; под Debian/Ubuntu потребуется ручная доработка пакетов)
- PHP 8+, MariaDB/MySQL, Python 3.10+
- nginx или Apache

## Установка

```bash
# распаковать архив, например в /opt/macatlas
cd /opt/macatlas

# при желании заполнить install.conf (домен, SSL, БД) —
# пустые пароли установщик сгенерирует сам
sudo bash install.sh
```

Установщик сам ставит зависимости, разворачивает БД из `bd/macatlas.sql`,
настраивает планировщик и создаёт учётную запись администратора.
Все сгенерированные пароли сохраняются в `/root/macatlas-credentials.txt`
и показываются в конце установки.

## Структура репозитория

```
install.sh          — установщик
install.conf        — необязательные параметры установки
bd/                 — чистый дамп БД + скрипт очистки (clean_for_install.sql)
etc/macatlas/       — шаблон конфигурации (секреты подставляет установщик)
opt/collector/      — Python-сборщики (мониторинг, бэкапы, статистика, LLDP/VLAN)
var/www/macatlas/   — веб-интерфейс (PHP)
```

## Для частных лиц и бизнеса

- **Личное некоммерческое использование — бесплатно**, без ограничений по функциональности.
- **Коммерческое использование** (организации, ИП, в интересах работодателя) — по коммерческой лицензии: mr.pegas2010@yandex.ru.
- Каждая версия через три года после релиза становится свободной под Apache 2.0 (условия BSL).

---

## English (short)

**MacAtlas** is a self-hosted network management system: inventory, IPAM,
config backups with change tracking, an interactive network map — and VLAN
tracking directly on map links (access/trunk, PVID, tagged lists, LLDP
neighbors). Full lifecycle for MikroTik (configurator, mass commands,
RouterOS updates); monitoring, backups, map and IPAM also work with
TP-Link and Ubiquiti.

- Website: [macatlas.ru](https://macatlas.ru) · Live demo: [demo.macatlas.ru](http://demo.macatlas.ru) (`demo` / `demo`)
- Requirements: Linux, PHP 8+, MariaDB/MySQL, Python 3.10+. Install: `sudo bash install.sh`.
- License: [Business Source License 1.1](LICENSE) — free for personal
  non-commercial use; commercial use requires a paid license
  (mr.pegas2010@yandex.ru). Each version converts to Apache 2.0 three
  years after its release. The UI is currently Russian-only.

© 2026 Evgeny Aleksandrovich Pokrovsky

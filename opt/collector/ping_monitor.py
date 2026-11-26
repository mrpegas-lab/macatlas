#!/usr/bin/env python3
"""
Мониторинг устройств hardware -> таблица hw_ping_state.

У каждого узла на карте (map_nodes.check_type) можно выбрать способ проверки:
ping | http | https | tcp | dns | ssh | snmp (по умолчанию — ping).
Устройства без записи на карте проверяются пингом.

Многопоточный опрос, ~все устройства за несколько секунд.
Запуск по cron каждую минуту:
    * * * * * /usr/bin/python3 /opt/collector/ping_monitor.py >> /var/log/macatlas/ping_monitor.log 2>&1

Инциденты (таблица incidents) — с дебаунсом и склейкой флапов:
- инцидент открывается только после INCIDENT_DOWN_CHECKS подряд неудачных
  проверок (кратковременный ребут/потеря пакетов журнал не засоряют);
- повторное падение в течение FLAP_WINDOW_MIN минут после закрытия
  переоткрывает прежний инцидент, а не плодит новый.
Счётчик подряд-неудач — hw_ping_state.down_streak (миграция
2026-11-23-ping-debounce.sql); без колонки — старое поведение (мгновенно).

Хранение истории — двухъярусное (2026-11-23, миграция 2026-11-23-ping-log-tiers.sql):
- hw_ping_log: поминутные строки, хранятся settings.ping_log_days (по умолч. 7);
  используется «Здоровьем сети» для аптайма за 24ч;
- hw_state_events: только переходы up/down (+ первое наблюдение устройства),
  хранятся settings.ping_events_days (по умолч. 365, 0 = вечно) — долгая
  история аптайма при мизерном объёме.
Чистка обеих таблиц — раз в сутки в 03:0x.
"""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta
import json
import os
import re
import socket
import subprocess
import time
import urllib.request
import mysql.connector

import collector_settings

# ------------------ Конфигурация ------------------

WORKERS      = 64     # потоков опроса
PING_COUNT   = 5      # пакетов на устройство (было 2 — давало ложные падения)
PING_TIMEOUT = 1      # сек ожидания ответа на пакет
CONN_TIMEOUT = 3      # сек таймаут соединения (tcp/http...)

# Дебаунс/склейка инцидентов:
INCIDENT_DOWN_CHECKS = 5    # инцидент открываем после стольких подряд неудачных проверок (~минут)
FLAP_WINDOW_MIN      = 10   # повторное падение в этом окне переоткрывает прежний инцидент

config_path = Path(os.environ.get("MACATLAS_CONFIG", "/etc/macatlas/config.json"))
with open(config_path, "r", encoding="utf-8") as f:
    config = json.load(f)

DB = {
    "host":     config["bd"]["dblocation"],
    "user":     config["bd"]["dbuser"],
    "password": config["bd"]["dbpasswd"],
    "database": config["bd"]["dbname"],
}

# порт по умолчанию для типа проверки (для tcp порт берём из map_nodes.check_port)
DEFAULT_PORTS = {"ssh": 22, "snmp": 161, "dns": 53}

RTT_RE = re.compile(r"(?:min/avg/max(?:/mdev)?\s*=\s*[\d.]+/([\d.]+))|(?:time[=<]([\d.]+)\s*ms)")

# ------------------ Проверки ------------------

def check_ping(ip: str, port=None):
    """Системный ping. Возвращает (is_up, rtt_ms)."""
    try:
        r = subprocess.run(
            ["ping", "-c", str(PING_COUNT), "-W", str(PING_TIMEOUT), "-n", ip],
            capture_output=True, text=True, timeout=PING_COUNT * PING_TIMEOUT + 3,
        )
    except Exception:
        return False, None
    if r.returncode != 0:
        return False, None
    rtt = None
    m = RTT_RE.search(r.stdout)
    if m:
        rtt = float(m.group(1) or m.group(2))
    return True, rtt


def _tcp_probe(ip: str, port: int):
    """TCP-соединение, замер времени. Возвращает (is_up, rtt_ms)."""
    t0 = time.monotonic()
    try:
        s = socket.create_connection((ip, port), timeout=CONN_TIMEOUT)
        s.close()
        return True, round((time.monotonic() - t0) * 1000, 2)
    except OSError:
        return False, None


def check_tcp(ip: str, port=None):
    if not port:
        return False, None
    return _tcp_probe(ip, port)


def check_service(port: int):
    def checker(ip, _port=None):
        return _tcp_probe(ip, port)
    return checker


def check_http(ip: str, port=None):
    """HTTP GET, любой осмысленный ответ = сервис жив. Возвращает (is_up, rtt_ms)."""
    t0 = time.monotonic()
    try:
        req = urllib.request.Request(f"http://{ip}", headers={"User-Agent": "macatlas-ping-monitor"})
        urllib.request.urlopen(req, timeout=CONN_TIMEOUT)
        return True, round((time.monotonic() - t0) * 1000, 2)
    except urllib.error.HTTPError:
        # ответили с ошибкой (4xx/5xx) — сервер жив
        return True, round((time.monotonic() - t0) * 1000, 2)
    except Exception:
        return False, None


def check_https(ip: str, port=None):
    t0 = time.monotonic()
    try:
        ctx = None
        try:
            import ssl
            ctx = ssl.create_default_context()
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        except Exception:
            ctx = None
        req = urllib.request.Request(f"https://{ip}", headers={"User-Agent": "macatlas-ping-monitor"})
        urllib.request.urlopen(req, timeout=CONN_TIMEOUT, context=ctx)
        return True, round((time.monotonic() - t0) * 1000, 2)
    except urllib.error.HTTPError:
        return True, round((time.monotonic() - t0) * 1000, 2)
    except Exception:
        return False, None


CHECKERS = {
    "ping": check_ping,
    "tcp":  check_tcp,
    "dns":  check_service(DEFAULT_PORTS["dns"]),
    "ssh":  check_service(DEFAULT_PORTS["ssh"]),
    "snmp": check_service(DEFAULT_PORTS["snmp"]),
    "http": check_http,
    "https": check_https,
}


def table_exists(conn, name: str) -> bool:
    """Таблица есть? (миграция «Здоровья сети» могла ещё не применяться)"""
    cur = conn.cursor()
    cur.execute("SHOW TABLES LIKE %s", (name,))
    ok = cur.fetchone() is not None
    cur.close()
    return ok


def column_exists(conn, table: str, column: str) -> bool:
    """Колонка есть? (миграция дебаунса могла ещё не применяться)"""
    cur = conn.cursor()
    cur.execute(f"SHOW COLUMNS FROM `{table}` LIKE %s", (column,))
    ok = cur.fetchone() is not None
    cur.close()
    return ok

# ------------------ Основной цикл ------------------

def main():
    started = datetime.now()
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor(dictionary=True)

    # глобальный выключатель мониторинга (галочка в веб-интерфейсе)
    cur.execute("SELECT value FROM app_settings WHERE name = 'monitoring_enabled'")
    row = cur.fetchone()
    if row and str(row["value"]).strip() == "0":
        print(f"[{started:%Y-%m-%d %H:%M:%S}] Мониторинг отключён в настройках — пропуск")
        cur.close()
        conn.close()
        return

    # настройки хранения истории (веб → Настройки → Мониторинг доступности):
    # ping_log_days    — поминутный лог hw_ping_log (нужен для аптайма 24ч)
    # ping_events_days — события up/down hw_state_events (0 = хранить вечно)
    ping_log_days    = collector_settings.get_int("ping_log_days", 7)
    ping_events_days = collector_settings.get_int("ping_events_days", 365)

    # Источник мониторинга (settings.monitoring_source):
    #   ping   — всё пингуем сами (по умолчанию)
    #   zabbix — доступность берёт zabbix_sync, нам делать нечего
    #   auto   — устройства, сопоставленные с Zabbix (zabbix_host_map),
    #            мониторит zabbix_sync, остальные пингуем мы
    source = "ping"
    try:
        cur.execute("SELECT value FROM settings WHERE `key` = 'monitoring_source'")
        row = cur.fetchone()
        if row:
            source = (row["value"] or "ping").strip()
    except Exception:
        pass
    if source == "zabbix":
        print(f"[{started:%Y-%m-%d %H:%M:%S}] monitoring_source=zabbix — пинг выполняет zabbix_sync, выход")
        cur.close()
        conn.close()
        return
    zbx_mapped = []
    if source == "auto" and table_exists(conn, "zabbix_host_map"):
        cur.execute("SELECT hardware_id FROM zabbix_host_map WHERE hardware_id IS NOT NULL")
        zbx_mapped = [r["hardware_id"] for r in cur.fetchall()]

    # устройства для опроса: активные, с IP + способ проверки с карты (если узел есть)
    cur.execute("""
        SELECT h.id, h.hardware_ip,
               COALESCE(n.check_type, 'ping') AS check_type,
               n.check_port AS check_port
        FROM hardware h
        LEFT JOIN map_nodes n ON n.hardware_id = h.id
        WHERE h.active = 1
          AND h.hardware_ip IS NOT NULL
          AND h.hardware_ip != ''
    """)
    devices = {}
    for r in cur.fetchall():
        # режим auto: сопоставленные с Zabbix устройства мониторит zabbix_sync
        if zbx_mapped and r["id"] in zbx_mapped:
            continue
        check_type = (r["check_type"] or "ping").lower()
        if check_type not in CHECKERS:
            check_type = "ping"
        devices[r["id"]] = {
            "id": r["id"],
            "ip": r["hardware_ip"].split("/")[0],
            "method": check_type,
            "port": r["check_port"],
        }
    devices = list(devices.values())

    # текущее состояние: is_up (для last_change при смене up/down),
    # down_streak + last_change (для дебаунса инцидентов)
    has_streak = column_exists(conn, "hw_ping_state", "down_streak")
    if not has_streak:
        print("ВНИМАНИЕ: нет колонки hw_ping_state.down_streak "
              "(миграция 2026-11-23-ping-debounce.sql) — инциденты без дебаунса")
    cur.execute("SELECT hardware_id, is_up, last_change" +
                (", down_streak" if has_streak else "") + " FROM hw_ping_state")
    old = {r["hardware_id"]: r for r in cur.fetchall()}

    # многопоточный опрос
    results = {}
    with ThreadPoolExecutor(max_workers=WORKERS) as ex:
        futures = {
            ex.submit(CHECKERS[d["method"]], d["ip"], d["port"]): d
            for d in devices
        }
        for fut in futures:
            d = futures[fut]
            try:
                results[d["id"]] = (d["method"],) + fut.result()
            except Exception:
                results[d["id"]] = (d["method"], False, None)

    # запись в БД
    now = datetime.now()
    up_cnt = down_cnt = changed_cnt = 0
    by_method = {}
    upd = conn.cursor()
    has_log = table_exists(conn, "hw_ping_log")
    has_inc = table_exists(conn, "incidents")
    has_events = table_exists(conn, "hw_state_events")
    for hw_id, (method, is_up, rtt) in results.items():
        is_up_i = 1 if is_up else 0
        prev = old.get(hw_id)
        was_up = prev["is_up"] if prev else None  # None = устройство опрошено впервые
        changed = was_up is not None and was_up != is_up_i
        if changed:
            changed_cnt += 1

        # счётчик подряд идущих неудачных проверок (дебаунс инцидентов);
        # без колонки в БД — старое поведение (инцидент сразу)
        if has_streak:
            streak = 0 if is_up else ((prev["down_streak"] if prev else 0) + 1)
        else:
            streak = 0 if is_up else INCIDENT_DOWN_CHECKS

        if has_streak:
            upd.execute("""
                INSERT INTO hw_ping_state (hardware_id, is_up, rtt_ms, method, last_change, checked_at, down_streak)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    is_up = VALUES(is_up),
                    rtt_ms = VALUES(rtt_ms),
                    method = VALUES(method),
                    checked_at = VALUES(checked_at),
                    -- last_change обновляем только если скрипт передал его (состояние изменилось)
                    last_change = COALESCE(VALUES(last_change), last_change),
                    down_streak = VALUES(down_streak)
            """, (hw_id, is_up_i, rtt, method, now if changed else None, now, streak))
        else:
            upd.execute("""
                INSERT INTO hw_ping_state (hardware_id, is_up, rtt_ms, method, last_change, checked_at)
                VALUES (%s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    is_up = VALUES(is_up),
                    rtt_ms = VALUES(rtt_ms),
                    method = VALUES(method),
                    checked_at = VALUES(checked_at),
                    last_change = COALESCE(VALUES(last_change), last_change)
            """, (hw_id, is_up_i, rtt, method, now if changed else None, now))

        # история для расчёта аптайма («Здоровье сети»)
        if has_log:
            upd.execute(
                "INSERT INTO hw_ping_log (hardware_id, checked_at, is_up, rtt_ms) "
                "VALUES (%s, %s, %s, %s)", (hw_id, now, is_up_i, rtt))

        # события смены состояния (долгая история аптайма «как в Dude»):
        # пишем только переходы up<->down и первое наблюдение устройства
        if has_events and (changed or was_up is None):
            upd.execute(
                "INSERT INTO hw_state_events (hardware_id, changed_at, is_up) "
                "VALUES (%s, %s, %s)", (hw_id, now, is_up_i))

        # журнал инцидентов.
        # Открытие — только после INCIDENT_DOWN_CHECKS подряд неудач (дебаунс:
        # краткий ребут/потеря пакетов инцидента не создают). started_at берём
        # по last_change (момент реального падения), а не по моменту подтверждения.
        # Склейка флапов: повторное падение в течение FLAP_WINDOW_MIN после
        # закрытия переоткрывает прежний инцидент вместо создания нового.
        # При первом опросе (was_up is None, last_change пуст) started_at —
        # приближение: now минус накопленный streak.
        if has_inc:
            if not is_up:
                if streak >= INCIDENT_DOWN_CHECKS:
                    upd.execute(
                        "SELECT id FROM incidents "
                        "WHERE hardware_id = %s AND ended_at IS NULL", (hw_id,))
                    if upd.fetchone() is None:
                        upd.execute(
                            "SELECT id, ended_at FROM incidents "
                            "WHERE hardware_id = %s ORDER BY id DESC LIMIT 1", (hw_id,))
                        last_inc = upd.fetchone()
                        if (last_inc and last_inc[1]
                                and (now - last_inc[1]).total_seconds() <= FLAP_WINDOW_MIN * 60):
                            upd.execute("UPDATE incidents SET ended_at = NULL WHERE id = %s",
                                        (last_inc[0],))
                        else:
                            lc = prev["last_change"] if prev else None
                            started_at = lc if (lc and lc <= now) \
                                else now - timedelta(minutes=max(streak - 1, 0))
                            upd.execute(
                                "INSERT INTO incidents (hardware_id, started_at) VALUES (%s, %s)",
                                (hw_id, started_at))
            else:
                upd.execute(
                    "UPDATE incidents SET ended_at = %s "
                    "WHERE hardware_id = %s AND ended_at IS NULL",
                    (now, hw_id))

        if is_up:
            up_cnt += 1
        else:
            down_cnt += 1
        by_method[method] = by_method.get(method, 0) + 1

    # чистка hw_ping_state от устройств, которых больше нет в активном списке
    # (деактивированы или потеряли IP) — таблица должна отражать реальность.
    # Устройства на Zabbix-мониторинге не трогаем: их ведёт zabbix_sync.
    pruned = 0
    keep_ids = [d["id"] for d in devices] + list(zbx_mapped)
    if keep_ids:
        fmt = ",".join(["%s"] * len(keep_ids))
        upd.execute(f"DELETE FROM hw_ping_state WHERE hardware_id NOT IN ({fmt})", keep_ids)
        pruned = upd.rowcount if upd.rowcount and upd.rowcount > 0 else 0

    # чистка истории — раз в сутки (в 03:0x), сроки из настроек
    if now.hour == 3:
        if has_log:
            upd.execute(
                f"DELETE FROM hw_ping_log WHERE checked_at < NOW() - INTERVAL {int(ping_log_days)} DAY")
        if has_events and ping_events_days > 0:
            upd.execute(
                f"DELETE FROM hw_state_events WHERE changed_at < NOW() - INTERVAL {int(ping_events_days)} DAY")

    conn.commit()
    upd.close()
    cur.close()
    conn.close()

    dt = (datetime.now() - started).total_seconds()
    methods = ", ".join(f"{m}:{c}" for m, c in sorted(by_method.items()))
    pruned_note = f", удалено устаревших строк: {pruned}" if pruned else ""
    print(f"[{now:%Y-%m-%d %H:%M:%S}] Опрошено: {len(results)} "
          f"(up {up_cnt} / down {down_cnt}, изменилось {changed_cnt}{pruned_note}) "
          f"за {dt:.1f} сек. Методы: {methods}")


if __name__ == "__main__":
    main()

# harvest.py
from pathlib import Path
from base64 import b64decode
import json
import os
import re
import time
import paramiko
import mysql.connector
import logging
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
logging.getLogger("paramiko").setLevel(logging.CRITICAL)

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from librouteros import connect as mikrotik_api_connect

from vendors import pick

# ------------------ Конфигурация ------------------

base_dir = Path(__file__).parent
# Конфиг вынесен из webroot; путь можно переопределить переменной окружения MACATLAS_CONFIG
config_path = Path(os.environ.get("MACATLAS_CONFIG", "/etc/macatlas/config.json"))

with open(config_path, "r", encoding="utf-8") as f:
    config = json.load(f)

DB = {
    "host":     config["bd"]["dblocation"],
    "user":     config["bd"]["dbuser"],
    "password": config["bd"]["dbpasswd"],
    "database": config["bd"]["dbname"],
}

MASTER_KEY = config.get("key", {}).get("master_key", "")

# Количество потоков для параллельной обработки свичей.
# 10-20 — безопасный диапазон. Слишком много потоков может упереться
# в лимиты SSH-сервера или исчерпать файловые дескрипторы.
MAX_WORKERS = 8

# Ретраи SSH-подключения: старые свичи (TP-Link/UBNT) рвут сессию,
# если их таблица SSH-сессий занята зависшими («No existing session»).
# За паузу железка успевает похоронить зависшую сессию.
SSH_ATTEMPTS = 3  # попыток подключения; паузы 5с, 10с

_SSH_TRANSIENT = (
    "no existing session", "error reading ssh protocol banner",
    "connection reset", "connection aborted", "broken pipe",
    "timed out", "timeout", "eof",
)


def _ssh_transient(err) -> bool:
    """Переходная ошибка SSH (стоит повторить), а не постоянная (авторизация и т.п.)."""
    msg = str(err).lower()
    return any(k in msg for k in _SSH_TRANSIENT)


def column_exists(conn, table: str, column: str) -> bool:
    """Колонка есть? (миграция могла ещё не применяться)"""
    cur = conn.cursor()
    cur.execute(f"SHOW COLUMNS FROM `{table}` LIKE %s", (column,))
    ok = cur.fetchone() is not None
    cur.close()
    return ok


# ------------------ Расшифровка пароля ------------------

def decrypt_password(encrypted_b64, master_key_b64):
    if not encrypted_b64 or not master_key_b64:
        return ""
    try:
        key = b64decode(master_key_b64)
        data = b64decode(encrypted_b64)
    except Exception:
        return ""
    if len(data) < 17:
        return ""
    iv = data[:16]
    ciphertext = data[16:]
    try:
        cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
        decryptor = cipher.decryptor()
        padded = decryptor.update(ciphertext) + decryptor.finalize()
        pad_len = padded[-1]
        if 1 <= pad_len <= 16:
            padded = padded[:-pad_len]
        return padded.decode("utf-8", errors="ignore")
    except Exception:
        return ""


# ------------------ SSH ------------------

def ssh_connect(ip, login, password, enable_pass=None, port=22, prefer_legacy=False):
    # ssh_compat: современные алгоритмы, при отказе — legacy
    # (старые прошивки TP-Link: ssh-rsa/group14-sha1/aes-cbc).
    # prefer_legacy — устройство уже определено как старое (hardware.ssh_legacy=1):
    # начинаем сразу с legacy, экономим бесполезное современное подключение.
    import ssh_compat
    client, mode = ssh_compat.connect_ex(ip, login, password, port=port,
                                         timeout=15, prefer_legacy=prefer_legacy)

    shell = client.invoke_shell()
    time.sleep(1)
    shell.recv(5000)

    shell.send("enable\r\n")
    time.sleep(0.5)

    if enable_pass:
        shell.send(f"{enable_pass}\r\n")
        time.sleep(0.5)

    shell.send("terminal length 0\r\n")
    time.sleep(0.5)
    shell.recv(5000)

    return {"client": client, "shell": shell, "ssh_mode": mode}


def ssh_exec(session, command, wait=1.0):
    shell = session["shell"]
    shell.send(f"{command}\r\n")
    time.sleep(wait)

    output = ""
    deadline = time.time() + 30
    last_data = time.time()

    while time.time() < deadline:
        if shell.recv_ready():
            chunk = shell.recv(5000).decode(errors="ignore")
            output += chunk
            last_data = time.time()

            if "--More--" in chunk or "---- More ----" in chunk:
                shell.send(" ")
            elif "<--- More --->" in chunk:
                shell.send(" ")
        else:
            if time.time() - last_data > wait and output:
                break
            time.sleep(0.1)

    output = re.sub(r'\x1b\[.*?[@-~]', '', output).replace('\r', '')
    return output


def ssh_close(session):
    try:
        session["shell"].close()
    except Exception:
        pass
    try:
        session["client"].close()
    except Exception:
        pass

# ------------------ Проверка соответствия бренда ------------------
# Защита от неверно выбранной модели: драйвер берётся по бренду модели,
# и если устройство на самом деле другого вендора, сбор умирал молча
# на середине (2026-11-22, SW-125: модель tplink, железо ubnt).

# Сигнатуры вендоров в текстовых выводах CLI
BRAND_SIGNATURES = {
    "ubnt":     ["edgeswitch", "ubnt", "ubiquiti", "unifi"],
    "tplink":   ["tp-link", "tp link", "tplink", "jetstream", "omada"],
    "mikrotik": ["mikrotik", "routeros"],
}
# Короткие команды-пробы (обе безопасны и быстры на обоих CLI)
BRAND_VERIFY_CMDS = ["show version", "show system-info"]


def canon_brand(brand):
    """Имя бренда из справочника -> канонический ключ BRAND_SIGNATURES."""
    b = (brand or "").strip().lower()
    for key, sigs in BRAND_SIGNATURES.items():
        if b == key or any(s in b for s in sigs):
            return key
    return b


def verify_brand(session, expected_brand):
    """Проверяет, что устройство отвечает как бренд из модели.
    Возвращает имя ЧУЖОГО бренда при несовпадении, иначе None.
    Пустой/неопознанный ответ — None (сбор не блокируем)."""
    exp = canon_brand(expected_brand)
    own = BRAND_SIGNATURES.get(exp, [])
    for cmd in BRAND_VERIFY_CMDS:
        try:
            out = ssh_exec(session, cmd, wait=1.2) or ""
        except Exception:
            continue
        low = out.lower()
        if own and any(s in low for s in own):
            return None  # устройство отвечает как ожидаемый бренд
        for key, sigs in BRAND_SIGNATURES.items():
            if key != exp and any(s in low for s in sigs):
                return key
    return None

# ------------------ MikroTik API-сессия ------------------

def mikrotik_connect(ip, login, password, port=None):
    if port is None:
        port = 8728
    return mikrotik_api_connect(host=ip, username=login, password=password, port=port)


def mikrotik_close(api):
    try:
        api.close()
    except Exception:
        pass

# ------------------ Утилиты для вендоров ------------------

def save_fixture(ip, kind, raw):
    """Сохраняем сырой вывод для отладки парсеров."""
    d = base_dir / "fixtures" / ip.replace(".", "_")
    d.mkdir(parents=True, exist_ok=True)
    ts = time.strftime("%Y%m%d_%H%M%S")
    try:
        (d / f"{kind}_{ts}.txt").write_text(raw, encoding="utf-8")
    except Exception:
        pass

def get_vendor_by_mac(mac, cursor):
    try:
        prefix = re.sub(r'[^0-9A-F]', '', mac.upper())[:6]
        cursor.execute(
            "SELECT id FROM oui_vendors WHERE prefix_plain = %s LIMIT 1",
            (prefix,),
        )
        result = cursor.fetchone()
        return result[0] if result else 0
    except Exception as e:
        print(f"  Ошибка при определении вендора для {mac}: {e}")
        return 0

# ------------------ Сбор с одного свича ------------------

def collect_switch(sw):
    ip          = sw["hardware_ip"]
    brand       = sw["brand_name"] or ""
    login       = sw["login"]
    password    = sw["password"]
    enable_pass = sw["enable_pass"]

    if not login or not password:
        print(f"  [{ip}] Нет кредов")
        return {"macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
                "errors": ["no credentials"], "fatal": True}

    vendor = pick(brand)
    if vendor is None:
        print(f"  [{ip}] Нет драйвера для бренда: {brand}")
        return {"macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
                "errors": [f"no driver for {brand}"], "fatal": True}

    # ---- MikroTik: API ----
    if getattr(vendor, "PROTOCOL", "ssh") == "mikrotik_api":
        try:
            api = mikrotik_connect(ip, login, password, port=sw.get("port_api"))
        except Exception as e:
            print(f"  [{ip}] Ошибка API: {e}")
            return {"macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
                    "errors": [f"api: {e}"], "fatal": True}

        try:
            return vendor.collect(api, ip=ip, sw=sw, save_fixture=save_fixture)
        except Exception as e:
            print(f"  [{ip}] Ошибка сбора: {e}")
            return {"macs": {}, "poe": {}, "port_status": {}, "port_speed": {}, "errors": [f"collect: {e}"]}
        finally:
            mikrotik_close(api)

    # ---- Остальные: SSH (с ретраями на переходные сбои) ----
    enable_to_send = enable_pass if getattr(vendor, "SEND_ENABLE_PASS", False) else None
    prefer_legacy = sw.get("ssh_legacy") == 1

    session = None
    last_err = None
    for attempt in range(1, SSH_ATTEMPTS + 1):
        try:
            session = ssh_connect(ip, login, password, enable_pass=enable_to_send,
                                  port=sw.get("port_ssh") or 22,
                                  prefer_legacy=prefer_legacy)
            break
        except Exception as e:
            last_err = e
            if attempt < SSH_ATTEMPTS and _ssh_transient(e):
                pause = 5 * attempt
                print(f"  [{ip}] SSH сбой ({e}) — повтор {attempt + 1}/{SSH_ATTEMPTS} через {pause}с")
                time.sleep(pause)
                continue
            break

    if session is None:
        e = last_err
        if isinstance(e, paramiko.ssh_exception.SSHException) \
                and "Error reading SSH protocol banner" in str(e):
            print(f"  [{ip}] SSH не отвечает (порт закрыт или SSH выключен)")
            return {"macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
                    "errors": ["ssh: banner"], "fatal": True}
        print(f"  [{ip}] Ошибка SSH: {e}")
        return {"macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
                "errors": [f"ssh: {e}"], "fatal": True}

    # запоминаем рабочий SSH-режим, чтобы старое железо не гонять через
    # заведомо бесполезное современное подключение (hardware.ssh_legacy)
    mode = session.get("ssh_mode")
    if sw.get("has_ssh_legacy") and mode:
        want = 1 if mode == "legacy" else 0
        if sw.get("ssh_legacy") != want:
            try:
                c2 = mysql.connector.connect(**DB)
                q2 = c2.cursor()
                q2.execute("UPDATE hardware SET ssh_legacy=%s WHERE id=%s", (want, sw["id"]))
                c2.commit()
                q2.close()
                c2.close()
                print(f"  [{ip}] SSH-режим: {mode} — запомнили")
            except Exception:
                pass

    try:
        # проверка: в модели не перепутан бренд (устройство отвечает как другой вендор)
        try:
            wrong = verify_brand(session, brand)
        except Exception:
            wrong = None
        if wrong:
            print(f"  [{ip}] В модели бренд '{brand}', а устройство отвечает как '{wrong}' — "
                  f"пропуск, проверь модель устройства!")
            return {"macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
                    "errors": [f"brand mismatch: model={brand}, device={wrong}"], "fatal": True}

        return vendor.collect(session, ip=ip, sw=sw,
                              ssh_exec=ssh_exec, save_fixture=save_fixture)
    except Exception as e:
        print(f"  [{ip}] Ошибка сбора: {e}")
        return {"macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
                "errors": [f"collect: {e}"]}
    finally:
        ssh_close(session)


# ------------------ Запись в БД ------------------

def update_db(sw, collected, max_retries=3):
    switch_id   = sw["id"]
    ip          = sw["hardware_ip"]
    macs        = collected["macs"]
    poe         = collected["poe"]
    port_status = collected.get("port_status", {})
    port_speed  = collected.get("port_speed", {})
    port_names  = collected.get("port_names", {})
    errors      = collected["errors"]

    all_ports = (set(macs.keys())
                 | set(poe.keys())
                 | set(port_status.keys())
                 | set(port_speed.keys())
                 | set(port_names.keys()))

    # фатальный отказ (нет кредов/драйвера, SSH/API не поднялось, чужой бренд):
    # в БД НИЧЕГО не пишем — иначе паспорт устройства затирался бы пустыми значениями
    if collected.get("fatal"):
        print(f"  [{ip}] Сбор не выполнен ({'; '.join(errors)}) — данные в БД не трогаю")
        return

    is_router = bool(sw.get("is_router"))

    for attempt in range(1, max_retries + 1):
        conn = mysql.connector.connect(**DB)
        cur  = conn.cursor()
        try:
            # ---- Универсальные метаданные устройства ----
            # Роутерам паспорт пишет ipam_arp_sync.py — здесь НЕ трогаем
            # (иначе температура/PoE затирались бы NULL'ами: у роутеров
            # нет switch-temperature и poe-полей в ответе API).
            if not is_router:
                cur.execute("""
                    UPDATE hardware SET
                        firmware_version   = %s,
                        bootloader_version = %s,
                        serial_number      = %s,
                        model_name         = %s,
                        uptime             = %s,
                        temperature        = %s,
                        poe_budget         = %s,
                        poe_consumption    = %s
                    WHERE id = %s
                """, (
                    collected.get("firmware_version")   or None,
                    collected.get("bootloader_version") or None,
                    collected.get("serial_number")      or None,
                    collected.get("model_name")         or None,
                    collected.get("uptime")             or None,
                    collected.get("temperature"),
                    collected.get("poe_budget"),
                    collected.get("poe_consumption"),
                    switch_id,
                ))

            # Сменилась прошивка → сбрасываем запомненный SSH-режим:
            # на следующем прогоне устройство заново определит modern/legacy
            # (новая прошивка могла как выпилить legacy-алгоритмы, так и
            # научиться современным).
            new_fw = collected.get("firmware_version") or ""
            if (not is_router and new_fw and sw.get("has_ssh_legacy")
                    and sw.get("firmware_version")
                    and sw["firmware_version"] != new_fw
                    and sw.get("ssh_legacy") is not None):
                cur.execute("UPDATE hardware SET ssh_legacy=NULL WHERE id=%s",
                            (switch_id,))
                print(f"  [{ip}] Прошивка сменилась ({sw['firmware_version']} → {new_fw}) — "
                      f"SSH-режим переопределится на следующем прогоне")

            if not all_ports and errors:
                print(f"  [{ip}] Нет данных портов для записи (ошибки сбора)")
                conn.commit()
                return

            # Очистка старых MAC-ов (роутерам FDB не собираем — таблицу не трогаем)
            if not is_router:
                cur.execute(
                    "DELETE FROM sw_port_fdb WHERE id_switch=%s AND (created_at IS NULL OR created_at < NOW() - INTERVAL 7 DAY)",
                    (switch_id,),
                )
                print(f"  [{ip}] Удалено {cur.rowcount} записей MAC для коммутатора {switch_id}")

            vendor_cur = conn.cursor()
            mac_batch  = []
            now = datetime.now()

            for port_num in sorted(all_ports):
                mac_list   = macs.get(port_num, [])
                mac_count  = len(mac_list)
                poe_status = poe.get(port_num, "unknown")
                spd        = port_speed.get(port_num)
                p_name     = port_names.get(port_num)

                pstatus = port_status.get(port_num, "down")
                if pstatus not in ("up", "down"):
                    pstatus = "down"

                la_now = now if pstatus == "up"   else None
                ld_now = now if pstatus == "down" else None

                cur.execute(
                    "SELECT port_status, poe, speed FROM sw_port_info "
                    "WHERE id_switch=%s AND port=%s",
                    (switch_id, port_num),
                )
                row = cur.fetchone()

                if row:
                    if pstatus == "up":
                        cur.execute(
                            "UPDATE sw_port_info SET "
                            "port_name=%s, mac_count=%s, port_status=%s, poe=%s, speed=%s, "
                            "last_active=%s, scanned_at=NOW() "
                            "WHERE id_switch=%s AND port=%s",
                            (p_name, mac_count, pstatus, poe_status, spd, now, switch_id, port_num),
                        )
                    else:
                        cur.execute(
                            "UPDATE sw_port_info SET "
                            "port_name=%s, mac_count=%s, port_status=%s, poe=%s, speed=%s, "
                            "last_down=%s, scanned_at=NOW() "
                            "WHERE id_switch=%s AND port=%s",
                            (p_name, mac_count, pstatus, poe_status, spd, now, switch_id, port_num),
                        )
                else:
                    cur.execute(
                        """INSERT INTO sw_port_info
                           (id_switch, port, port_name, mac_count, poe, speed, port_status,
                            last_active, last_down, scanned_at)
                           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())""",
                        (switch_id, port_num, p_name, mac_count, poe_status, spd, pstatus,
                         la_now, ld_now),
                    )

                if port_num not in macs or is_router:
                    continue

                cur.execute("DELETE FROM sw_port_fdb WHERE id_switch=%s AND port=%s",
                            (switch_id, port_num))

                for mac in mac_list:
                    mac_upper = mac.upper()
                    vendor_id = get_vendor_by_mac(mac_upper, vendor_cur) or 0
                    mac_batch.append((switch_id, port_num, mac_upper, vendor_id))

            if mac_batch:
                cur.executemany(
                    "INSERT INTO sw_port_fdb (id_switch, port, port_mac, vendor_id, created_at) "
                    "VALUES (%s, %s, %s, %s, NOW())",
                    mac_batch,
                )

            # ---- Чистка портов, которых больше нет на устройстве (2026-11-23) ----
            # sw_port_info исторически был только INSERT/UPDATE: исчезнувшие
            # с устройства порты (туннели у VPN-роутеров, переименованные,
            # извлечённые модули) висели вечно. Удаляем строки устройства,
            # не вошедшие в текущий сбор. Только при непустом all_ports —
            # пустой сбор (сбой API/SSH) не должен стирать реальные порты.
            if all_ports:
                fmt = ",".join(["%s"] * len(all_ports))
                cur.execute(
                    f"DELETE FROM sw_port_info WHERE id_switch=%s AND port NOT IN ({fmt})",
                    (switch_id, *sorted(all_ports)),
                )
                if cur.rowcount:
                    print(f"  [{ip}] Удалено устаревших портов: {cur.rowcount}")

            # ---- VLAN по портам -> sw_cfg_port (если вендор собрал) ----
            vlans = collected.get("vlans")
            if vlans is not None:
                cur.execute("DELETE FROM sw_cfg_port WHERE hardware_id=%s", (switch_id,))
                rows = []
                for p in vlans.values():
                    tg = p.get("tagged") or []
                    ut = p.get("untagged") or []
                    rows.append((
                        switch_id, p.get("port", ""), p.get("port_num"),
                        p.get("mode", "unknown"), p.get("pvid"),
                        "ALL" if tg == "ALL" else (",".join(map(str, tg)) or None),
                        ",".join(map(str, ut)) or None,
                        p.get("descr") or None, "harvest",
                    ))
                if rows:
                    cur.executemany(
                        """INSERT INTO sw_cfg_port
                           (hardware_id, port, port_num, mode, pvid, tagged, untagged, descr, src_file)
                           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                        rows,
                    )

            # ---- LLDP/CDP-соседи -> sw_port_neighbor ----
            neighbors = collected.get("neighbors")
            if neighbors is not None:
                cur.execute("DELETE FROM sw_port_neighbor WHERE hardware_id=%s", (switch_id,))
                # один и тот же сосед может анонсироваться на порту несколько раз
                # (и без MAC) — дедуплицируем, иначе дубль по uniq (hw,port,mac)
                seen = set()
                seen_port_mac = set()
                rows = []
                for n in neighbors:
                    mac = n.get("neighbor_mac") or None
                    key = (n.get("port", ""), mac,
                           n.get("neighbor_name") or "", n.get("neighbor_port") or "")
                    if key in seen:
                        continue
                    if mac is not None:
                        pm = (n.get("port", ""), mac)
                        if pm in seen_port_mac:
                            continue
                        seen_port_mac.add(pm)
                    seen.add(key)
                    # защита от «Data too long»: некоторые железки анонсируют
                    # в System Name / Port ID длинные описания — обрезаем,
                    # чтобы один длиннющий сосед не ронял запись всего устройства
                    nb_name = (n.get("neighbor_name") or "")[:250] or None
                    nb_port = (n.get("neighbor_port") or "")[:250] or None
                    rows.append(
                        (switch_id, n.get("port_num"), n.get("port", ""),
                         nb_name, nb_port,
                         mac, n.get("proto", "lldp"))
                    )
                if rows:
                    cur.executemany(
                        """INSERT INTO sw_port_neighbor
                           (hardware_id, port_num, port, neighbor_name, neighbor_port, neighbor_mac, proto)
                           VALUES (%s,%s,%s,%s,%s,%s,%s)
                           ON DUPLICATE KEY UPDATE seen_at=CURRENT_TIMESTAMP""",
                        rows,
                    )

            vendor_cur.close()
            conn.commit()
            return  # успех — выходим из retry-цикла

        except mysql.connector.errors.DatabaseError as e:
            conn.rollback()
            # 1213 = deadlock, 1205 = lock wait timeout
            if e.errno in (1213, 1205) and attempt < max_retries:
                print(f"  [{ip}] Дедлок/лок (попытка {attempt}/{max_retries}), повтор...")
                time.sleep(0.5 * attempt)  # нарастающая пауза
                continue
            print(f"  [{ip}] Ошибка записи в БД: {e}")
            raise
        except Exception as e:
            conn.rollback()
            print(f"  [{ip}] Ошибка записи в БД: {e}")
            raise
        finally:
            cur.close()
            conn.close()

# ------------------ Обработка одного свича (для потока) ------------------

def process_switch(sw, idx, total):
    """
    Полная обработка одного свича: сбор + запись в БД.
    Возвращает словарь со статистикой для лога.
    """
    ip = sw["hardware_ip"]
    print(f"\n[{idx}/{total}] Старт обработки устройства {ip}")
    t0 = time.time()

    collected = collect_switch(sw)

    if collected["errors"]:
        print(f"  [{ip}] Ошибки: {collected['errors']}")

    has_data = (bool(collected["macs"])
                or bool(collected["poe"])
                or bool(collected.get("port_status")))
    if not has_data:
        print(f"  [{ip}] Нет данных — пропуск записи")
        return {"ip": ip, "ok": False, "reason": "no data", "elapsed": time.time() - t0}

    try:
        update_db(sw, collected)
    except Exception as e:
        print(f"  [{ip}] Ошибка записи в БД: {e}")
        return {"ip": ip, "ok": False, "reason": f"db: {e}", "elapsed": time.time() - t0}

    mac_ports  = len(collected["macs"])
    poe_ports  = len(collected["poe"])
    st         = collected.get("port_status") or {}
    spd        = collected.get("port_speed") or {}
    up_cnt     = sum(1 for v in st.values() if v == "up")
    down_cnt   = sum(1 for v in st.values() if v == "down")
    spd_cnt    = sum(1 for v in spd.values() if v is not None)
    total_ports = len(set(collected["macs"].keys())
                      | set(collected["poe"].keys())
                      | set(st.keys())
                      | set(spd.keys()))
    elapsed = time.time() - t0
    print(f"  [{ip}] Готово за {elapsed:.1f}s: MAC на {mac_ports} портах, "
          f"PoE на {poe_ports} портах, link up={up_cnt} down={down_cnt}, "
          f"speed={spd_cnt}, всего портов: {total_ports}")
    return {"ip": ip, "ok": True, "elapsed": elapsed}


# ------------------ Основная функция ------------------

def main(limit=None, filter_ip=None, workers=None):
    # Загружаем список свичей
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor(dictionary=True)

    # свичи (model_type_id=2) + роутеры (1): у роутеров собираем только
    # порты/VLAN/LLDP (без FDB и паспорта — паспорт роутеров пишет ipam_arp_sync.py)
    # колонка hardware.ssh_legacy появляется миграцией
    # 2026-11-23-harvest-ssh-neighbor.sql — без неё работаем как раньше
    has_ssh_legacy = column_exists(conn, "hardware", "ssh_legacy")
    legacy_col = ",\n               hardware.ssh_legacy" if has_ssh_legacy else ""
    cur.execute("""
        SELECT hardware.id, brand_name, hardware_ip, poe,
               hardware.firmware_version AS fw_current""" + legacy_col + """,
               model.model_name,
               model.model_type_id,
               model.ports     AS model_ports,
               model.poe_ports AS model_poe_ports,
               c.login         AS cred_login,
               c.pas           AS cred_pas,
               c.enable_pas    AS cred_enable_pas,
               c.port_api      AS cred_port_api,
               c.port_ssh      AS cred_port_ssh
        FROM hardware
        LEFT OUTER JOIN model ON (hardware.id_model = model.id)
        LEFT OUTER JOIN brand ON (model.id_brand = brand.id)
        LEFT OUTER JOIN credentials c ON (c.id = hardware.cred_id)
        WHERE active = true AND model_type_id IN (1, 2)
        ORDER BY INET_ATON(hardware_ip)
    """)

    switches = []
    for row in cur.fetchall():
        enc_pass   = row["cred_pas"] or ""
        enc_enable = row["cred_enable_pas"] or ""

        password    = decrypt_password(enc_pass, MASTER_KEY)   if enc_pass   else ""
        enable_pass = decrypt_password(enc_enable, MASTER_KEY) if enc_enable else ""

        switches.append({
            "id": row["id"],
            "brand_name": row["brand_name"],
            "hardware_ip": row["hardware_ip"],
            "is_router": (row["model_type_id"] == 1),
            "poe": row["poe"],
            "model": row["model_name"] or "",
            "ports": row["model_ports"] or 0,
            "poe_ports": row["model_poe_ports"] or 0,
            "login": row["cred_login"] or "",
            "password": password,
            "enable_pass": enable_pass,
            "port_api": row["cred_port_api"] or None,
            "port_ssh": row["cred_port_ssh"] or None,
            "firmware_version": row.get("fw_current") or "",
            "ssh_legacy": row.get("ssh_legacy") if has_ssh_legacy else None,
            "has_ssh_legacy": has_ssh_legacy,
        })

    cur.close()
    conn.close()

    # --- фильтр по IP ---
    if filter_ip:
        if isinstance(filter_ip, str):
            filter_ip = [filter_ip]
        switches = [sw for sw in switches if sw["hardware_ip"] in filter_ip]

    # --- ограничение количества свичей ---
    if limit:
        switches = switches[:limit]

    total = len(switches)
    if total == 0:
        print("Нет устройств для обработки")
        return

    # потоки: явный аргумент > настройка workers_harvest (веб) > дефолт кода
    if workers is None:
        try:
            import collector_settings
            workers = collector_settings.get_int("workers_harvest", MAX_WORKERS)
        except Exception:
            workers = MAX_WORKERS
    n_workers = workers
    # не создавать больше потоков, чем свичей
    n_workers = min(n_workers, total)

    print(f"Будет обработано устройств: {total} (потоков: {n_workers})")

    # живой прогресс в БД (только при запуске из веба; иначе no-op)
    try:
        import job_progress
        job_progress.init(total)
    except Exception:
        job_progress = None

    t_start = time.time()
    ok_cnt     = 0
    fail_cnt   = 0
    skip_cnt   = 0

    with ThreadPoolExecutor(max_workers=n_workers) as executor:
        futures = {
            executor.submit(process_switch, sw, idx, total): sw
            for idx, sw in enumerate(switches, start=1)
        }
        for future in as_completed(futures):
            sw = futures[future]
            try:
                res = future.result()
                if res["ok"]:
                    ok_cnt += 1
                elif res.get("reason") == "no data":
                    skip_cnt += 1
                else:
                    fail_cnt += 1
            except Exception as e:
                fail_cnt += 1
                print(f"  [{sw['hardware_ip']}] Критическая ошибка потока: {e}")
            if job_progress:
                job_progress.step(current=sw.get("switch_name") or sw["hardware_ip"])

    t_total = time.time() - t_start
    print(f"\n=== Итого за {t_total:.1f}s: "
          f"успешно={ok_cnt}, пропущено={skip_cnt}, ошибок={fail_cnt} ===")


# ------------------ Запуск ------------------

if __name__ == "__main__":
    # обычный режим — все свичи, потоки по умолчанию (MAX_WORKERS)
    main()

    # режим отладки — первые 15 свичей, 5 потоков
    #main(limit=15, workers=5)

    # режим отладки — конкретные IP, 3 потока
    #main(filter_ip=["10.210.0.3", "10.220.0.126", "10.220.0.121"], workers=3)
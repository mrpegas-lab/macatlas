#!/usr/bin/env python3
"""
backup.py — сбор резервных копий конфигураций устройств.

Заменяет ansible-плейбук backup_mikrotik.yml и добавляет свичи.

Подход: переиспользуем механику harvest (paramiko, credentials из БД,
расшифровка master-ключом).
  - MikroTik: текст /export (stdout) + бинарник /system backup по SCP
    (SFTP RouterOS не поддерживает; каждый шаг — своё SSH-подключение).
  - UBNT/TPLink: CLI show running-config (shell + enable-пароль).
  - Точки доступа (UniFi AP, model_type_id=3) НЕ бэкапим.

Файлы складываются в /mnt/backups/<локация>/<устройство>/:
  <hostname>_<YYYYmmddHHMMSS>.rsc    — текстовый экспорт MikroTik
  <hostname>_<YYYYmmddHHMMSS>.backup — бинарный бэкап MikroTik
  <hostname>_<YYYYmmddHHMMSS>.cfg    — running-config свичей
Дальше файлы подхватывает backup_indexer.py.

Запуск:
    python3 backup.py                          # по 1 устройству каждого бренда (dry-run)
    python3 backup.py --ip 10.0.0.5            # конкретные устройства
    python3 backup.py --all                    # все устройства
    python3 backup.py --location COD           # одна локация (locations.new_name)
    python3 backup.py --all --write            # БОЕВОЙ РЕЖИМ: записать файлы
    python3 backup.py --all --write --workers 5
Без --write работает dry-run: только консоль, файлы не сохраняются.

Параметры:
    --ip IP [IP ...]   бэкап только указанных устройств (по внутреннему IP)
    --all              все устройства из hardware + mt_sites
                       (без --all/--ip/--location — по 1 устройству каждого бренда,
                       удобно для проверки)
    --location NAME    устройства одной локации (locations.new_name, напр. COD)
    --limit N          не больше N устройств (для тестов)
    --write            БОЕВОЙ режим: записать файлы в backup_root
    --workers N        сколько устройств бэкапится ОДНОВРЕМЕННО (потоков,
                       каждый — своё SSH-подключение). По умолчанию 3.
                       1 = строго по очереди (самый щадящий режим),
                       5-10 = быстрее, но больше одновременных SSH-сессий.
                       На результат не влияет, только на скорость и нагрузку.
    --help             эта справка
"""
import argparse
import json
import os
import re
import sys
import time
from base64 import b64decode
from pathlib import Path

import mysql.connector
import paramiko
import logging
logging.getLogger("paramiko").setLevel(logging.CRITICAL)

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend

# ------------------ Конфигурация ------------------

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

# Транслитерация имени типа локации в имя папки (та же функция, что в ansible.py)
_TRANSLIT = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e',
    'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
    'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
    'ф': 'f', 'х': 'h', 'ц': 'c', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch',
    'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya',
}

def translit_dir(name) -> str:
    if not name:
        return ''
    s = ''.join(_TRANSLIT.get(ch, ch) for ch in str(name).strip().lower())
    return re.sub(r'[^a-z0-9]+', '_', s).strip('_')

# Куда складывать бэкапы и сколько хранить
BACKUP_ROOT     = Path(config.get("backup_root", "/mnt/backups"))
RETENTION_DAYS  = int(config.get("backup_retention_days", 365))

# model_type_id, которые НЕ бэкапим (3 = точки доступа)
SKIP_MODEL_TYPES = {3}

# Потоков по умолчанию (SSH-сессии на каждое устройство)
MAX_WORKERS = 5

# ------------------ Расшифровка (копия из harvest.py) ------------------

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
    iv, ciphertext = data[:16], data[16:]
    try:
        cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
        padded = cipher.decryptor().update(ciphertext) + cipher.decryptor().finalize()
        pad_len = padded[-1]
        if 1 <= pad_len <= 16:
            padded = padded[:-pad_len]
        return padded.decode("utf-8", errors="ignore")
    except Exception:
        return ""

# ------------------ SSH ------------------

def ssh_client(ip, login, password, port=22):
    # ssh_compat: современные алгоритмы, при отказе — legacy
    # (старые прошивки TP-Link: ssh-rsa/group14-sha1/aes-cbc)
    import ssh_compat
    return ssh_compat.connect(ip, login, password, port=port, timeout=15)


def cli_exec(client, command, enable_pass=None, wait=1.0, timeout=60):
    """Интерактивный CLI (ubnt/tplink): shell + enable + добивка пейджинга."""
    shell = client.invoke_shell()
    time.sleep(1)
    shell.recv(65535)
    # TPLink/UBNT: привилегированный режим
    shell.send("enable\r\n")
    time.sleep(0.7)
    out = shell.recv(65535).decode(errors="ignore")
    if enable_pass and re.search(r'password', out, re.I):
        shell.send(f"{enable_pass}\r\n")
        time.sleep(0.7)
        shell.recv(65535)
    shell.send("terminal length 0\r\n")
    time.sleep(0.5)
    shell.recv(65535)

    shell.send(f"{command}\r\n")
    output, last_data = "", time.time()
    deadline = time.time() + timeout
    while time.time() < deadline:
        if shell.recv_ready():
            chunk = shell.recv(65535).decode(errors="ignore")
            output += chunk
            last_data = time.time()
            if re.search(r'--More--|--- More ---|<--- More --->', chunk, re.I):
                shell.send(" ")
        else:
            if time.time() - last_data > 2 and output:
                break
            time.sleep(0.1)
    shell.close()
    output = re.sub(r'\x1b\[.*?[@-~]', '', output).replace('\r', '')
    return output


def exec_cmd(client, command, timeout=120):
    """Неинтерактивное выполнение (MikroTik): exec-канал, как по API."""
    stdin, stdout, stderr = client.exec_command(command, timeout=timeout)
    out = stdout.read().decode(errors="ignore")
    err = stderr.read().decode(errors="ignore")
    return out, err


def scp_get(client, remote_path, timeout=60):
    """Скачивание файла по SCP (протокол rcp, 'scp -f').

    MikroTik RouterOS не поддерживает SFTP, но поддерживает SCP
    (так же работает ansible net_get). Реализация без внешних
    зависимостей поверх paramiko exec-канала.
    """
    stdin, stdout, stderr = client.exec_command(f"scp -f {remote_path}", timeout=timeout)
    chan = stdout.channel
    chan.settimeout(timeout)

    def read_line():
        line = b""
        while not line.endswith(b"\n"):
            ch = chan.recv(1)
            if not ch:
                raise IOError("SCP: канал закрыт удалённой стороной")
            line += ch
        return line

    # старт передачи
    chan.sendall(b"\x00")
    header = read_line()
    code = header[:1]
    if code == b"\x01":  # ошибка на стороне роутера
        raise IOError(f"SCP: {header[1:].decode(errors='ignore').strip()}")
    if code != b"C":
        raise IOError(f"SCP: неожиданный ответ {header!r}")

    # header: b"C<mode> <size> <name>\n"
    parts = header[1:].split(b" ", 2)
    size = int(parts[1])

    chan.sendall(b"\x00")
    data = b""
    while len(data) < size:
        chunk = chan.recv(min(65536, size - len(data)))
        if not chunk:
            raise IOError("SCP: обрыв на %d из %d байт" % (len(data), size))
        data += chunk
    # завершающий байт от сервера
    end = chan.recv(1)
    if end != b"\x00":
        raise IOError(f"SCP: нет завершающего байта ({end!r})")
    chan.sendall(b"\x00")
    return data

# ------------------ Бэкапы по вендорам ------------------

def backup_mikrotik(dev):
    """MikroTik: текст /export + бинарник по SCP.

    RouterOS после закрытия exec-канала часто рвёт всё SSH-соединение,
    поэтому каждый шаг — своё подключение (как делает ansible:
    отдельная сессия на команды и отдельная на net_get/scp).
    """
    ip, login, password = dev["ip"], dev["login"], dev["password"]
    port = dev["port"] or 22

    # 1. Текстовый экспорт
    t0 = time.time()
    client = ssh_client(ip, login, password, port)
    try:
        text, err = exec_cmd(client, "/export")
    finally:
        client.close()
    # MikroTik отдаёт строки с CRLF — срезаем \r, иначе каждая строка
    # будет «отличаться» от ansible-файлов (LF) в хэше и diff
    text = text.replace("\r\n", "\n").replace("\r", "\n").strip()
    print(f"  /export: {len(text)} байт за {time.time()-t0:.1f}s"
          + (f" (stderr: {err.strip()[:100]})" if err.strip() else ""))

    # 2. Создание бинарника на роутере
    tmp_name = "macatlas_backup_tmp"
    client = ssh_client(ip, login, password, port)
    try:
        _, err2 = exec_cmd(client, f'/system backup save name={tmp_name}')
    finally:
        client.close()
    if err2.strip():
        print(f"  backup save stderr: {err2.strip()[:200]}")

    # 3. Скачивание по SCP (SFTP RouterOS не поддерживает),
    #    ждём, пока файл допишется (на слабых моделях до 10-15 сек)
    data, last_err = b"", None
    for _ in range(20):
        try:
            client = ssh_client(ip, login, password, port)
            try:
                data = scp_get(client, f"/{tmp_name}.backup")
            finally:
                client.close()
            if data:
                break
        except Exception as e:
            last_err = e
            time.sleep(1)
    if not data and last_err:
        raise last_err
    print(f"  бинарник: {len(data)} байт по SCP")

    # 4. Уборка на роутере
    try:
        client = ssh_client(ip, login, password, port)
        try:
            exec_cmd(client, f'/file remove "{tmp_name}.backup"')
        finally:
            client.close()
    except Exception:
        pass  # файл останется на роутере — не критично

    return {"text": text, "binary": data}


def backup_cli(dev, command):
    """UBNT/TPLink: show running-config через CLI (с enable-паролем, как harvest)."""
    client = ssh_client(dev["ip"], dev["login"], dev["password"], dev["port"] or 22)
    t0 = time.time()
    text = cli_exec(client, command, enable_pass=dev["enable_pass"], timeout=120)
    client.close()
    # убираем эхо команды и приглашение в конце
    lines = [l for l in text.split("\n")
             if command not in l and not l.strip().endswith(("#", ">"))]
    text = "\n".join(lines).strip()
    print(f"  {command}: {len(text)} байт за {time.time()-t0:.1f}s")
    return {"text": text}


def backup_device(dev):
    brand = (dev["brand"] or "").lower()
    if "mikrotik" in brand:
        return backup_mikrotik(dev)
    if "ubnt" in brand or "ubiquiti" in brand:
        return backup_cli(dev, "show running-config")
    if "tplink" in brand or "tp-link" in brand:
        return backup_cli(dev, "show running-config")
    raise ValueError(f"неизвестный бренд: {dev['brand']}")

# ------------------ Устройства ------------------

def load_devices(only_ips=None, per_brand=False, limit=None, location=None):
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor(dictionary=True)
    cur.execute("""
        SELECT h.id, h.switch_name, h.hardware_ip, h.locations_id,
               l.new_name AS loc_name,
               b.brand_name, mt.id AS mtype, m.model_name,
               c.login, c.pas, c.enable_pas, c.port_ssh AS cred_port_ssh
        FROM hardware h
        JOIN model m       ON m.id = h.id_model
        JOIN brand b       ON b.id = m.id_brand
        JOIN model_type mt ON mt.id = m.model_type_id
        LEFT JOIN locations l   ON l.id = h.locations_id
        LEFT JOIN credentials c ON c.id = h.cred_id
        WHERE h.active = 1
          AND h.hardware_ip IS NOT NULL AND h.hardware_ip <> ''
        ORDER BY INET_ATON(h.hardware_ip)
    """)
    devices = []
    for r in cur.fetchall():
        if r["mtype"] in SKIP_MODEL_TYPES:
            continue
        if not r["login"] or not r["pas"]:
            continue
        devices.append({
            "id": r["id"], "name": r["switch_name"] or f"hw-{r['id']}",
            "ip": r["hardware_ip"].split("/")[0],
            "brand": r["brand_name"], "model": r["model_name"],
            "locations_id": r["locations_id"],
            "loc_name": r["loc_name"] or f"loc_{r['locations_id'] or 'unknown'}",
            "login": r["login"],
            "password": decrypt_password(r["pas"], MASTER_KEY),
            "enable_pass": decrypt_password(r["enable_pas"] or "", MASTER_KEY),
            "port": r["cred_port_ssh"],
        })
    # --- Роутеры MikroTik объектов из mt_sites (салоны и площадки) ---
    # mt_sites — ГЛАВНАЯ таблица роутеров объектов.
    # mt_devices НЕ используем: туда лишь пишется собранная статистика
    # и она может быть неполной.
    # IP роутера — mt_sites.lan (серый внутренний, с маской: 10.77.34.1/24).
    # mt_sites.ip1 — БЕЛЫЙ внешний адрес объекта, для подключения не используем
    # (TODO: в будущем — опция fallback на ip1 для роутеров без серой связности;
    #  есть роутеры, у которых только серый адрес — для них lan единственный путь).
    # Креды — mt_sites.cred_id,
    # имя устройства и папка — locations.new_name (как в ansible inventory).
    cur.execute("""
        SELECT s.id, s.lan, s.locations_id,
               l.new_name AS loc_name,
               lt.name   AS type_name,
               c.login, c.pas, c.port_ssh AS cred_port_ssh
        FROM mt_sites s
        LEFT JOIN locations l      ON l.id = s.locations_id
        LEFT JOIN locations_type lt ON lt.id = l.type_id
        LEFT JOIN credentials c     ON c.id = s.cred_id
        WHERE s.lan IS NOT NULL AND s.lan <> ''
    """)
    seen_ips = {d["ip"] for d in devices}
    for r in cur.fetchall():
        ip = r["lan"].split("/")[0]
        if ip in seen_ips:        # уже есть в hardware — не дублируем
            continue
        if not r["login"] or not r["pas"]:
            continue
        loc_name = r["loc_name"] or f"loc_{r['locations_id'] or 'unknown'}"
        devices.append({
            "id": r["id"], "name": loc_name,
            "ip": ip,
            "brand": "Mikrotik", "model": "",
            "locations_id": r["locations_id"],
            "loc_name": loc_name,
            # группа-папка из типа локации: «Салон» -> salon (как backup_location в ansible)
            "group": translit_dir(r["type_name"]) or "sites",
            "login": r["login"],
            "password": decrypt_password(r["pas"], MASTER_KEY),
            "enable_pass": "",
            "port": r["cred_port_ssh"],
        })
        seen_ips.add(ip)

    cur.close()
    conn.close()

    if only_ips:
        # диагностика: почему запрошенный IP не попал в список
        found = {d["ip"] for d in devices if d["ip"] in only_ips}
        for ip in only_ips:
            if ip not in found:
                conn2 = mysql.connector.connect(**DB)
                cur2 = conn2.cursor(dictionary=True)
                cur2.execute("""
                    SELECT h.switch_name, h.active, h.hardware_ip, h.cred_id,
                           c.login, c.pas
                    FROM hardware h
                    LEFT JOIN credentials c ON c.id = h.cred_id
                    WHERE SUBSTRING_INDEX(h.hardware_ip, '/', 1) = %s
                """, (ip,))
                row = cur2.fetchone()
                if not row:
                    # роутер объекта? ищем в mt_sites (IP в колонке lan, с маской)
                    cur2.execute("""
                        SELECT s.lan, s.cred_id, l.new_name, c.login, c.pas
                        FROM mt_sites s
                        LEFT JOIN locations l   ON l.id = s.locations_id
                        LEFT JOIN credentials c ON c.id = s.cred_id
                        WHERE SUBSTRING_INDEX(s.lan, '/', 1) = %s
                    """, (ip,))
                    srow = cur2.fetchone()
                    cur2.close()
                    conn2.close()
                    if not srow:
                        print(f"  [diag] {ip}: нет ни в hardware, ни в mt_sites (ip1)")
                    elif not srow["cred_id"]:
                        print(f"  [diag] {ip}: {srow['new_name']} (mt_sites) — не назначены учётные данные")
                    elif not srow["login"] or not srow["pas"]:
                        print(f"  [diag] {ip}: {srow['new_name']} (mt_sites) — пустой логин/пароль в кредах")
                    else:
                        print(f"  [diag] {ip}: {srow['new_name']} (mt_sites) — есть, но не попал в выборку (баг?)")
                    continue
                cur2.close()
                conn2.close()
                if not row:
                    print(f"  [diag] {ip}: нет в таблице hardware")
                elif not row["active"]:
                    print(f"  [diag] {ip}: {row['switch_name']} — active=0")
                elif not row["cred_id"]:
                    print(f"  [diag] {ip}: {row['switch_name']} — не назначены учётные данные (cred_id пуст)")
                elif not row["login"] or not row["pas"]:
                    print(f"  [diag] {ip}: {row['switch_name']} — в учётных данных пустой логин/пароль")
                else:
                    print(f"  [diag] {ip}: {row['switch_name']} — отфильтрован (тип модели в списке пропуска?)")
        devices = [d for d in devices if d["ip"] in only_ips]
    elif location:
        devices = [d for d in devices if d["loc_name"] == location]
    elif per_brand:
        seen, picked = set(), []
        for d in devices:
            if d["brand"] not in seen:
                seen.add(d["brand"])
                picked.append(d)
        devices = picked
    if limit:
        devices = devices[:limit]
    return devices

# ------------------ Сохранение ------------------

def safe_name(s):
    """Имя устройства/локации -> безопасное имя папки."""
    return re.sub(r'[^\w\-.]+', '_', str(s).strip()) or "unknown"


def save_backup(dev, res, ts):
    """Пишет файлы бэкапа и чистит старые. Возвращает список записанных файлов.

    Пути:
      hardware:  /mnt/backups/<локация>/<устройство>/
      mt_sites:  /mnt/backups/<группа (тип локации, напр. salon)>/<объект>/
                 — объект сам и есть устройство, подпапка не нужна.
    """
    brand = (dev["brand"] or "").lower()
    loc = safe_name(dev["loc_name"])
    name = safe_name(dev["name"])
    parts = [BACKUP_ROOT]
    if dev.get("group"):
        parts.append(safe_name(dev["group"]))
    parts.append(loc)
    if name != loc:          # у mt_sites имя устройства == имя объекта — не дублируем
        parts.append(name)
    dev_dir = Path(*parts)
    dev_dir.mkdir(parents=True, exist_ok=True)

    written = []
    stem = f"{safe_name(dev['name'])}_{ts}"

    # текстовый конфиг: MikroTik -> .rsc, остальные -> .cfg
    text_ext = "rsc" if "mikrotik" in brand else "cfg"
    if res.get("text"):
        p = dev_dir / f"{stem}.{text_ext}"
        p.write_text(res["text"], encoding="utf-8")
        written.append(p)

    # бинарник MikroTik
    if res.get("binary"):
        p = dev_dir / f"{stem}.backup"
        p.write_bytes(res["binary"])
        written.append(p)

    # ротация: удаляем файлы этого устройства старше RETENTION_DAYS
    cutoff = time.time() - RETENTION_DAYS * 86400
    for old in dev_dir.glob(f"{safe_name(dev['name'])}_*"):
        try:
            if old.is_file() and old.stat().st_mtime < cutoff:
                old.unlink()
        except OSError:
            pass

    return written

# ------------------ Запуск ------------------

def process_device(d, write, ts, idx, total):
    """Бэкап одного устройства. Возвращает None при успехе, строку ошибки при неудаче."""
    print(f"[{idx}/{total}] [{d['ip']}] {d['name']} ({d['brand']} {d['model']})")
    try:
        res = backup_device(d)
        if write:
            files = save_backup(d, res, ts)
            print(f"  записано: {', '.join(p.name for p in files)}")
        else:
            head = res["text"][:200].replace("\n", " | ")
            print(f"  ОК. Начало конфига: {head} …")
            if res.get("binary"):
                print(f"  ОК. Бинарник: {len(res['binary'])} байт")
        return None
    except Exception as e:
        err = str(e).strip() or e.__class__.__name__
        print(f"  ОШИБКА: {err}")
        return err


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--ip", nargs="+",
                    help="бэкап только указанных устройств (по внутреннему IP)")
    ap.add_argument("--all", action="store_true",
                    help="все устройства из hardware + mt_sites "
                         "(без --all/--ip/--location — по 1 устройству каждого бренда)")
    ap.add_argument("--location",
                    help="устройства одной локации (locations.new_name, напр. COD)")
    ap.add_argument("--limit", type=int, default=None,
                    help="не больше N устройств (для тестов)")
    ap.add_argument("--write", action="store_true",
                    help="боевой режим: записать файлы (иначе dry-run, только консоль)")
    ap.add_argument("--workers", type=int, default=None,
                    help="сколько устройств бэкапится одновременно (потоков); "
                         "по умолчанию — настройка workers_backup из веба "
                         f"(если её нет: {MAX_WORKERS}); 1 = строго по очереди, "
                         "5-10 = быстрее, но больше одновременных SSH-сессий; "
                         "влияет только на скорость")
    args = ap.parse_args()

    # потоки: явный --workers > настройка workers_backup (веб) > дефолт кода
    if args.workers is None:
        try:
            import collector_settings
            args.workers = collector_settings.get_int("workers_backup", MAX_WORKERS)
        except Exception:
            args.workers = MAX_WORKERS
        print(f"Потоков: {args.workers} (настройка workers_backup)")

    devices = load_devices(only_ips=args.ip, per_brand=not args.all and not args.location,
                           limit=args.limit, location=args.location)
    print(f"Устройств для бэкапа: {len(devices)}")
    if not args.write:
        print("РЕЖИМ DRY-RUN: файлы НЕ сохраняются, только консоль")
    if not devices:
        return
    print()

    ts = time.strftime("%Y%m%d%H%M%S")
    total = len(devices)
    ok = 0
    failed = []   # (name, ip, brand, причина)

    # живой прогресс в БД (только при запуске из веба; иначе no-op)
    try:
        import job_progress
        job_progress.init(total)
    except Exception:
        job_progress = None

    # статус последнего бэкапа по каждому устройству (backup_device_status;
    # миграция не применена — молча пропускаем, бэкап важнее)
    status_conn = None
    try:
        status_conn = mysql.connector.connect(**DB)
        _c = status_conn.cursor()
        _c.execute("SHOW TABLES LIKE 'backup_device_status'")
        if not _c.fetchone():
            status_conn.close()
            status_conn = None
        _c.close()
    except Exception:
        status_conn = None

    def record_status(dev, err):
        if not status_conn:
            return
        try:
            kind = 'site' if dev.get("group") else 'hw'
            ok = 1 if err is None else 0
            msg = None if err is None else str(err)[:500]
            c = status_conn.cursor()
            c.execute(
                "INSERT INTO backup_device_status "
                "(device, kind, last_attempt_at, last_ok, error) "
                "VALUES (%s,%s,%s,%s,%s) "
                "ON DUPLICATE KEY UPDATE "
                "kind=VALUES(kind), last_attempt_at=VALUES(last_attempt_at), "
                "last_ok=VALUES(last_ok), error=VALUES(error)",
                (dev["name"][:150], kind, time.strftime("%Y-%m-%d %H:%M:%S"), ok, msg))
            status_conn.commit()
            c.close()
        except Exception:
            pass

    if args.workers > 1:
        from concurrent.futures import ThreadPoolExecutor, as_completed
        with ThreadPoolExecutor(max_workers=args.workers) as ex:
            futs = {ex.submit(process_device, d, args.write, ts, i, total): d
                    for i, d in enumerate(devices, 1)}
            for f in as_completed(futs):
                err = f.result()
                if err is None:
                    ok += 1
                else:
                    d = futs[f]
                    failed.append((d["name"], d["ip"], d["brand"], err))
                record_status(futs[f], err)
                if job_progress:
                    job_progress.step(current=futs[f]["name"])
    else:
        for i, d in enumerate(devices, 1):
            err = process_device(d, args.write, ts, i, total)
            if err is None:
                ok += 1
            else:
                failed.append((d["name"], d["ip"], d["brand"], err))
            record_status(d, err)
            if job_progress:
                job_progress.step(current=d["name"])

    if status_conn:
        status_conn.close()

    # ---- Итоговый отчёт ----
    print(f"\n=== Итого: успешно {ok}, ошибок {len(failed)} из {total} ===")
    if failed:
        print("\n--- Не удалось забэкапить ---")
        for name, ip, brand, err in failed:
            print(f"  {name} ({ip}, {brand}): {err}")


if __name__ == "__main__":
    main()

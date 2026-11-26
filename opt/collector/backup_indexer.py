#!/usr/bin/env python3
"""
Индексатор MikroTik-бэкапов.

Сканирует /mnt/backups/<locations.new_name>/mikrotik/ для всех локаций с type_id=1,
синхронизирует mt_backups, кеширует diff в mt_backup_diffs,
находит и (опционально) удаляет orphan-папки.

CLI:
    python3 backup_indexer.py                       # индексация + поиск orphan
    python3 backup_indexer.py --prune --diff        # + удалить пропавшие файлы, посчитать diff
    python3 backup_indexer.py --loc=42              # только одна локация
    python3 backup_indexer.py --full                # пересчитать всё
    python3 backup_indexer.py --json                # отчёт в JSON
    python3 backup_indexer.py --prune-orphans       # dry-run удаления orphan
    python3 backup_indexer.py --prune-orphans=force # реальное удаление orphan
    python3 backup_indexer.py --log=/var/log/x.log  # писать лог в файл
"""

from pathlib import Path
import argparse
import difflib
import hashlib
import json
import os
import re
import shutil
import sys
from datetime import datetime

import mysql.connector

# ---------- Конфиг ----------
base_dir = Path(__file__).resolve().parent
# Конфиг вынесен из webroot; путь можно переопределить переменной окружения MACATLAS_CONFIG
config_path = Path(os.environ.get("MACATLAS_CONFIG", "/etc/macatlas/config.json"))

with open(config_path, "r", encoding="utf-8") as f:
    config = json.load(f)

DB = {
    "host":     config["bd"]["dblocation"],
    "user":     config["bd"]["dbuser"],
    "password": config["bd"]["dbpasswd"],
    "database": config["bd"]["dbname"],
    "charset":  "utf8mb4",
}

BACKUP_ROOT = Path(config.get("backup_root", "/mnt/backups"))

# Игнор-лист строк конфига (regex): динамические записи вроде
# address-list'ов VPN-клиентов (list=l2tp-inside) не считаются
# изменением — ни в хэше, ни в diff.
IGNORE_RE = [re.compile(p) for p in config.get("backup_ignore", [])]

def ignored(line: str) -> bool:
    return any(r.search(line) for r in IGNORE_RE)

# Папки-группы верхнего уровня = транслитерация названия типа локации
# («Салон» -> salon):  <root>/<тип>/<локация>/mikrotik
# (напр. salon/SITE_1_EXAMPLE/mikrotik)


# ---------- Хелперы ----------
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
def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    
    # Если это .rsc файл, хэшируем только реальные настройки, 
    # игнорируя строки с комментариями (в т.ч. дату генерации)
    if path.suffix.lower() == '.rsc':
        with open(path, 'r', encoding='utf-8', errors='replace') as fh:
            for line in fh:
                # Пропускаем пустые строки и комментарии (начинаются с #)
                if line.strip().startswith('#') or not line.strip():
                    continue
                # Пропускаем строки из игнор-листа (динамика)
                if ignored(line):
                    continue
                h.update(line.encode('utf-8'))
        return h.hexdigest()

    # .cfg (UBNT/TP-Link) — текстовый конфиг: хэшируем построчно с учётом
    # игнор-листа (System Up Time, SNTP time и т.п.), иначе каждый бэкап
    # свича считается «изменённым» из-за аптайма
    if path.suffix.lower() == '.cfg':
        with open(path, 'r', encoding='utf-8', errors='replace') as fh:
            for line in fh:
                # Пропускаем строки из игнор-листа (динамика)
                if ignored(line):
                    continue
                h.update(line.encode('utf-8'))
        return h.hexdigest()

    # Для остальных файлов (например, .backup) хэшируем как есть
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def db_connect() -> mysql.connector.MySQLConnection:
    return mysql.connector.connect(**DB)


def make_logger(path: str | None):
    """Возвращает функцию log(...), которая пишет и в stdout, и в файл (если задан)."""
    if not path:
        return lambda *a, **k: print(*a, **k)

    log_path = Path(path)
    log_path.parent.mkdir(parents=True, exist_ok=True)

    def _log(*args, **kwargs):
        msg = " ".join(str(a) for a in args)
        line = f"{datetime.now().strftime('%Y-%m-%d %H:%M:%S')} {msg}"
        print(line, **kwargs)
        with open(log_path, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    return _log


# ---------- Синхронизация одной локации ----------
def sync_location(conn, loc_id: int, loc_name: str,
                  *, prune: bool, full: bool, known_types: set) -> dict:
    rep = {
        "added": 0, "updated": 0, "skipped": 0, "removed": 0,
        "touched_rsc": False, "touched_backup": False, "touched_cfg": False,
        "no_folder": False,
    }

    # Структура бэкапов по локации:
    #   НОВАЯ (backup.py):  <loc>/<устройство>/            — файлы прямо в папке устройства
    #   плоская   — <loc>/mikrotik/             (старые бэкапы ansible)
    #   вложенная — <loc>/<устройство>/mikrotik (площадки: COD, office и т.д.)
    #   по типу   — <тип>/<loc>/...             (напр. Салон/SITE_1_EXAMPLE)
    base = BACKUP_ROOT / Path(loc_name).name
    scan_dirs = []
    flat = base / "mikrotik"
    if flat.is_dir():
        scan_dirs.append(flat)
    if base.is_dir():
        for sub in sorted(base.iterdir()):
            if not sub.is_dir() or sub.name == "mikrotik":
                continue
            scan_dirs.append(sub)                 # новая структура: файлы в папке устройства
            nested = sub / "mikrotik"
            if nested.is_dir():
                scan_dirs.append(nested)          # старая структура ansible
    for t in known_types:
        tbase = BACKUP_ROOT / t / Path(loc_name).name
        if tbase.is_dir():
            scan_dirs.append(tbase)
            typed = tbase / "mikrotik"
            if typed.is_dir():
                scan_dirs.append(typed)

    if not scan_dirs:
        rep["no_folder"] = True
        return rep

    # 1. Файлы на диске
    on_disk = {}
    for folder in scan_dirs:
        for entry in folder.iterdir():
            if not entry.is_file():
                continue
            ext = entry.suffix.lower().lstrip(".")
            if ext not in ("rsc", "backup", "cfg"):
                continue
            st = entry.stat()
            on_disk[str(entry)] = {
                "type":  ext,
                "size":  st.st_size,
                # без микросекунд — в БД DATETIME их не хранит,
                # иначе сравнение всегда видит "изменение"
                "mtime": datetime.fromtimestamp(st.st_mtime).replace(microsecond=0),
                "name":  entry.name,
            }

    cur = conn.cursor(dictionary=True)

    # 2. Что уже в БД
    cur.execute(
        "SELECT id, file_path, file_size, file_mtime, sha256 "
        "FROM mt_backups WHERE locations_id = %s",
        (loc_id,),
    )
    existing = {row["file_path"]: row for row in cur.fetchall()}

    # 3. Синхронизация
    for path, meta in on_disk.items():
        need_hash = (meta["type"] in ("rsc", "cfg"))
        mtime_str = meta["mtime"].strftime("%Y-%m-%d %H:%M:%S")

        if path in existing:
            e = existing[path]
            same_size  = int(e["file_size"]) == meta["size"]
            same_mtime = e["file_mtime"] == meta["mtime"]
            if same_size and same_mtime and not full:
                rep["skipped"] += 1
                continue

            h = sha256_file(Path(path)) if need_hash else None
            cur.execute(
                "UPDATE mt_backups "
                "SET file_size=%s, file_mtime=%s, sha256=%s, indexed_at=NOW() "
                "WHERE id=%s",
                (meta["size"], mtime_str, h, e["id"]),
            )
            rep["updated"] += 1
            rep["touched_" + meta["type"]] = True
        else:
            h = sha256_file(Path(path)) if need_hash else None
            # ON DUPLICATE: тот же путь может всплыть у другой локации
            # с тем же new_name (дубликаты в locations) — не падаем,
            # такой файл считаем пропущенным.
            cur.execute(
                "INSERT INTO mt_backups "
                "(locations_id, file_type, file_name, file_path, "
                " file_size, file_mtime, sha256, indexed_at) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,NOW()) "
                "ON DUPLICATE KEY UPDATE id = id",
                (loc_id, meta["type"], meta["name"], path,
                 meta["size"], mtime_str, h),
            )
            if cur.rowcount == 1:
                rep["added"] += 1
                rep["touched_" + meta["type"]] = True
            else:
                rep["skipped"] += 1

    # 4. Prune — удалить записи о пропавших файлах
    if prune:
        for path, e in existing.items():
            if path not in on_disk:
                cur.execute("DELETE FROM mt_backups WHERE id = %s", (e["id"],))
                rep["removed"] += 1
                ext = path.lower().rsplit(".", 1)[-1]
                if ext in ("rsc", "backup", "cfg"):
                    rep["touched_" + ext] = True

    cur.close()

    # 5. Пересчёт prev_id / changed_vs_prev
    if rep["touched_rsc"] or full:
        recalc_prev(conn, loc_id, "rsc")
    if rep["touched_backup"] or full:
        recalc_prev(conn, loc_id, "backup")
    if rep["touched_cfg"] or full:
        recalc_prev(conn, loc_id, "cfg")

    return rep


# ---------- Пересчёт prev_id / changed_vs_prev ----------
# Имя устройства из имени файла бэкапа:
# CORE-SW-01_20261104174711.rsc -> CORE-SW-01
_STAMP_RE = re.compile(r'_\d{14}$')

def device_key(file_name: str) -> str:
    stem = file_name.rsplit('.', 1)[0]
    return _STAMP_RE.sub('', stem)


def recalc_prev(conn, loc_id: int, ftype: str) -> None:
    cur = conn.cursor(dictionary=True)
    cur.execute(
        "SELECT id, file_name, sha256, file_size FROM mt_backups "
        "WHERE locations_id=%s AND file_type=%s "
        "ORDER BY file_mtime DESC, id DESC",
        (loc_id, ftype),
    )
    rows = cur.fetchall()
    cur.close()

    if not rows:
        return

    # В одной локации может лежать несколько устройств
    # (<loc>/<устройство>/mikrotik — офис, COD и т.п.).
    # «Предыдущий» бэкап ищем только среди копий ТОГО ЖЕ устройства,
    # иначе сравнивались конфиги разных свичей между собой.
    by_dev = {}
    for row in rows:
        by_dev.setdefault(device_key(row["file_name"]), []).append(row)

    upd = conn.cursor()
    for dev_rows in by_dev.values():
        for i, cur_row in enumerate(dev_rows):
            prev = dev_rows[i + 1] if i + 1 < len(dev_rows) else None
            prev_id = prev["id"] if prev else None

            changed = None
            # changed_vs_prev считаем только для текстовых (.rsc/.cfg) —
            # бинарный .backup отличается на килобайты всегда
            # (служебные данные), это не изменение конфига
            if prev and ftype in ("rsc", "cfg"):
                if cur_row["sha256"] and prev["sha256"]:
                    changed = 1 if cur_row["sha256"] != prev["sha256"] else 0
                else:
                    changed = 1 if int(cur_row["file_size"]) != int(prev["file_size"]) else 0

            upd.execute(
                "UPDATE mt_backups SET prev_id=%s, changed_vs_prev=%s WHERE id=%s",
                (prev_id, changed, cur_row["id"]),
            )
    upd.close()


# ---------- Diff с предыдущим ----------
def compute_missing_diffs(conn, loc_id: int, force: bool = False) -> int:
    """Считает diff для пар (prev → current), которых нет в mt_backup_diffs.
    Только для .rsc. Возвращает число новых записей."""
    cur = conn.cursor(dictionary=True)
    cur.execute(
        "SELECT b.id AS to_id, b.file_path AS to_path, b.prev_id, "
        "       b.file_type AS ftype, "
        "       p.file_path AS from_path, "
        "       b.sha256 AS to_hash, p.sha256 AS from_hash "
        "FROM mt_backups b "
        "JOIN mt_backups p ON p.id = b.prev_id "
        "WHERE b.locations_id=%s AND b.file_type IN ('rsc','cfg') AND b.prev_id IS NOT NULL",
        (loc_id,),
    )
    pairs = cur.fetchall()
    cur.close()

    if not pairs:
        return 0

    to_ids = [p["to_id"] for p in pairs]
    fmt = ",".join(["%s"] * len(to_ids))
    chk = conn.cursor()
    chk.execute(f"SELECT to_id FROM mt_backup_diffs WHERE to_id IN ({fmt})", to_ids)
    have = {row[0] for row in chk.fetchall()}
    chk.close()

    ins = conn.cursor()
    count = 0
    for p in pairs:
        to_id = p["to_id"]
        if to_id in have and not force:
            continue

        # Хэши совпадают → файлы идентичны
        if p["to_hash"] and p["from_hash"] and p["to_hash"] == p["from_hash"]:
            lines = []
            added = removed = 0
        else:
            try:
                a_lines = Path(p["from_path"]).read_text(
                    encoding="utf-8", errors="replace").splitlines()
                b_lines = Path(p["to_path"]).read_text(
                    encoding="utf-8", errors="replace").splitlines()
            except OSError:
                continue

            # Строки из игнор-листа в diff не показываем
            if IGNORE_RE:
                a_lines = [l for l in a_lines if not ignored(l)]
                b_lines = [l for l in b_lines if not ignored(l)]

            # .rsc: комментарии и пустые строки не показываем —
            # как в sha256_file(), иначе каждый экспорт «отличается»
            # служебной строкой "# 2026-11-14 10:16:23 by RouterOS ..."
            if p["ftype"] == "rsc":
                a_lines = [l for l in a_lines
                           if l.strip() and not l.strip().startswith('#')]
                b_lines = [l for l in b_lines
                           if l.strip() and not l.strip().startswith('#')]

            lines = []
            added = removed = 0
            for line in difflib.ndiff(a_lines, b_lines):
                tag, text = line[:2], line[2:]
                if tag == "  ":
                    lines.append([" ", text])
                elif tag == "- ":
                    lines.append(["-", text])
                    removed += 1
                elif tag == "+ ":
                    lines.append(["+", text])
                    added += 1
                # '? ' — подсказка, пропускаем

        ins.execute(
            "INSERT INTO mt_backup_diffs "
            "(from_id, to_id, added, removed, diff_json, created_at) "
            "VALUES (%s,%s,%s,%s,%s,NOW()) "
            "ON DUPLICATE KEY UPDATE "
            "  added=VALUES(added), removed=VALUES(removed), "
            "  diff_json=VALUES(diff_json)",
            (p["prev_id"], to_id, added, removed,
             json.dumps(lines, ensure_ascii=False)),
        )
        count += 1

    ins.close()
    return count


# ---------- Orphan-папки ----------
def find_orphan_folders(conn, backup_root: Path, known_types: set) -> list[dict]:
    """Возвращает список папок в backup_root, которых нет среди locations.new_name
    и среди типов локаций (папки-группы — не сироты)."""
    if not backup_root.is_dir():
        return []

    with conn.cursor() as cur:
        cur.execute(
            "SELECT new_name FROM locations "
            "WHERE new_name IS NOT NULL AND new_name <> ''"
        )
        known = {row[0] for row in cur.fetchall()}

    orphans = []
    for d in backup_root.iterdir():
        # папки локаций и папки-группы типов — не сироты
        if not d.is_dir() or d.name in known or d.name in known_types:
            continue

        files = 0
        total = 0
        latest = None
        for f in d.rglob("*"):
            if not f.is_file():
                continue
            files += 1
            st = f.stat()
            total += st.st_size
            m = datetime.fromtimestamp(st.st_mtime)
            if latest is None or m > latest:
                latest = m

        orphans.append({
            "name":  d.name,
            "path":  str(d),
            "files": files,
            "size":  total,
            "mtime": latest,
        })

    orphans.sort(key=lambda x: (x["mtime"] or datetime.min), reverse=True)
    return orphans


def prune_orphan_folders(orphans: list[dict], *,
                         dry_run: bool = True,
                         min_age_days: int = 7,
                         log=print) -> dict:
    """Удаляет orphan-папки старше min_age_days."""
    result = {"deleted": [], "skipped": [], "errors": []}
    now = datetime.now()

    for o in orphans:
        age_days = (now - o["mtime"]).days if o["mtime"] else 9999

        if age_days < min_age_days:
            result["skipped"].append({
                **o,
                "reason": f"age {age_days}d < {min_age_days}d (защита)",
            })
            continue

        if dry_run:
            log(f"[DRY-RUN] would delete: {o['path']} "
                f"({o['files']} files, {o['size']} bytes, age {age_days}d)")
            result["deleted"].append(o)
            continue

        try:
            shutil.rmtree(o["path"])
            log(f"[DELETED] {o['path']} "
                f"({o['files']} files, {o['size']} bytes)")
            result["deleted"].append(o)
        except Exception as e:
            result["errors"].append({**o, "error": str(e)})
            log(f"[ERROR] {o['path']}: {e}")

    return result

def print_human_report(report: dict) -> None:
    """Человекочитаемый отчёт для CLI."""
    LINE = "═" * 66
    THIN = "─" * 66

    started  = report.get("started", "")
    finished = report.get("finished", "")

    # Длительность
    duration = ""
    try:
        t1 = datetime.strptime(started,  "%Y-%m-%d %H:%M:%S")
        t2 = datetime.strptime(finished, "%Y-%m-%d %H:%M:%S")
        duration = f"{(t2 - t1).total_seconds():.1f} сек."
    except Exception:
        pass

    print()
    print(LINE)
    print(f"  ИНДЕКСАЦИЯ БЭКАПОВ MIKROTIK — {started}")
    print(LINE)

    # ── Локации ──
    total      = report.get("locations", 0)
    no_folder  = report.get("no_folder", 0)
    with_files = total - no_folder

    print()
    print("📊 ЛОКАЦИИ")
    print(f"  Всего локаций в БД:              {total:>6}")
    print(f"  С папкой бэкапов:                {with_files:>6}")
    print(f"  Без папки (ещё не бэкапились):   {no_folder:>6}")

    # ── Файлы ──
    added    = report.get("added", 0)
    updated  = report.get("updated", 0)
    skipped  = report.get("skipped", 0)
    removed  = report.get("removed", 0)
    diffs    = report.get("diffs_new", 0)

    print()
    print("📁 ФАЙЛЫ")
    print(f"  Новых проиндексировано:          {added:>6}")
    print(f"  Обновлено (изменились):          {updated:>6}")
    print(f"  Пропущено (без изменений):       {skipped:>6}")
    print(f"  Удалено (нет на диске):          {removed:>6}")

    # ── Diff ──
    print()
    print("🔍 DIFF (сравнение с предыдущим)")
    print(f"  Новых сравнений посчитано:       {diffs:>6}")
    if diffs == 0 and added > 0:
        print("  ℹ У новых файлов нет предыдущей версии — сравнивать не с чем.")
        print("    Diff появится при следующем бэкапе того же типа.")
    elif diffs == 0:
        print("  ℹ Новых сравнений нет — все пары уже закешированы ранее.")

    # ── Orphan ──
    orph_found   = report.get("orphans_found", 0)
    orph_deleted = report.get("orphans_deleted", 0)
    orph_skipped = report.get("orphans_skipped", 0)

    print()
    if orph_found == 0:
        print("🗑  ORPHAN-ПАПКИ (нет в locations)")
        print("  ✅ Не найдено — всё чисто.")
    else:
        print(f"🗑  ORPHAN-ПАПКИ — найдено {orph_found}")
        print("  (папки в BACKUP_ROOT, которых нет среди locations.new_name)")
        print()
        print(f"  {'Папка':<30} {'Файлов':>7} {'Размер':>12}  {'Последнее изменение'}")
        print(f"  {THIN}")
        for o in report.get("orphans", []):
            size_fmt = fmt_size(o.get("size", 0))
            mtime    = o.get("mtime")
            mtime_s  = mtime.strftime("%Y-%m-%d %H:%M") if mtime else "—"
            print(f"  {o['name']:<30} {o['files']:>7} {size_fmt:>12}  {mtime_s}")

        if orph_deleted or orph_skipped:
            print()
            print(f"  Удалено:  {orph_deleted}")
            print(f"  Пропущено (моложе min-age): {orph_skipped}")

    # ── Ошибки ──
    errors = report.get("errors", [])
    if errors:
        print()
        print(f"❌ ОШИБКИ ({len(errors)})")
        for err in errors:
            print(f"  • {err}")

    # ── Итог ──
    print()
    print(LINE)
    status = "Ошибок: 0" if not errors else f"Ошибок: {len(errors)}"
    print(f"  ГОТОВО за {duration}. {status}")
    print(LINE)
    print()


def fmt_size(n: int) -> str:
    """Форматирует размер в человекочитаемый вид."""
    if n < 1024:
        return f"{n} B"
    if n < 1024 * 1024:
        return f"{n / 1024:.1f} KB"
    if n < 1024 * 1024 * 1024:
        return f"{n / 1024 / 1024:.2f} MB"
    return f"{n / 1024 / 1024 / 1024:.2f} GB"











# ---------- Main ----------
def main():
    ap = argparse.ArgumentParser(description="Индексатор MikroTik-бэкапов")
    ap.add_argument("--loc",   type=int, default=None,
                    help="Только одна локация (locations.id)")
    ap.add_argument("--prune", action="store_true",
                    help="Удалять записи о несуществующих файлах")
    ap.add_argument("--diff",  action="store_true",
                    help="Считать diff для новых пар")
    ap.add_argument("--full",  action="store_true",
                    help="Пересчитать всё, игнорируя mtime/size")
    ap.add_argument("--json",  action="store_true",
                    help="Отчёт в JSON")
    ap.add_argument("--prune-orphans", nargs="?", const="dry",
                    choices=["dry", "force"], default=None,
                    help="Удалить папки, которых нет в locations. "
                         "Без значения = dry-run, force = удалить сразу")
    ap.add_argument("--orphan-min-age", type=int, default=7,
                    help="Не удалять orphan-папки моложе N дней (по умолчанию 7)")
    ap.add_argument("--log", type=str, default=None,
                    help="Файл для лога (append). По умолчанию stdout")
    args = ap.parse_args()

    log = make_logger(args.log)

    report = {
        "started":         datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "locations":       0,
        "no_folder":       0,
        "added":           0,
        "updated":         0,
        "skipped":         0,
        "removed":         0,
        "diffs_new":       0,
        "orphans_found":   0,
        "orphans_deleted": 0,
        "orphans_skipped": 0,
        "errors":          [],
        "orphans":         [],
    }

    try:
        conn = db_connect()
    except mysql.connector.Error as e:
        print(f"DB connect failed: {e}", file=sys.stderr)
        sys.exit(1)

    try:
        # --- Выборка локаций (всех типов) и типов (папки-группы) ---
        cur = conn.cursor(dictionary=True)
        cur.execute("SELECT name FROM locations_type")
        known_types = {translit_dir(row["name"]) for row in cur.fetchall()}
        known_types.discard('')
        known_types.add("sites")   # запасная группа для локаций без типа

        sql = ("SELECT id, new_name FROM locations "
               "WHERE new_name IS NOT NULL AND new_name <> ''")
        params = []
        if args.loc:
            sql += " AND id=%s"
            params.append(args.loc)
        sql += " ORDER BY id"
        cur.execute(sql, params)
        locations = cur.fetchall()
        cur.close()

        # --- Обход локаций ---
        for loc in locations:
            report["locations"] += 1
            try:
                rep = sync_location(
                    conn, loc["id"], loc["new_name"],
                    prune=args.prune, full=args.full,
                    known_types=known_types,
                )
            except Exception as e:
                report["errors"].append(
                    f"loc {loc['id']} ({loc['new_name']}): {e}")
                continue

            if rep["no_folder"]:
                report["no_folder"] += 1
            else:
                for k in ("added", "updated", "skipped", "removed"):
                    report[k] += rep[k]

            if args.diff and (rep["touched_rsc"] or rep["touched_backup"]):
                try:
                    report["diffs_new"] += compute_missing_diffs(
                        conn, loc["id"], args.full)
                except Exception as e:
                    report["errors"].append(f"diff loc {loc['id']}: {e}")

        # --- Orphan-папки ---
        orphans = find_orphan_folders(conn, BACKUP_ROOT, known_types)
        report["orphans_found"] = len(orphans)
        report["orphans"] = orphans

        if orphans:
            log(f"\n⚠ Найдено {len(orphans)} orphan-папок (нет в locations):")
            for o in orphans:
                log(f"  • {o['name']:30s}  "
                    f"{o['files']:4d} файлов  "
                    f"{o['size']:>12,d} B  "
                    f"mtime {o['mtime']}")

        if args.prune_orphans:
            dry_run = (args.prune_orphans == "dry")
            if dry_run:
                log(f"\n[DRY-RUN] Проверка --prune-orphans "
                    f"(min-age={args.orphan_min_age}d). "
                    f"Для реального удаления: --prune-orphans=force")

            r = prune_orphan_folders(
                orphans,
                dry_run=dry_run,
                min_age_days=args.orphan_min_age,
                log=log,
            )

            report["orphans_deleted"] = len(r["deleted"])
            report["orphans_skipped"] = len(r["skipped"])

            if r["skipped"]:
                log(f"\nПропущено (моложе {args.orphan_min_age} дней):")
                for s in r["skipped"]:
                    log(f"  • {s['name']}: {s['reason']}")

            if r["errors"]:
                log("\nОшибки удаления:")
                for e in r["errors"]:
                    log(f"  • {e['name']}: {e['error']}")
                for e in r["errors"]:
                    report["errors"].append(f"orphan delete {e['name']}: {e['error']}")

        conn.commit()
    finally:
        conn.close()

    report["finished"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")


    # --- Вывод ---
    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2, default=str))
    else:
        print_human_report(report)


if __name__ == "__main__":
    main()
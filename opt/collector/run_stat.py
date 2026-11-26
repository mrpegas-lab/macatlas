#!/usr/bin/env python3
"""
run_stat.py — сбор статистики с роутеров объектов (mt_sites).

Заменяет ansible-плейбук stat_mikrotik.yml: раньше ansible по inventory
вызывал `php collect-info.php --hostname=X` для каждого объекта.
Теперь то же самое напрямую, без ansible.

Логика: берём все объекты из mt_sites (lan не пуст — значит объект активен),
для каждого вызываем collect-info.php --hostname=<new_name> (он сам обновляет
mt_devices и mt_sites в БД). Параллельно в несколько потоков (как serial: 20).

Запуск:
    python3 run_stat.py                # все объекты
    python3 run_stat.py --limit 5      # первые 5 (тест)
    python3 run_stat.py --workers 10   # число потоков (по умолчанию 20)
"""
import argparse
import json
import os
import subprocess
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

import mysql.connector

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

COLLECT_INFO = "/var/www/macatlas/api/mikrotik/collect-info.php"
PHP = "php"
MAX_WORKERS = 20          # как serial: 20 в ansible-плейбуке
TIMEOUT = 120             # на один объект (php collect-info сам ждёт до ~60с)

# ------------------ Объекты ------------------

def load_sites(limit=None):
    """new_name всех объектов с заполненным lan (как inventory ansible)."""
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor()
    cur.execute("""
        SELECT l.new_name
        FROM mt_sites ms
        JOIN locations l ON l.id = ms.locations_id
        WHERE ms.lan IS NOT NULL AND ms.lan <> ''
          AND l.new_name IS NOT NULL AND l.new_name <> ''
        ORDER BY l.new_name
    """)
    names = [r[0] for r in cur.fetchall()]
    cur.close()
    conn.close()
    return names[:limit] if limit else names

# ------------------ Сбор с одного объекта ------------------

def collect_one(name, idx, total):
    """Запуск collect-info.php для объекта. Возвращает (name, ошибка|None)."""
    try:
        p = subprocess.run(
            [PHP, COLLECT_INFO, f"--hostname={name}"],
            capture_output=True, text=True, timeout=TIMEOUT,
        )
        out = (p.stdout or "").strip()
        # collect-info отвечает JSON: {"success": true|false, "error": ...}
        try:
            res = json.loads(out) if out else {}
        except json.JSONDecodeError:
            res = {"success": False, "error": f"не-JSON ответ: {out[:120]}"}
        if res.get("success"):
            return name, None
        return name, res.get("error") or f"exit={p.returncode}"
    except subprocess.TimeoutExpired:
        return name, f"таймаут {TIMEOUT}с"
    except Exception as e:
        return name, str(e)

# ------------------ Запуск ------------------

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=None, help="не больше N объектов (тест)")
    ap.add_argument("--workers", type=int, default=None,
                    help=f"потоков; по умолчанию — настройка workers_run_stat "
                         f"из веба (если её нет: {MAX_WORKERS})")
    args = ap.parse_args()

    # потоки: явный --workers > настройка workers_run_stat (веб) > дефолт кода
    if args.workers is None:
        try:
            import collector_settings
            args.workers = collector_settings.get_int("workers_run_stat", MAX_WORKERS)
        except Exception:
            args.workers = MAX_WORKERS

    names = load_sites(limit=args.limit)
    total = len(names)
    print(f"=== run_stat started at {time.strftime('%Y-%m-%d %H:%M:%S')} ===")
    print(f"Объектов для сбора: {total} (потоков: {args.workers})")
    if not total:
        return

    t_start = time.time()
    ok = 0
    failed = []

    # живой прогресс в БД (только при запуске из веба; иначе no-op)
    try:
        import job_progress
        job_progress.init(total)
    except Exception:
        job_progress = None

    with ThreadPoolExecutor(max_workers=args.workers) as ex:
        futs = {ex.submit(collect_one, n, i, total): n
                for i, n in enumerate(names, 1)}
        done = 0
        for f in as_completed(futs):
            done += 1
            name, err = f.result()
            if err is None:
                ok += 1
            else:
                failed.append((name, err))
            if job_progress:
                job_progress.step(current=name)
            if done % 25 == 0 or done == total:
                print(f"  прогресс: {done}/{total} (ок: {ok}, ошибок: {len(failed)})")

    dt = time.time() - t_start
    print(f"\n=== Итого за {dt:.0f}s: успешно {ok}, ошибок {len(failed)} из {total} ===")
    if failed:
        print("\n--- Не удалось собрать ---")
        for name, err in failed:
            print(f"  {name}: {err}")


if __name__ == "__main__":
    main()

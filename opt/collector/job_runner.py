#!/usr/bin/env python3
"""
Диспетчер ручных запусков задач MacAtlas («Запустить сейчас» в настройках).

Веб кладёт заявку в job_run_requests (status='pending'), этот скрипт
(каждую минуту из cron) забирает заявки и выполняет команды из collector_jobs.
Результат (код возврата, вывод, время) пишется обратно в job_run_requests.

Ручной запуск выполняется даже если задача выключена флагом —
это осознанное действие администратора.

Запуск по cron каждую минуту:
    * * * * * /usr/bin/python3 /opt/collector/job_runner.py >> /var/log/macatlas/job_runner.log 2>&1
"""
import fcntl
import json
import os
import re
import subprocess
import sys
from datetime import datetime
from pathlib import Path

import mysql.connector

TIMEOUT_SEC = 1800      # максимум 30 минут на задачу
MAX_OUTPUT  = 60000     # обрезаем вывод, БАЙТ (кириллица в UTF-8 = 2 байта/символ,
                        # обрезка по символам переполняла TEXT-колонку: «Data too long»)
BATCH       = 3         # заявок за один прогон (выполняются последовательно)

config_path = Path(os.environ.get("MACATLAS_CONFIG", "/etc/macatlas/config.json"))
with open(config_path, "r", encoding="utf-8") as f:
    config = json.load(f)

DB = {
    "host":     config["bd"]["dblocation"],
    "user":     config["bd"]["dbuser"],
    "password": config["bd"]["dbpasswd"],
    "database": config["bd"]["dbname"],
}


def main():
    # защита от параллельного запуска (cron мог догнать предыдущий прогон)
    lock = open("/tmp/macatlas_job_runner.lock", "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return

    conn = mysql.connector.connect(**DB)
    cur = conn.cursor(dictionary=True)

    # есть ли progress-колонки (миграция 2026-11-15-job-progress.sql)?
    # работаем и без них: прогресс просто не сбрасываем/не пишем
    cur.execute(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_run_requests' "
        "AND COLUMN_NAME = 'progress_total'")
    has_progress = bool(cur.fetchone()["c"])

    # Сторожок: заявки, висящие в 'running' дольше TIMEOUT_SEC —
    # исполнитель погиб, не обновив статус (OOM, сбой). Закрываем их,
    # иначе кнопка «Запустить» в вебе остаётся заблокированной навсегда.
    try:
        cur.execute(
            "UPDATE job_run_requests SET status='failed', finished_at=%s, "
            "output=CONCAT(COALESCE(output,''), "
            "'\n[сторожок] процесс пропал, не обновив статус — помечено failed') "
            "WHERE status='running' AND started_at < %s",
            (datetime.now(),
             datetime.fromtimestamp(datetime.now().timestamp() - TIMEOUT_SEC)))
        if cur.rowcount:
            conn.commit()
            print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] сторожок: закрыто "
                  f"зависших заявок: {cur.rowcount}")
    except Exception as e:
        conn.rollback()
        print(f"сторожок: {e}")

    # Ретенция истории ручных запусков: удаляем завершённые заявки
    # старше 30 дней, но оставляем минимум 5 последних на каждую задачу —
    # страница настроек показывает последний запуск, история не должна
    # обнуляться. Растёт таблица медленно, чистка — чтобы не копился мусор.
    # (выбор «5 последних на задачу» делаем в Python: надёжнее, чем
    #  хитрые подзапросы с LIMIT в DELETE на MariaDB)
    try:
        cur.execute(
            "SELECT job_key, id FROM job_run_requests "
            "WHERE status IN ('done','failed') ORDER BY job_key, id DESC")
        rows = cur.fetchall()
        keep, per_job = set(), {}
        for row in rows:
            n = per_job.get(row["job_key"], 0)
            if n < 5:
                keep.add(row["id"])
                per_job[row["job_key"]] = n + 1
        cur.execute(
            "SELECT id FROM job_run_requests "
            "WHERE status IN ('done','failed') AND finished_at < %s",
            (datetime.fromtimestamp(datetime.now().timestamp() - 30 * 86400),))
        old_ids = [r["id"] for r in cur.fetchall() if r["id"] not in keep]
        if old_ids:
            cur.execute(
                f"DELETE FROM job_run_requests WHERE id IN "
                f"({','.join(str(i) for i in old_ids)})")
            conn.commit()
            print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] ретенция: удалено "
                  f"старых заявок: {len(old_ids)}")
    except Exception as e:
        conn.rollback()
        print(f"ретенция: {e}")

    # расписания из веба (если просили перегенерировать crontab) —
    # выполняем ВСЕГДА, даже когда очередь ручных запусков пуста
    try:
        apply_crontab(conn)
    except Exception as e:
        print(f"apply_crontab: {e}")

    cur.execute(
        "SELECT * FROM job_run_requests WHERE status = 'pending' ORDER BY id LIMIT %s",
        (BATCH,))
    requests = cur.fetchall()
    if not requests:
        cur.close(); conn.close()
        return

    cur.execute("SELECT job_key, command FROM collector_jobs")
    jobs = {r["job_key"]: r["command"] for r in cur.fetchall()}

    for req in requests:
        now = datetime.now()
        if has_progress:
            cur.execute(
                "UPDATE job_run_requests SET status='running', started_at=%s, "
                "progress_total=NULL, progress_done=NULL, progress_current=NULL, "
                "progress_at=NULL WHERE id=%s",
                (now, req["id"]))
        else:
            cur.execute(
                "UPDATE job_run_requests SET status='running', started_at=%s "
                "WHERE id=%s",
                (now, req["id"]))
        conn.commit()

        command = jobs.get(req["job_key"])
        if not command:
            cur.execute(
                "UPDATE job_run_requests SET status='failed', finished_at=%s, "
                "exit_code=NULL, output=%s WHERE id=%s",
                (datetime.now(), f"Задача '{req['job_key']}' не найдена в collector_jobs",
                 req["id"]))
            conn.commit()
            continue

        # параметры ручного запуска (2026-11-17): веб кладёт напр. "--ip 10.77.34.1"
        # для разового бэкапа одного устройства. Дописываем к команде из cron-задачи.
        # В shell уходит только белый список символов — защита от инъекций.
        params = (req.get("params") or "").strip()
        if params:
            if re.fullmatch(r"[A-Za-z0-9 ._\-/:=]+", params):
                command = f"{command} {params}"
            else:
                cur.execute(
                    "UPDATE job_run_requests SET status='failed', finished_at=%s, "
                    "exit_code=NULL, output=%s WHERE id=%s",
                    (datetime.now(), f"Отклонены недопустимые params: {params!r}",
                     req["id"]))
                conn.commit()
                continue

        print(f"[{now:%Y-%m-%d %H:%M:%S}] Запуск '{req['job_key']}' "
              f"(заявка #{req['id']}, от {req['requested_by']}): {command}")
        try:
            # передаём исполнителю id заявки — по нему он пишет живой прогресс
            # (job_progress.py); без переменной прогресс просто не пишется
            env = dict(os.environ, MACATLAS_JOB_REQ_ID=str(req["id"]))
            r = subprocess.run(command, shell=True, capture_output=True, text=True,
                               timeout=TIMEOUT_SEC, env=env)
            # обрезаем ПО БАЙТАМ, а не по символам: кириллица в UTF-8 — 2 байта,
            # обрезка по символам переполняла колонку output (TEXT, 65535 байт)
            output = (r.stdout + r.stderr).strip()
            output = output.encode("utf-8", errors="replace")[-MAX_OUTPUT:] \
                           .decode("utf-8", errors="ignore")
            code = r.returncode
        except subprocess.TimeoutExpired:
            output = f"Прервано по таймауту {TIMEOUT_SEC} сек"
            code = None
        except Exception as e:
            output = f"Ошибка запуска: {e}"
            code = None

        status = "done" if code == 0 else "failed"
        # пока задача выполнялась (долгие backup — 10-20 мин), MariaDB могла
        # закрыть простаивающее соединение (wait_timeout) — «gone away».
        # Переподключаемся перед записью финального статуса.
        try:
            conn.ping(reconnect=True, attempts=3, delay=2)
        except Exception:
            conn = mysql.connector.connect(**DB)
        cur = conn.cursor(dictionary=True)
        cur.execute(
            "UPDATE job_run_requests SET status=%s, finished_at=%s, exit_code=%s, output=%s "
            "WHERE id=%s",
            (status, datetime.now(), code, output, req["id"]))
        conn.commit()

        # вывод ручного запуска — всегда в лог-файл задачи
        # (редирект >> в cron-строке на ручной запуск не действует)
        try:
            log_path = f"/var/log/macatlas/{req['job_key']}.log"
            with open(log_path, "a", encoding="utf-8") as lf:
                lf.write(f"\n[{now:%Y-%m-%d %H:%M:%S}] Ручной запуск "
                         f"(заявка #{req['id']}, от {req['requested_by']})\n")
                lf.write(output + "\n")
                lf.write(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] Завершено: {status}, "
                         f"exit={code}\n")
        except Exception as e:
            print(f"не удалось записать лог {req['job_key']}: {e}")

        print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] Заявка #{req['id']}: "
              f"{status}, exit={code}")

    cur.close()
    conn.close()


# ---------- Применение расписаний из БД в crontab ----------

CRON_BEGIN = "# --- MacAtlas jobs BEGIN ---"
CRON_END   = "# --- MacAtlas jobs END ---"


def apply_crontab(conn):
    """Если app_settings.crontab_dirty = 1 — пересобирает блок задач MacAtlas
    в crontab из collector_jobs. Чужие строки crontab не трогаем,
    старые строки MacAtlas (вне блока, с нашими путями) убираем."""
    cur = conn.cursor(dictionary=True)
    cur.execute("SELECT value FROM app_settings WHERE name = 'crontab_dirty'")
    row = cur.fetchone()
    if not row or str(row["value"]).strip() != "1":
        cur.close()
        return

    cur.execute("SELECT * FROM collector_jobs WHERE cron <> '' ORDER BY job_key")
    jobs = cur.fetchall()
    if not jobs:
        # реестр пуст (миграция/сбой) — crontab НЕ трогаем, иначе потеряем задачи
        print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] apply_crontab: реестр задач пуст — пропуск")
        cur.close()
        return
    cur.execute("SELECT name, value FROM app_settings")
    flags = {r["name"]: r["value"] for r in cur.fetchall()}

    lines = [CRON_BEGIN]
    for j in jobs:
        fk = j["flag_key"] or f"job_enabled_{j['job_key']}"
        enabled = flags.get(fk, "1").strip() != "0"
        cmd = j["command"]
        # если в команде нет редиректа — пишем вывод в лог задачи,
        # иначе вывод cron-запуска теряется (почты у cron нет)
        if ">" not in cmd:
            cmd = f"{cmd} >> /var/log/macatlas/{j['job_key']}.log 2>&1"
        line = f"{j['cron']} /usr/bin/python3 /opt/collector/job_guard.py {j['job_key']} {cmd}"
        lines.append(line if enabled else f"# выключено в настройках: {line}")
    lines.append(CRON_END)
    block = "\n".join(lines)

    # текущий crontab
    r = subprocess.run(["crontab", "-l"], capture_output=True, text=True)
    current = r.stdout if r.returncode == 0 else ""

    # выкидываем старый блок и «сиротские» строки MacAtlas вне блока,
    # но НИКОГДА не трогаем строку самого диспетчера job_runner
    rest, skip = [], False
    for ln in current.splitlines():
        s = ln.strip()
        if s == CRON_BEGIN:
            skip = True
            continue
        if s == CRON_END:
            skip = False
            continue
        if skip:
            continue
        if "job_runner.py" in ln:
            rest.append(ln)          # диспетчер — священен
            continue
        if "/opt/collector/" in ln or "/opt/ansible/" in ln:
            continue
        rest.append(ln)

    # страховка: если строки диспетчера нет — возвращаем (без него всё встанет)
    if not any("job_runner.py" in ln for ln in rest):
        rest.append("* * * * * /usr/bin/python3 /opt/collector/job_runner.py "
                    ">> /var/log/macatlas/job_runner.log 2>&1")

    new_cron = "\n".join(rest).strip() + "\n\n" + block + "\n"

    r = subprocess.run(["crontab", "-"], input=new_cron,
                       capture_output=True, text=True)
    if r.returncode == 0:
        cur.execute("UPDATE app_settings SET value = '0' WHERE name = 'crontab_dirty'")
        conn.commit()
        print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] crontab обновлён из настроек "
              f"({len(jobs)} задач)")
    else:
        print(f"ОШИБКА записи crontab: {r.stderr.strip()}")
    cur.close()


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""gen_cron.py — генерация начального crontab-файла MacAtlas.

Запускается вручную по запросу (новая установка, чистка crontab).
Читает реестр задач collector_jobs из БД и формирует
/opt/ansible/macatlas.cron со строками job_guard-обёрток.

Дальнейшее управление crontab — автоматическое: job_runner.py
пересобирает блок «MacAtlas jobs» при изменениях из веба
(флаг settings.crontab_dirty). Этот файл нужен только как
стартовая точка:  crontab /opt/ansible/macatlas.cron
(осторожно: заменяет crontab целиком) или скопировать строки
в «crontab -e» вручную.
"""
import datetime
import json
import os
from pathlib import Path

import mysql.connector

ANSIBLE_DIR = Path("/opt/ansible")
CRON_PATH = ANSIBLE_DIR / "macatlas.cron"

config_path = Path(os.environ.get("MACATLAS_CONFIG", "/etc/macatlas/config.json"))
with open(config_path, "r", encoding="utf-8") as f:
    config = json.load(f)

DB = {
    "host":     config["bd"]["dblocation"],
    "user":     config["bd"]["dbuser"],
    "password": config["bd"]["dbpasswd"],
    "database": config["bd"]["dbname"],
}

# Строка диспетчера: без неё ручные запуски из веба и автообновление
# crontab не работают — ставим всегда первой.
JOB_RUNNER_LINE = ("* * * * * /usr/bin/python3 /opt/collector/job_runner.py "
                   ">> /var/log/macatlas/job_runner.log 2>&1")

# Где искать команду задачи (job_key -> путь скрипта и лог),
# если в collector_jobs.command лежит только имя .sh (бэкапы) —
# тогда команда уже полная и идёт как есть.
GUARD = "/usr/bin/python3 /opt/collector/job_guard.py"


def main():
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor(dictionary=True)
    cur.execute("SELECT job_key, name, command, schedule, cron, flag_key "
                "FROM collector_jobs ORDER BY job_key")
    jobs = cur.fetchall()

    flags = {}
    cur.execute("SELECT name, value FROM app_settings "
                "WHERE name LIKE 'job_enabled_%' OR name = 'monitoring_enabled'")
    for r in cur.fetchall():
        flags[r["name"]] = (r["value"] or "").strip()
    cur.close()
    conn.close()

    lines = [
        "# MacAtlas — начальный crontab.",
        "# Сгенерировано gen_cron.py из реестра collector_jobs "
        + datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "# Установка: crontab -e и скопировать нужные строки,",
        "# либо целиком: crontab /opt/ansible/macatlas.cron "
        "(ЗАМЕНЯЕТ crontab полностью!)",
        "# Дальше расписанием управляет веб («Настройки системы») "
        "через job_runner.py.",
        "",
        "# job_runner — диспетчер ручных запусков из веба, каждую минуту",
        JOB_RUNNER_LINE,
        "",
        "# --- MacAtlas jobs BEGIN ---",
    ]

    for j in jobs:
        flag = j["flag_key"] or f"job_enabled_{j['job_key']}"
        enabled = flags.get(flag, "1") != "0"
        cron = (j["cron"] or "").strip()
        if not cron:
            lines.append(f"# {j['name']} — cron не задан, пропущено")
            continue
        cmd = j["command"].strip()
        # бэкапы хранят в command только путь к .sh — это уже готовая команда;
        # у остальных задач command тоже полная команда — оборачиваем всё одинаково
        line = f"{cron} {GUARD} {j['job_key']} {cmd}"
        if not enabled:
            line = "# " + line
        lines.append(f"# {j['name']} ({j['schedule']})")
        lines.append(line)

    lines += ["# --- MacAtlas jobs END ---", ""]

    ANSIBLE_DIR.mkdir(parents=True, exist_ok=True)
    CRON_PATH.write_text("\n".join(lines), encoding="utf-8", newline="\n")
    print(f"Crontab-файл сохранён: {CRON_PATH}")
    print(f"Задач в файле: {len(jobs)}")


if __name__ == "__main__":
    main()

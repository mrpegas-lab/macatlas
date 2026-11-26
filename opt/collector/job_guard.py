#!/usr/bin/env python3
"""
Обёртка cron-задач MacAtlas: запускает команду, только если задача включена
(таблица app_settings; тумблеры — на странице «Настройки системы»).

Использование в crontab:
    * * * * * /usr/bin/python3 /opt/collector/job_guard.py <job_key> <команда ...>

Флаг включения: app_settings.name = job_enabled_<job_key> (для ping_monitor —
общий флаг monitoring_enabled). Значение '0' — задача выключена, пропуск.
Ключа нет / таблицы нет / ошибка БД — задача запускается (безопасный дефолт).
"""
import json
import os
import sys
from pathlib import Path

import mysql.connector


def main():
    if len(sys.argv) < 3:
        print("Использование: job_guard.py <job_key> <команда ...>")
        return 2

    job_key = sys.argv[1]
    cmd = sys.argv[2:]
    flag_key = "monitoring_enabled" if job_key == "ping_monitor" \
        else f"job_enabled_{job_key}"

    config_path = Path(os.environ.get("MACATLAS_CONFIG", "/etc/macatlas/config.json"))
    try:
        with open(config_path, "r", encoding="utf-8") as f:
            config = json.load(f)
        conn = mysql.connector.connect(
            host=config["bd"]["dblocation"],
            user=config["bd"]["dbuser"],
            password=config["bd"]["dbpasswd"],
            database=config["bd"]["dbname"],
        )
        cur = conn.cursor()
        cur.execute("SELECT value FROM app_settings WHERE name = %s", (flag_key,))
        row = cur.fetchone()
        cur.close()
        conn.close()
        if row and str(row[0]).strip() == "0":
            print(f"[job_guard] Задача '{job_key}' выключена в настройках — пропуск")
            return 0
    except Exception as e:
        # настроек нет или БД недоступна — не блокируем задачу
        print(f"[job_guard] не удалось проверить флаг '{flag_key}': {e} — запускаю")

    # запускаем через shell: в командах из collector_jobs могут быть
    # редиректы (>> лог), PATH=..., пайпы — без shell они не работают
    # (execvp исполнял первое слово как бинарь, отсюда исторические коды 2)
    os.execlp("sh", "sh", "-c", " ".join(cmd))


if __name__ == "__main__":
    sys.exit(main())

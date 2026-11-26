#!/usr/bin/env python3
"""
Чтение ручных настроек из таблицы `settings` (веб → Настройки системы).

Используется коллекторами для параметров, которые администратор
меняет из веба без правки кода (например, workers_*).

    import collector_settings
    workers = collector_settings.get_int("workers_backup", 5)

Ключа нет / БД недоступна / значение кривое — возвращается default,
скрипт работает как раньше. Настройка должна быть зарегистрирована
в mod/settings/registry.php, иначе из веба она не редактируется.
"""
import json
import os


def _conn():
    import mysql.connector
    config_path = os.environ.get("MACATLAS_CONFIG", "/etc/macatlas/config.json")
    with open(config_path, "r", encoding="utf-8") as f:
        config = json.load(f)
    return mysql.connector.connect(
        host=config["bd"]["dblocation"],
        user=config["bd"]["dbuser"],
        password=config["bd"]["dbpasswd"],
        database=config["bd"]["dbname"],
    )


def get_int(key: str, default: int) -> int:
    """Целочисленная настройка из settings; при любой проблеме — default."""
    try:
        conn = _conn()
        cur = conn.cursor()
        cur.execute("SELECT value FROM settings WHERE name = %s", (key,))
        row = cur.fetchone()
        cur.close()
        conn.close()
        if row and str(row[0]).strip() != "":
            return int(str(row[0]).strip())
    except Exception:
        pass
    return default

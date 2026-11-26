# resolve_hostnames.py
from pathlib import Path
import json
import os
import mysql.connector
from lanname import netbios_name

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

# Таймаут на один запрос (в секундах)
TIMEOUT = 1.0

def main():
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor(dictionary=True)

    # Ищем адреса с пустым hostname
    cur.execute("""
        SELECT a.id, INET_NTOA(a.ip) AS ip_str
        FROM ipam_addresses a
        WHERE (a.hostname IS NULL OR a.hostname = '')
          AND a.status != 'conflict'
        LIMIT 20
    """)
    rows = cur.fetchall()

    if not rows:
        print("Нет адресов с пустым hostname для обработки.")
        conn.close()
        return

    print(f"Найдено адресов для опроса: {len(rows)}")
    updated = 0

    # живой прогресс в БД (только при запуске из веба; иначе no-op)
    try:
        import job_progress
        job_progress.init(len(rows))
    except Exception:
        job_progress = None

    for row in rows:
        ip_str = row['ip_str']
        if job_progress:
            job_progress.step(current=ip_str)
        print(f"  Опрос {ip_str}...", end=" ")
        
        name = None
        try:
            # Пробуем получить NetBIOS-имя
            name = netbios_name(ip_str, timeout=TIMEOUT)
        except Exception:
            pass

        if name:
            print(f"найдено '{name}'")
            cur.execute(
                "UPDATE ipam_addresses SET hostname = %s, updated_at = NOW() WHERE id = %s",
                (name, row['id'])
            )
            updated += 1
        else:
            print("не отвечает")

    conn.commit()
    cur.close()
    conn.close()
    print(f"\nОбновлено записей: {updated}")

if __name__ == "__main__":
    main()
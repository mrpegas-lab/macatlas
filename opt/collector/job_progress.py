#!/usr/bin/env python3
"""
Живой прогресс ручного запуска задачи MacAtlas.

Когда задача запущена из веба («Запустить» в настройках), job_runner
передаёт ей в окружении MACATLAS_JOB_REQ_ID — id заявки в job_run_requests.
Скрипт-исполнитель сообщает ход работы:

    import job_progress
    job_progress.init(total=len(devices))     # один раз, когда известен объём
    ...
    job_progress.step(current=name)           # на каждой единице работы
    ...
    job_progress.step()                       # или просто +1 к счётчику

Если переменной окружения нет (запуск из cron или руками в консоли) —
все функции тихо ничего не делают, скрипт работает как раньше.

Прогресс читает веб (mod/settings): процент, текущее устройство
и heartbeat (progress_at) для детекции зависания.
"""
import json
import os
from datetime import datetime

_REQ_ID = int(os.environ.get("MACATLAS_JOB_REQ_ID") or 0)
_DB = None
_state = {"total": None, "done": 0}


def _db():
    """Ленивое подключение к БД (конфиг тот же, что у всех коллекторов)."""
    global _DB
    if _DB is None:
        import mysql.connector
        config_path = os.environ.get("MACATLAS_CONFIG", "/etc/macatlas/config.json")
        with open(config_path, "r", encoding="utf-8") as f:
            config = json.load(f)
        _DB = mysql.connector.connect(
            host=config["bd"]["dblocation"],
            user=config["bd"]["dbuser"],
            password=config["bd"]["dbpasswd"],
            database=config["bd"]["dbname"],
        )
    return _DB


def enabled() -> bool:
    """True, если скрипт запущен из веба и прогресс имеет смысл писать."""
    return _REQ_ID > 0


def init(total: int):
    """Сообщить общий объём работы. Вызывать один раз."""
    if not enabled():
        return
    _state["total"] = int(total)
    _write(done=0, current="")


def step(current: str = None):
    """+1 к выполненному; current — что сейчас обрабатывается (имя/IP)."""
    if not enabled():
        return
    _state["done"] += 1
    _write(done=_state["done"], current=current)


def set_done(done: int, current: str = None):
    """Установить счётчик явно (если цикл сам считает индекс)."""
    if not enabled():
        return
    _state["done"] = int(done)
    _write(done=_state["done"], current=current)


def _write(done: int, current: str):
    try:
        conn = _db()
        cur = conn.cursor()
        cur.execute(
            "UPDATE job_run_requests "
            "SET progress_total=%s, progress_done=%s, progress_current=%s, progress_at=%s "
            "WHERE id=%s AND status='running'",
            (_state["total"], done, (current or "")[:255] or None,
             datetime.now(), _REQ_ID))
        conn.commit()
        cur.close()
    except Exception:
        # прогресс — вспомогательная штука, падать из-за неё нельзя
        pass

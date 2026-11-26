#!/usr/bin/env python3
"""
MikroTik SSH runner через /import.
Файл конфига заливается по SFTP, потом выполняется /import, потом файл удаляется.

DEBUG_MODE = True  →  только залить файл, НЕ импортировать, НЕ удалять (для отладки)
DEBUG_MODE = False →  обычная работа: залить + импортировать + удалить
"""

import json
import random
import re
import socket
import string
import sys

import paramiko

# ============================================================
# ВРЕМЕННЫЙ ФЛАГ ОТЛАДКИ
# True  — залить файл, но не выполнять и не удалять
# False — обычный режим (залить → import → удалить)
# ============================================================
DEBUG_MODE =    False

ANSI_RE = re.compile(r'\x1B\[[0-9;?]*[a-zA-Z]')


def emit(obj):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False))
    sys.stdout.flush()
    sys.exit(0)


def strip_ansi(text):
    return ANSI_RE.sub('', text)


def normalize_commands(commands):
    if isinstance(commands, str):
        commands = commands.splitlines()
    out = []
    for c in commands or []:
        c = str(c).rstrip('\r\n')
        t = c.strip()
        if not t or t.startswith('#'):
            continue
        out.append(c)
    return out


def random_name():
    suffix = ''.join(random.choices(string.ascii_lowercase + string.digits, k=8))
    return f"mtbulk_{suffix}.rsc"


def run(host, user, password, commands, timeout=60):
    client = None
    sftp = None
    remote_name = random_name()
    chunks = []
    had_error = False
    err_text = None

    try:
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(
            hostname=host, port=22,
            username=user, password=password,
            timeout=10, banner_timeout=10, auth_timeout=10,
            look_for_keys=False,
            allow_agent=False,
            compress=False,
        )

        config_text = "\n".join(commands) + "\n"

        # 1. Заливаем файл по SFTP
        sftp = client.open_sftp()
        with sftp.open(remote_name, 'w') as f:
            f.write(config_text)
        sftp.close()
        sftp = None

        chunks.append(f'$ upload {remote_name} ({len(config_text)} bytes)')

        # === РЕЖИМ ОТЛАДКИ ===
        if DEBUG_MODE:
            chunks.append('')
            chunks.append('DEBUG MODE: файл залит, но НЕ импортирован и НЕ удалён.')
            chunks.append(f'Проверить на MikroTik:')
            chunks.append(f'  /file print where name~"mtbulk_"')
            chunks.append(f'  /file print file={remote_name}')
            chunks.append(f'Удалить после проверки:')
            chunks.append(f'  /file remove [find name~"mtbulk_"]')
            return {
                'success': True,
                'output': '\n'.join(chunks).rstrip(),
                'error': None
            }

        # 2. Выполняем /import
        import_cmd = f'/import file-name={remote_name} verbose=yes'
        _, stdout, stderr = client.exec_command(import_cmd, timeout=timeout)

        out_bytes = stdout.read()
        err_bytes = stderr.read()

        out_text = strip_ansi(out_bytes.decode('utf-8', errors='replace'))
        err_text_cmd = strip_ansi(err_bytes.decode('utf-8', errors='replace'))

        if out_text.strip():
            chunks.append(out_text.strip())
        if err_text_cmd.strip():
            chunks.append('[stderr] ' + err_text_cmd.strip())

        rc = stdout.channel.recv_exit_status()
        if rc != 0:
            had_error = True
            err_text = f'/import вернул код {rc}'

        # 3. Удаляем файл
        try:
            _, stdout2, _ = client.exec_command(
                f'/file remove [find name="{remote_name}"]', timeout=10
            )
            stdout2.read()
        except Exception:
            pass

        chunks.append(f'$ remove {remote_name}')

        return {
            'success': not had_error,
            'output': '\n'.join(chunks).rstrip() or '(вывод пуст)',
            'error': err_text
        }

    except paramiko.AuthenticationException:
        return {'success': False, 'error': f'SSH: неверный логин/пароль ({user}@{host})'}
    except (socket.timeout, paramiko.SSHException) as e:
        # Попытка удалить файл при ошибке — только если не в отладке
        try:
            if client and not DEBUG_MODE:
                _, stdout2, _ = client.exec_command(
                    f'/file remove [find name="{remote_name}"]', timeout=5
                )
                stdout2.read()
        except Exception:
            pass
        return {'success': False, 'error': f'SSH: {e}'}
    except Exception as e:
        return {'success': False, 'error': f'Ошибка: {e}'}
    finally:
        if sftp:
            try: sftp.close()
            except Exception: pass
        if client:
            try: client.close()
            except Exception: pass


def main():
    raw = sys.stdin.read()
    try:
        payload = json.loads(raw or '{}')
    except Exception as e:
        emit({'success': False, 'error': f'bad json: {e}'})

    host     = payload.get('host')
    user     = payload.get('user')
    password = payload.get('password', '')
    timeout  = int(payload.get('timeout', 60))
    commands = normalize_commands(payload.get('commands'))

    if not host or not user:
        emit({'success': False, 'error': 'host/user обязательны'})
    if not commands:
        emit({'success': False, 'error': 'Пустой список команд'})

    emit(run(host, user, password, commands, timeout))


if __name__ == '__main__':
    main()
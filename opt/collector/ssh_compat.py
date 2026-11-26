#!/usr/bin/env python3
"""
SSH-подключение с откатом на legacy-алгоритмы.

Старые прошивки (TP-Link TL-SG2210P/SG3210/SG3428XMP, старые EdgeSwitch
и т.п.) предлагают только устаревшие host key (ssh-rsa/ssh-dss), kex
(diffie-hellman-group1/group14-sha1) и шифры (aes*-cbc, 3des-cbc).
Современный paramiko их по умолчанию отклоняет:
    «Incompatible ssh peer (no acceptable host key)»

Здесь: сначала обычное безопасное подключение; если не договорились
об алгоритмах — повтор с расширенным списком (legacy). Legacy-список
используется ТОЛЬКО как откат для конкретного устройства, глобально
политику не ослабляем.

Использование:
    import ssh_compat
    client = ssh_compat.connect(ip, login, password, port=22)
"""
import socket
import warnings

import paramiko

# legacy-шифры (3des и т.п.) — осознанный выбор для старого железа,
# предупреждения cryptography об их deprecated-статусе только мусорят в выводе
try:
    from cryptography.utils import CryptographyDeprecationWarning
    warnings.filterwarnings("ignore", category=CryptographyDeprecationWarning)
except Exception:
    pass

# алгоритмы, которые добавляем при откате на legacy
LEGACY_KEYS    = ("ssh-rsa", "ssh-dss")
LEGACY_KEX     = ("diffie-hellman-group14-sha1",
                  "diffie-hellman-group1-sha1",
                  "diffie-hellman-group-exchange-sha1")
LEGACY_CIPHERS = ("aes128-cbc", "aes192-cbc", "aes256-cbc", "3des-cbc",
                  # очень старые прошивки TP-Link (SSH от Lysator):
                  # rijndael — это AES, по сути aes256-cbc под другим именем
                  "rijndael-cbc@lysator.liu.se")
LEGACY_MACS    = ("hmac-sha1", "hmac-sha1-96", "hmac-md5")


def _register_lysator_cipher():
    """Научить paramiko шифру rijndael-cbc@lysator.liu.se как алиасу aes256-cbc.

    Без этого при согласовании такого шифра paramiko падает
    с «unknown cipher» (Завидово, старые TP-Link)."""
    from paramiko.transport import Transport
    info = getattr(Transport, "_cipher_info", None)
    if isinstance(info, dict) and "aes256-cbc" in info \
            and "rijndael-cbc@lysator.liu.se" not in info:
        info["rijndael-cbc@lysator.liu.se"] = info["aes256-cbc"]


def connect(ip, login, password, port=22, timeout=15):
    """paramiko.SSHClient; сначала современные алгоритмы, потом legacy."""
    return connect_ex(ip, login, password, port=port, timeout=timeout)[0]


def connect_ex(ip, login, password, port=22, timeout=15, prefer_legacy=False):
    """Как connect(), но возвращает (client, mode): mode = 'modern' | 'legacy'.

    prefer_legacy=True — сначала legacy-алгоритмы (устройства, где harvest
    уже определил старый SSH: экономит одно заведомо бесполезное
    подключение). Если legacy не поднялся (напр. после обновления
    прошивки старые алгоритмы выпилили) — пробуем современный."""
    if prefer_legacy:
        try:
            return _connect_legacy(ip, login, password, port, timeout), "legacy"
        except Exception:
            pass
        return _connect_modern(ip, login, password, port, timeout), "modern"
    try:
        return _connect_modern(ip, login, password, port, timeout), "modern"
    except paramiko.ssh_exception.SSHException as e:
        msg = str(e).lower()
        # откат только при несогласовании алгоритмов; ошибки авторизации
        # и сети пробрасываем как есть
        if not any(k in msg for k in ("no acceptable", "incompatible",
                                      "negotiat", "handshake", "cipher",
                                      "no matching")):
            raise
    return _connect_legacy(ip, login, password, port, timeout), "legacy"


def _connect_modern(ip, login, password, port, timeout):
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(ip, port=port, username=login, password=password,
                   look_for_keys=False, allow_agent=False, timeout=timeout)
    return client


def _extend(sec_opts, attr, extra):
    """Дописать алгоритмы в конец списка SecurityOptions (если атрибут
    есть в этой версии paramiko)."""
    if not hasattr(sec_opts, attr):
        return
    cur = list(getattr(sec_opts, attr))
    setattr(sec_opts, attr,
            tuple(cur) + tuple(a for a in extra if a not in cur))


def _connect_legacy(ip, login, password, port, timeout):
    _register_lysator_cipher()
    sock = socket.create_connection((ip, port), timeout=timeout)
    t = paramiko.Transport(sock)
    try:
        sec = t.get_security_options()
        _extend(sec, "key_types", LEGACY_KEYS)
        _extend(sec, "kex", LEGACY_KEX)
        # рекламируем только те legacy-шифры, которые эта версия paramiko
        # реально умеет исполнять (3des-cbc и др. могли быть выпилены —
        # иначе согласуем шифр без реализации и упадём с «unknown cipher»)
        from paramiko.transport import Transport
        supported = set(getattr(Transport, "_cipher_info", {}) or {})
        _extend(sec, "ciphers",
                [c for c in LEGACY_CIPHERS if c in supported])
        _extend(sec, "digests", LEGACY_MACS)   # paramiko 2.x; в 3.x может не быть

        t.start_client(timeout=timeout)
        t.get_remote_server_key()  # host key принимаем любой — свои устройства
        t.auth_password(login, password)
        if not t.is_authenticated():
            raise paramiko.ssh_exception.AuthenticationException(
                "legacy: авторизация не удалась")
    except Exception:
        t.close()
        raise

    # оборачиваем транспорт в SSHClient, чтобы дальше код не менялся
    # (invoke_shell / exec_command работают через него же)
    client = paramiko.SSHClient()
    client._transport = t
    return client


def _register_legacy_cipher_impls():
    """Вернуть реализации шифров, выпиленные из paramiko 3 (3des-cbc и др.).

    cryptography их содержит (TripleDES/Blowfish — deprecated, но работают),
    поэтому просто регистрируем описание в Transport._cipher_info —
    как это было в paramiko 2.x. Без поддержки в cryptography — пропускаем."""
    from paramiko.transport import Transport
    info = getattr(Transport, "_cipher_info", None)
    if not isinstance(info, dict):
        return
    try:
        from cryptography.hazmat.primitives.ciphers import algorithms, modes
        # paramiko 3 выпилил ВСЕ CBC-шифры — а старые TP-Link (см. nmap:
        # aes128/192/256-cbc, blowfish, cast128, 3des) ничего другого не знают.
        # Возвращаем всё CBC-семейство, реализация — из cryptography.
        legacy_impl = {
            "aes128-cbc":   (algorithms.AES,      modes.CBC, 16, 16),
            "aes192-cbc":   (algorithms.AES,      modes.CBC, 16, 24),
            "aes256-cbc":   (algorithms.AES,      modes.CBC, 16, 32),
            "3des-cbc":     (algorithms.TripleDES, modes.CBC, 8, 24),
            "blowfish-cbc": (algorithms.Blowfish, modes.CBC, 8, 16),
            "cast128-cbc":  (algorithms.CAST5,    modes.CBC, 8, 16),
        }
        for name, (cls, mode, block, key) in legacy_impl.items():
            if name not in info:
                try:
                    info[name] = {"class": cls, "mode": mode,
                                  "block-size": block, "key-size": key}
                except Exception:
                    pass
    except Exception:
        pass


def _register_dss_shim():
    """Заглушка host key ssh-dss (DSA), удалённого из paramiko 3.

    Старые TP-Link предлагают ТОЛЬКО ssh-dss (см. nmap). Мы и так принимаем
    любой host key (свои устройства), поэтому регистрируем класс-заглушку:
    хранит блоб ключа для kex-хэша, подпись считает всегда валидной.
    Если ssh-dss в этой версии paramiko есть — ничего не делаем."""
    try:
        from paramiko.transport import Transport
        from paramiko.pkey import PKey

        key_info = getattr(Transport, "_key_info", None)
        if not isinstance(key_info, dict) or "ssh-dss" in key_info:
            return

        class _AnyDSSKey(PKey):
            name = "ssh-dss"

            def __init__(self, msg=None, data=None, raw=None):
                self.raw = raw or data or (msg.get_binary() if msg is not None else b"")

            def asbytes(self):
                return self.raw

            def get_name(self):
                return "ssh-dss"

            def get_bits(self):
                return 1024

            def can_sign(self):
                return False

            def verify_ssh_sig(self, data, msg):
                return True   # host key принимаем любой — это осознанный выбор

        key_info["ssh-dss"] = _AnyDSSKey
    except Exception:
        pass


# алиас rijndael-cbc@lysator.liu.se и старые шифры/ключи регистрируем сразу
# при импорте: «unknown cipher» вылетает уже на СОВРЕМЕННОМ подключении
# (ещё до отката), поэтому ждать _connect_legacy поздно
_register_lysator_cipher()
_register_legacy_cipher_impls()
_register_dss_shim()

#!/usr/bin/env python3
"""
Генератор ключей WireGuard.
Вызывается из PHP через shell_exec() для получения пары ключей.
"""
import json
import base64
from nacl.public import PrivateKey

def generate_wg_keys():
    priv = PrivateKey.generate()
    private_key = base64.b64encode(bytes(priv)).decode()
    public_key = base64.b64encode(bytes(priv.public_key)).decode()
    return {"private": private_key, "public": public_key}

if __name__ == "__main__":
    keys = generate_wg_keys()
    print(json.dumps(keys))
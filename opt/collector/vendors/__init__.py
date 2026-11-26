from . import tplink, ubnt, mikrotik

MODULES = [tplink, ubnt, mikrotik]


def pick(brand_name):
    if not brand_name:
        return None
    b = brand_name.strip().lower()
    for m in MODULES:
        for token in m.BRAND_MATCH:
            if token in b:
                return m
    return None
import re

PROTOCOL = "mikrotik_api"
BRAND_MATCH = ["mikrotik", "routeros", "routerboard"]


def collect(api, ip, sw, save_fixture):
    result = {
        "macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
        "port_names": {},
        "firmware_version": "", "bootloader_version": "",
        "serial_number": "", "model_name": "", "uptime": "",
        "temperature": None, "poe_budget": None, "poe_consumption": None,
        "errors": []
    }

    # ---- system resource: uptime + RouterOS version ----
    try:
        res = list(api("/system/resource/print"))
        save_fixture(ip, "resource", str(res))
        if res:
            r = res[0]
            result["uptime"]           = r.get("uptime", "")
            result["firmware_version"] = r.get("version", "")
    except Exception as e:
        result["errors"].append(f"resource: {e}")

    # ---- routerboard: bootloader + serial + model ----
    try:
        rb = list(api("/system/routerboard/print"))
        save_fixture(ip, "routerboard", str(rb))
        if rb:
            r = rb[0]
            result["bootloader_version"] = r.get("current-firmware", "")
            result["serial_number"]      = r.get("serial-number", "")
            result["model_name"]         = r.get("model", "")
    except Exception as e:
        result["errors"].append(f"routerboard: {e}")

    # ---- health: temperature + poe ----
    try:
        health_list = list(api("/system/health/print"))
        save_fixture(ip, "health", str(health_list))
        if health_list:
            _parse_health(health_list, result)   # весь список
    except Exception as e:
        result["errors"].append(f"health: {e}")

    # ---- interfaces ----
    try:
        interfaces = list(api("/interface/ethernet/print"))
        save_fixture(ip, "ethernet", str(interfaces))
    except Exception as e:
        result["errors"].append(f"interfaces: {e}")
        return result

    # Сортируем интерфейсы, чтобы присвоить сквозные номера 1, 2, 3...
    def sort_key(iface):
        name = iface.get("name", "").lower()
        nums = re.findall(r'\d+', name)
        return [int(n) for n in nums] if nums else [0]

    interfaces.sort(key=sort_key)

    iface_to_port = {}
    port_counter = 1

    for iface in interfaces:
        name = iface.get("name", "")

        if _is_logical(name):
            continue

        port_num = port_counter
        port_counter += 1

        iface_to_port[name] = port_num
        result["macs"].setdefault(port_num, [])
        result["port_names"][port_num] = name

        running  = iface.get("running",  False)
        disabled = iface.get("disabled", False)
        if isinstance(running,  str): running  = running.lower()  == "true"
        if isinstance(disabled, str): disabled = disabled.lower() == "true"

        link = "down" if (disabled or not running) else "up"
        result["port_status"][port_num] = link
        result["port_speed"][port_num]  = _parse_speed_str(iface.get("speed", "")) if link == "up" else None

    # ---- Bonding-интерфейсы ----
    # Участвуют наравне с физическими портами: VLAN (bridge port/vlan),
    # соседи и FDB привязываются к bonding-порту. Номера — продолжение
    # сквозного счётчика после ethernet, старая нумерация не сдвигается.
    try:
        bondings = list(api("/interface/bonding/print"))
        save_fixture(ip, "bonding", str(bondings))
    except Exception as e:
        result["errors"].append(f"bonding: {e}")
        bondings = []

    for b in sorted(bondings, key=sort_key):
        name = b.get("name", "")
        if not name:
            continue

        port_num = port_counter
        port_counter += 1

        iface_to_port[name] = port_num
        result["macs"].setdefault(port_num, [])
        result["port_names"][port_num] = name

        running  = b.get("running",  False)
        disabled = b.get("disabled", False)
        if isinstance(running,  str): running  = running.lower()  == "true"
        if isinstance(disabled, str): disabled = disabled.lower() == "true"

        link = "down" if (disabled or not running) else "up"
        result["port_status"][port_num] = link
        result["port_speed"][port_num]  = None   # у bonding нет поля speed

    # ---- Bridge hosts (FDB) ----
    # Роутерам MAC-таблица не нужна (порты/VLAN/LLDP собираем, FDB — нет)
    hosts = []
    if not sw.get("is_router"):
        try:
            hosts = list(api("/interface/bridge/host/print"))
            save_fixture(ip, "bridge_hosts", str(hosts))
        except Exception as e:
            result["errors"].append(f"bridge hosts: {e}")
            hosts = []

    unknown = {}
    for host in hosts:
        iface_name = host.get("interface", "")
        mac        = host.get("mac-address", "")
        local      = host.get("local", False)

        if isinstance(local, str):
            local = local.lower() == "true"
        if local or not mac:
            continue

        port_num = iface_to_port.get(iface_name)
        if port_num is None:
            unknown[iface_name] = unknown.get(iface_name, 0) + 1
            continue

        result["macs"][port_num].append(mac.upper())

    if unknown:
        print(f"  Пропущено MAC с неизвестных интерфейсов: {unknown}")

    # ---- VLAN по портам моста (bridge port pvid + bridge vlan tagged/untagged) ----
    result["vlans"] = {}
    try:
        bports = list(api("/interface/bridge/port/print"))
        bvlans = list(api("/interface/bridge/vlan/print"))
        members = list(api("/interface/list/member/print"))
        save_fixture(ip, "bridge_vlan", str(bvlans))
        result["vlans"] = _collect_vlans(bports, bvlans, members,
                                         iface_to_port, result["port_names"])
    except Exception as e:
        result["errors"].append(f"bridge vlan: {e}")

    # ---- Соседи (LLDP/CDP/MNDP) ----
    result["neighbors"] = []
    try:
        nb = list(api("/ip/neighbor/print"))
        save_fixture(ip, "neighbor", str(nb))
        result["neighbors"] = _collect_neighbors(nb, iface_to_port, result["port_names"])
    except Exception as e:
        result["errors"].append(f"neighbor: {e}")

    total = sum(len(v) for v in result["macs"].values())
    up   = sum(1 for v in result["port_status"].values() if v == "up")
    down = sum(1 for v in result["port_status"].values() if v == "down")
    spd  = sum(1 for v in result["port_speed"].values()  if v is not None)

    print(f"  MAC: {total} записей на {len(result['macs'])} портах")
    print(f"  STAT: {up} up / {down} down, speed известна на {spd} портах")

    return result


def _parse_health(health, result):
    # ---- приводим к плоскому словарю {name: value} ----
    flat = {}

    if isinstance(health, dict) and "name" in health and "value" in health:
        flat[health["name"]] = health["value"]
    elif isinstance(health, dict):
        flat = dict(health)
    elif isinstance(health, (list, tuple)):
        for item in health:
            if isinstance(item, dict) and item.get("name"):
                flat[item["name"]] = item.get("value")
    else:
        result["errors"].append(f"health: неожиданный формат {type(health)}")
        return

    # ---- только switch-temperature ----
    val = flat.get("switch-temperature")
    if val not in (None, ""):
        try:
            temp = float(val)
            # RouterOS API иногда отдаёт десятые доли
            if temp > 100:
                temp = temp / 10
            result["temperature"] = round(temp, 1)
        except (ValueError, TypeError):
            pass

    # ---- PoE ----
    for src, dst in (("poe-budget", "poe_budget"),
                     ("poe-consumption", "poe_consumption")):
        val = flat.get(src)
        if val in (None, ""):
            continue
        try:
            result[dst] = float(val)
        except (ValueError, TypeError):
            pass


def _expand_vlan_ids(spec):
    """'200,300-302' -> {200,300,301,302}"""
    out = set()
    spec = str(spec or "")  # RouterOS API может отдать int, если ID один
    for part in re.split(r"[,\s]+", spec):
        if not part:
            continue
        m = re.match(r"^(\d+)-(\d+)$", part)
        if m:
            a, b = int(m.group(1)), int(m.group(2))
            out.update(range(min(a, b), min(max(a, b), 4094) + 1))
        elif part.isdigit():
            out.add(int(part))
    return out


def _collect_vlans(bridge_ports, bridge_vlans, list_members, iface_to_port, port_names):
    # interface list -> member interfaces
    lists = {}
    for m in list_members:
        lst, iface = m.get("list", ""), m.get("interface", "")
        if lst and iface:
            lists.setdefault(lst, set()).add(iface)

    pvid = {}
    for p in bridge_ports:
        iface = p.get("interface", "")
        if iface not in iface_to_port:
            continue
        try:
            pvid[iface] = int(p.get("pvid", ""))
        except (TypeError, ValueError):
            pass

    tagged, untagged = {}, {}
    for v in bridge_vlans:
        ids = _expand_vlan_ids(v.get("vlan-ids", ""))
        if not ids:
            continue
        for key, mp in (("tagged", tagged), ("untagged", untagged)):
            for name in re.split(r"[,\s]+", str(v.get(key, "") or "")):
                if not name or name.startswith("bridge"):
                    continue
                for t in lists.get(name, {name}):
                    if t in iface_to_port:
                        mp.setdefault(t, set()).update(ids)

    out = {}
    for iface, num in iface_to_port.items():
        tg = sorted(tagged.get(iface, set()))
        pv = pvid.get(iface)
        ut = sorted(untagged.get(iface, set()) - ({pv} if pv else set()))
        if not tg and not pv and not ut:
            continue
        out[num] = {
            "port": port_names.get(num, iface),
            "port_num": num,
            "mode": "trunk" if tg else "access",
            "pvid": pv,
            "tagged": tg,
            "untagged": ut,
        }
    return out


def _collect_neighbors(rows, iface_to_port, port_names):
    out = []
    for r in rows:
        iface = r.get("interface", "")
        # API отдаёт интерфейс списком через запятую, когда discovery
        # виден сразу на нескольких (sfp28-10,bonding1,bridge1):
        # берём первый физический порт, который знаем
        num = None
        for part in str(iface).split(","):
            part = part.strip()
            if part in iface_to_port:
                num = iface_to_port[part]
                iface = part
                break
        if num is None:
            continue
        out.append({
            "port_num": num,
            "port": port_names.get(num, iface),
            "neighbor_name": r.get("identity", "") or "",
            "neighbor_port": r.get("interface-name", "") or "",
            "neighbor_mac": (r.get("mac-address", "") or "").upper() or None,
            "proto": (r.get("protocol", "") or "lldp").lower(),
        })
    return out


def _is_logical(name):
    name = name.lower()
    logical_prefixes = (
        "bridge", "vlan", "bonding", "loopback", "lo",
        "pppoe", "ppp", "l2tp", "sstp", "ovpn", "eoip",
        "gre", "ipip", "sit", "wlan", "lte", "vrrp", "cap",
    )
    return any(name.startswith(p) for p in logical_prefixes)


def _parse_speed_str(s):
    """'1Gbps' -> 1000, '100Mbps' -> 100, '10Mbps' -> 10, 'auto' -> None."""
    if not s:
        return None
    s = s.strip().lower()
    if s in ("auto", "0", ""):
        return None
    m = re.match(r'^(\d+)\s*([gm])bps?$', s)
    if not m:
        return None
    val = int(m.group(1))
    return val * 1000 if m.group(2) == "g" else val
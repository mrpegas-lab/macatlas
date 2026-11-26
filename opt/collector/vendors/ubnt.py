import re

PROTOCOL = "ssh"
BRAND_MATCH = ["ubnt", "ubiquiti", "edgeswitch", "unifi"]
SEND_ENABLE_PASS = True

MAC_CMD     = "show mac-addr-table"
STATUS_CMD  = "show interfaces status all"
SYSINFO_CMD = "show sysinfo"
POE_CMD     = "show poe status all"


def collect(session, ip, sw, ssh_exec, save_fixture):
    result = {
        "macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
        "firmware_version": "", "bootloader_version": "",
        "serial_number": "", "model_name": "", "uptime": "",
        "temperature": None, "poe_budget": None, "poe_consumption": None,
        "errors": []
    }

    # ---- show version: firmware + serial + model ----
    try:
        raw = ssh_exec(session, "show version")
        save_fixture(ip, "version", raw)
        _parse_version(raw, result)
    except Exception as e:
        result["errors"].append(f"version: {e}")

    # ---- show sysinfo: uptime ----
    try:
        raw = ssh_exec(session, SYSINFO_CMD)
        save_fixture(ip, "sysinfo", raw)
        _parse_sysinfo(raw, result)
    except Exception as e:
        result["errors"].append(f"sysinfo: {e}")

    # ---- show environment: temperature ----
    try:
        raw = ssh_exec(session, "show environment")
        save_fixture(ip, "environment", raw)
        _parse_environment(raw, result)
    except Exception as e:
        result["errors"].append(f"environment: {e}")

    # ---- MAC ----
    try:
        raw = ssh_exec(session, MAC_CMD)
        save_fixture(ip, "mac", raw)
        result["macs"] = _parse_mac(raw)
    except Exception as e:
        result["errors"].append(f"mac: {e}")

    # ---- Link status + speed ----
    try:
        raw = ssh_exec(session, STATUS_CMD)
        save_fixture(ip, "status", raw)
        result["port_status"], result["port_speed"] = _parse_status(raw)
        # имена портов для sw_port_info.port_name (подсказки на карте)
        result["port_names"] = {n: "0/" + str(n) for n in result["port_status"]}
    except Exception as e:
        result["errors"].append(f"status: {e}")

    # ---- PoE (порты + суммарное потребление) ----
    if sw.get("poe"):
        try:
            raw = ssh_exec(session, POE_CMD)
            save_fixture(ip, "poe", raw)
            result["poe"], result["poe_consumption"] = _parse_poe(raw)
            # глобального бюджета на EdgeSwitch через CLI нет — poe_budget остаётся None
        except Exception as e:
            result["errors"].append(f"poe: {e}")

    # ---- VLAN: pvid (show vlan port all) + членство (show vlan <id> по каждому) ----
    try:
        raw = ssh_exec(session, "show vlan port all")
        save_fixture(ip, "vlan_port", raw)
        pvid_map = _parse_vlan_port_all(raw)

        raw = ssh_exec(session, "show vlan")
        save_fixture(ip, "vlan", raw)
        vlan_ids = _parse_vlan_ids(raw)

        tagged, untagged = {}, {}
        for vid in vlan_ids:
            raw = ssh_exec(session, f"show vlan {vid}", wait=0.6)
            for iface, kind in _parse_vlan_membership(raw).items():
                (tagged if kind == "tagged" else untagged).setdefault(iface, []).append(vid)

        result["vlans"] = _build_vlans(pvid_map, tagged, untagged)
        print(f"  VLAN: портов с данными {len(result['vlans'])} (vlan'ов {len(vlan_ids)})")
    except Exception as e:
        result["errors"].append(f"vlan: {e}")

    # ---- LLDP-соседи ----
    try:
        raw = ssh_exec(session, "show lldp remote-device all", wait=2.0)
        save_fixture(ip, "lldp", raw)
        result["neighbors"] = _build_neighbors(_parse_lldp(raw))
        print(f"  LLDP: соседей {len(result['neighbors'])}")
    except Exception as e:
        result["errors"].append(f"lldp: {e}")

    return result


def _parse_version(raw, result):
    m = re.search(r'Software Version[.\s]+(\S+)', raw, re.I)
    if m: result["firmware_version"] = m.group(1).strip()
    m = re.search(r'Serial Number[.\s]+(\S+)', raw, re.I)
    if m: result["serial_number"] = m.group(1).strip()
    m = re.search(r'Machine Model[.\s]+(.+)', raw, re.I)
    if m: result["model_name"] = m.group(1).strip()


def _parse_sysinfo(raw, result):
    m = re.search(r'System Up Time[.\s]+(.+)', raw, re.I)
    if m: result["uptime"] = m.group(1).strip()


def _parse_environment(raw, result):
    temps = []
    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        # отсекаем заголовок и разделитель
        if line.startswith("Unit") or line.startswith("----"):
            continue
        parts = line.split()
        # ожидаем: Unit Sensor Description Temp(C) State Max_Temp(C)
        if len(parts) < 4:
            continue
        # первые два токена — числа (Unit, Sensor)
        if not (parts[0].isdigit() and parts[1].isdigit()):
            continue
        try:
            temps.append(float(parts[3]))
        except ValueError:
            continue

    if temps:
        result["temperature"] = max(temps)


def _parse_mac(raw):
    result = {}
    mac_re = re.compile(r'([0-9a-f]{2}[:-]){5}[0-9a-f]{2}', re.IGNORECASE)
    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        m = mac_re.search(line)
        if not m:
            continue
        mac = m.group(0).replace('-', ':').upper()
        parts = line.split()
        if len(parts) < 5:
            continue
        iface  = parts[2]
        status = parts[4]
        if status != "Learned":
            continue
        if '/' not in iface:
            continue
        if not iface.startswith("0/"):
            continue
        try:
            port_num = int(iface.split('/')[-1])
        except ValueError:
            continue
        result.setdefault(port_num, []).append(mac)
    return result


def _parse_status(raw):
    status = {}
    speed  = {}
    port_re = re.compile(r'^(\d+)/(\d+)$')

    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        if line.startswith("-") or line.lower().startswith("port"):
            continue

        tokens = line.split()
        if len(tokens) < 3:
            continue

        m = port_re.match(tokens[0])
        if not m:
            continue
        if m.group(1) != "0":
            continue

        port_num = int(m.group(2))

        link = "down"
        link_idx = None
        for i, tok in enumerate(tokens[1:], start=1):
            t = tok.strip(",.").lower()
            if t == "up":
                link = "up"; link_idx = i; break
            if t == "down":
                link = "down"; link_idx = i; break

        spd_val = None
        if link == "up" and link_idx is not None:
            for tok in tokens[link_idx + 2:]:
                t = tok.strip(",.")
                if t.isdigit():
                    spd_val = int(t)
                    break

        status[port_num] = link
        speed[port_num]  = spd_val

    return status, speed


def _parse_poe(raw):
    """
    EdgeSwitch 'show poe status all':
      Intf      Detection      Class   Consumed(W) Voltage(V) Current(mA) ...
      0/1       Short          Unknown        0.00       0.00        0.00 ...
      0/2       Good           Class2         1.43      52.31       27.46 ...

    Возвращает (poe_dict, total_consumed):
      poe_dict       = {port_num: 'on'|'off'}
      total_consumed = сумма Consumed(W) по всем портам, Вт
    """
    poe_dict = {}
    total_consumed = 0.0

    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        if line.startswith("-") or line.lower().startswith("intf"):
            continue

        parts = line.split()
        if len(parts) < 3:
            continue

        m = re.match(r'^(\d+)/(\d+)$', parts[0])
        if not m:
            continue
        if m.group(1) != "0":
            continue
        port_num = int(m.group(2))

        detection = parts[1].lower()
        if detection == "open" and len(parts) > 2 and parts[2].lower() == "circuit":
            detection = "open circuit"

        poe_dict[port_num] = "on" if detection == "good" else "off"

        # Consumed(W) — 4-й токен (индекс 3), может быть "N/A"
        if len(parts) >= 4:
            try:
                total_consumed += float(parts[3])
            except ValueError:
                pass

    return poe_dict, round(total_consumed, 2)

# ------------------ VLAN ------------------

def _port_num_of(iface):
    """'0/12' -> 12; '3/1' (LAG) -> None (матчинг только по имени)."""
    if not iface.startswith("0/"):
        return None
    try:
        return int(iface.split("/")[-1])
    except ValueError:
        return None


def _parse_vlan_port_all(raw):
    """show vlan port all -> {iface: pvid}"""
    out = {}
    for line in raw.splitlines():
        m = re.match(r"^(\d+/\d+)\s+(\d+)\s+\d+\s", line.strip())
        if m:
            out[m.group(1)] = int(m.group(2))
    return out


def _parse_vlan_ids(raw):
    """show vlan -> список VLAN ID"""
    out = []
    for line in raw.splitlines():
        m = re.match(r"^(\d+)\s+\S", line.strip())
        if m:
            v = int(m.group(1))
            if 1 <= v <= 4094:
                out.append(v)
    return out


def _parse_vlan_membership(raw):
    """show vlan <id> -> {iface: 'tagged'|'untagged'} (только Include)"""
    out = {}
    for line in raw.splitlines():
        m = re.match(r"^(\d+/\d+)\s+(Include|Exclude)\s+\S+\s+(Tagged|Untagged)",
                     line.strip(), re.I)
        if m and m.group(2).lower() == "include":
            out[m.group(1)] = "tagged" if m.group(3).lower() == "tagged" else "untagged"
    return out


def _build_vlans(pvid_map, tagged, untagged):
    """-> {port_num|'': {'port','mode','pvid','tagged','untagged'}}"""
    out = {}
    for iface in sorted(set(pvid_map) | set(tagged) | set(untagged)):
        tg = sorted(set(tagged.get(iface, [])))
        pv = pvid_map.get(iface)
        ut = sorted(set(untagged.get(iface, [])) - ({pv} if pv else set()))
        out[iface] = {
            "port": iface,
            "port_num": _port_num_of(iface),
            "mode": "trunk" if tg else "access",
            "pvid": pv,
            "tagged": tg,
            "untagged": ut,
        }
    return out


# ------------------ LLDP ------------------

def _parse_lldp(raw):
    """show lldp remote-device all -> список соседей"""
    out = []
    for line in raw.splitlines():
        # 0/12  18  02:00:00:00:00:55  sfp28-8             CORE-SW-15
        # port id может содержать пробелы ('ten-gigabitEthernet 1/0/52')
        m = re.match(r"^(\d+/\d+)\s+(\d+)\s+([0-9A-Fa-f:]{17})\s+(.*?)\s{2,}(\S+)\s*$",
                     line.rstrip())
        if not m:
            continue
        iface = m.group(1)
        out.append({
            "port_num": _port_num_of(iface),
            "port": iface,
            "neighbor_name": m.group(5),
            "neighbor_port": m.group(4).strip(),
            "neighbor_mac": m.group(3).upper(),
            "proto": "lldp",
        })
    return out


def _build_neighbors(rows):
    return rows

import re

PROTOCOL = "ssh"
BRAND_MATCH = ["tplink", "tp-link", "tp link"]
SEND_ENABLE_PASS = False

MAC_CMD = "show mac address-table"
STATUS_CMD = "show interface status"
POE_CMD_TEMPLATE = "show power inline information interface gigabitEthernet 1/0/1-{n}"


def collect(session, ip, sw, ssh_exec, save_fixture):
    result = {
        "macs": {}, "poe": {}, "port_status": {}, "port_speed": {},
        "firmware_version": "", "bootloader_version": "",
        "serial_number": "", "model_name": "", "uptime": "",
        "temperature": None, "poe_budget": None, "poe_consumption": None,
        "errors": []
    }

    try:
        raw = ssh_exec(session, "show system-info")
        save_fixture(ip, "system_info", raw)
        _parse_system_info(raw, result)
    except Exception as e:
        result["errors"].append(f"system_info: {e}")

    if sw.get("poe"):
        try:
            raw = ssh_exec(session, "show power inline")
            save_fixture(ip, "poe_global", raw)
            _parse_poe_global(raw, result)
        except Exception as e:
            result["errors"].append(f"poe_global: {e}")

    # ---- существующая логика MAC / status / poe без изменений ----
    try:
        raw = ssh_exec(session, MAC_CMD)
        save_fixture(ip, "mac", raw)
        result["macs"] = _parse_mac(raw)
    except Exception as e:
        result["errors"].append(f"mac: {e}")

    try:
        raw = ssh_exec(session, STATUS_CMD)
        save_fixture(ip, "status", raw)
        result["port_status"], result["port_speed"], result["port_names"] = _parse_status(raw)
    except Exception as e:
        result["errors"].append(f"status: {e}")

    if sw.get("poe"):
        try:
            n = sw.get("poe_ports") or 0
            if n:
                raw = ssh_exec(session, POE_CMD_TEMPLATE.format(n=n))
                save_fixture(ip, "poe", raw)
                result["poe"] = _parse_poe(raw)
        except Exception as e:
            result["errors"].append(f"poe: {e}")

    # ---- VLAN ----
    try:
        raw = ssh_exec(session, "show vlan", wait=2.0)
        save_fixture(ip, "vlan", raw)
        # платформа Omada: UT:/TG: маркеры в том же выводе
        result["vlans"] = _parse_vlan(raw)
        if not result["vlans"]:
            # платформа JetStream Smart: тегирование только по-портово
            try:
                raw2 = ssh_exec(session, "show interface switchport", wait=3.0)
                save_fixture(ip, "switchport", raw2)
                result["vlans"] = _parse_switchport(raw2)
            except Exception:
                result["vlans"] = {}
            if not result["vlans"]:
                # fallback: по одному порту (имена — из колонки Ports вывода show vlan)
                ifaces = sorted({pm.group(1).capitalize() + pm.group(2)
                                 for pm in _PORT_TOK.finditer(raw)},
                                key=lambda x: [int(n) for n in re.findall(r"\d+", x)])
                merged = []
                for iface in ifaces:
                    pfx = re.match(r"^[A-Za-z]+", iface).group(0)
                    long_pfx = _PREFIX_LONG.get(pfx, pfx)
                    try:
                        merged.append(ssh_exec(
                            session,
                            f"show interface switchport {long_pfx} {iface[len(pfx):]}",
                            wait=1.0))
                    except Exception:
                        pass
                if merged:
                    save_fixture(ip, "switchport_perport", "\n".join(merged))
                    result["vlans"] = _parse_switchport("\n".join(merged))
        print(f"  VLAN: портов с данными {len(result['vlans'])}")
    except Exception as e:
        result["errors"].append(f"vlan: {e}")

    # ---- LLDP-соседи ----
    try:
        raw = ssh_exec(session, "show lldp neighbor-information", wait=2.0)
        save_fixture(ip, "lldp", raw)
        result["neighbors"] = _parse_lldp(raw)
        if not result["neighbors"]:
            # платформа JetStream Smart: подробный формат per-interface
            raw2 = ssh_exec(session, "show lldp neighbor-information interface", wait=2.0)
            save_fixture(ip, "lldp_verbose", raw2)
            result["neighbors"] = _parse_lldp_verbose(raw2)
        print(f"  LLDP: соседей {len(result['neighbors'])}")
    except Exception as e:
        result["errors"].append(f"lldp: {e}")

    return result


def _parse_system_info(raw, result):
    m = re.search(r'Software Version\s*-\s*(\S+)', raw, re.I)
    if m: result["firmware_version"] = m.group(1).strip()

    m = re.search(r'Bootloader Version\s*-\s*(?:TP-Link\s+)?(.+)', raw, re.I)
    if m: result["bootloader_version"] = m.group(1).strip()

    m = re.search(r'Serial Number\s*-\s*(\S+)', raw, re.I)
    if m: result["serial_number"] = m.group(1).strip()

    m = re.search(r'Running Time\s*-\s*(.+)', raw, re.I)
    if m: result["uptime"] = m.group(1).strip()

    m = re.search(r'Hardware Version\s*-\s*(\S+)', raw, re.I)
    if m: result["model_name"] = m.group(1).strip()


def _parse_poe_global(raw, result):
    m = re.search(r'System Power Limit:\s*([\d.]+)', raw, re.I)
    if m: result["poe_budget"] = float(m.group(1))
    m = re.search(r'System Power Consumption:\s*([\d.]+)', raw, re.I)
    if m: result["poe_consumption"] = float(m.group(1))


# ---------- парсеры ----------

def _parse_mac(raw):
    result = {}
    mac_re  = re.compile(r'([0-9a-f]{2}[:-]){5}[0-9a-f]{2}', re.IGNORECASE)
    port_re = re.compile(r'\b(?:Gi|Te|Fa|Eth|Fo|Hu)\d+(?:/\d+)+\b', re.IGNORECASE)

    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        m = mac_re.search(line)
        if not m:
            continue
        mac = m.group(0).replace('-', ':').upper()
        p = port_re.search(line)
        if not p:
            continue
        port_num = _extract_port(p.group(0))
        if port_num is None:
            continue
        result.setdefault(port_num, []).append(mac)
    return result


def _parse_status(raw):
    """
    'show interface status' TP-Link (Omada / JetStream):
      Port      Status      Speed   Duplex  FlowCtrl  Active-Medium  LAG  ...
      Gi1/0/1   LinkUp      100M    Full    Disable   Copper         N/A  ...
      Gi1/0/2   LinkDown    N/A     N/A     N/A       Copper         N/A  ...

    Возвращает (status, speed, names):
      status = {port_num: 'up'|'down'}
      speed  = {port_num: Мбит/с | None}
      names  = {port_num: 'Gi1/0/1'}   — для sw_port_info.port_name
    """
    status = {}
    speed  = {}
    names  = {}
    port_re = re.compile(r'^(?:Gi|Te|Fa|Eth|Hu|Fo)?\d+(?:/\d+)+$', re.IGNORECASE)

    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        if line.startswith("----") or line.lower().startswith("port"):
            continue

        tokens = line.split()
        if len(tokens) < 3:
            continue
        if not port_re.match(tokens[0]):
            continue

        port_str   = tokens[0]
        status_str = tokens[1].strip(",.").lower()
        speed_str  = tokens[2].strip(",.")

        link = "up" if status_str in ("linkup", "up") else "down"

        m = re.search(r'(\d+)$', port_str)
        if not m:
            continue
        port_num = int(m.group(1))

        # нормализация префикса: gi1/0/1 -> Gi1/0/1
        pfx = re.match(r"^[A-Za-z]+", port_str)
        if pfx:
            port_str = pfx.group(0).capitalize() + port_str[len(pfx.group(0)):]

        status[port_num] = link
        speed[port_num]  = _parse_speed_str(speed_str) if link == "up" else None
        names[port_num]  = port_str

    return status, speed, names


def _parse_speed_str(s):
    """'100M' -> 100, '1000M' -> 1000, '10M' -> 10, '1G' -> 1000, '10G' -> 10000, 'N/A' -> None."""
    if not s:
        return None
    s = s.strip().upper()
    if s in ("N/A", "-", "AUTO", "0"):
        return None
    m = re.match(r'^(\d+)\s*([GM]?)$', s)
    if not m:
        return None
    val = int(m.group(1))
    unit = m.group(2)
    return val * 1000 if unit == "G" else val


def _parse_poe(raw):
    poe_dict = {}
    lines = raw.splitlines()
    start_idx = 0

    for i, line in enumerate(lines):
        if "Interface" in line and "Power-Status" in line:
            start_idx = i + 1
            break

    for line in lines[start_idx:]:
        line = line.strip()
        if not line or line.startswith("-----") or line.endswith("#"):
            continue
        line = re.sub(r'Class \d+', 'N/A', line)
        parts = re.sub(r'\s+', ' ', line).split(' ')
        if len(parts) < 6:
            continue
        try:
            port = int(parts[0].split('/')[-1])
        except Exception:
            continue
        status_raw = parts[-1].upper()
        if status_raw == "ON":
            status = "on"
        elif status_raw == "OFF":
            status = "off"
        else:
            status = "unknown"
        poe_dict[port] = status

    return poe_dict


def _extract_port(text):
    if not text:
        return None
    if text.isdigit():
        return int(text)
    m = re.search(r'(\d+)\s*$', text)
    return int(m.group(1)) if m else None

# ------------------ VLAN ------------------

_PORT_TOK = re.compile(r"(Gi|Te|Fa|Eth|Fo|Hu)(\d+(?:/\d+)+)(?:-(\d+))?", re.I)


def _put_port(vid, kind, iface, tagged, untagged):
    if kind == "TG":
        tagged.setdefault(iface, set()).add(vid)
    elif kind == "UT":
        untagged.setdefault(iface, set()).add(vid)


def _add_ports(text, vid, kind, tagged, untagged):
    """Порты из фрагмента строки: 'Gi1/0/1, Gi1/0/2' или 'Gi1/0/1-4'."""
    if kind not in ("UT", "TG"):
        return
    for pm in _PORT_TOK.finditer(text):
        prefix, base, rng = pm.group(1).capitalize(), pm.group(2), pm.group(3)
        if rng:
            parts = base.split("/")
            for n in range(int(parts[-1]), int(rng) + 1):
                _put_port(vid, kind, prefix + "/".join(parts[:-1] + [str(n)]),
                          tagged, untagged)
        else:
            _put_port(vid, kind, prefix + base, tagged, untagged)


def _parse_vlan(raw):
    """
    'show vlan' TP-Link Omada/JetStream:
      1     System-VLAN   active    UT: Te1/0/49, Te1/0/50
      865   NIKAMED       active    UT: Gi1/0/1, ... (переносы строк)
                                        TG: Te1/0/49, ...
    -> {iface: {'port','port_num','mode','pvid','tagged','untagged'}}
    """
    tagged, untagged = {}, {}
    vid = None
    kind = None
    for line in raw.splitlines():
        s = line.rstrip()
        st = s.strip()
        # новая VLAN-запись: начинается с номера и содержит статус
        m_new = re.match(r"^(\d+)\s+\S.*\b(active|suspend)\b", st, re.I)
        if m_new:
            vid = int(m_new.group(1))
            kind = None
        if vid is None or not (1 <= vid <= 4094):
            continue
        # режем строку по маркерам UT:/TG:
        pos = 0
        for mm in re.finditer(r"(UT|TG):", st):
            _add_ports(st[pos:mm.start()], vid, kind, tagged, untagged)
            kind = mm.group(1)
            pos = mm.end()
        _add_ports(st[pos:], vid, kind, tagged, untagged)

    out = {}
    for iface in sorted(set(tagged) | set(untagged),
                        key=lambda x: [int(n) for n in re.findall(r"\d+", x)]):
        tg = sorted(tagged.get(iface, set()))
        ut = sorted(untagged.get(iface, set()))
        # единственный untagged — это pvid/native порта
        pv = ut[0] if len(ut) == 1 else None
        ut_extra = [v for v in ut if v != pv]
        m = re.search(r"(\d+)$", iface)
        out[iface] = {
            "port": iface,
            "port_num": int(m.group(1)) if m else None,
            "mode": "trunk" if tg else "access",
            "pvid": pv,
            "tagged": tg,
            "untagged": ut_extra,
        }
    return out


# ---------- VLAN по-портово (платформа JetStream Smart: TL-SG2210MP и пр.) ----------

_PREFIX_LONG = {
    "Gi": "gigabitEthernet", "Te": "ten-gigabitEthernet",
    "Fa": "fastEthernet", "Eth": "ethernet",
    "Fo": "fortyGigabitEthernet", "Hu": "hundredGigabitEthernet",
}


def _parse_switchport(raw):
    """
    'show interface switchport [port]' (JetStream Smart):
     Port Gi1/0/1:
     PVID: 41
     ...
     Member in VLAN:
     Vlan      Name                Egress-rule
     ----      -----------         -----------
     1         System-VLAN         Untagged
     41        VIDEO               Untagged
    -> {iface: {'port','port_num','mode','pvid','tagged','untagged'}}
    Работает и на выводе одного порта, и на склейке всех портов.
    Строки с иным egress (Forbidden и пр.) игнорируются.
    """
    out = {}
    cur = None

    def _flush():
        nonlocal cur
        if cur is None:
            return
        tg = sorted(cur["tagged"])
        pv = cur["pvid"]
        ut = sorted(v for v in cur["untagged"] if v != pv)
        out[cur["port"]] = {
            "port": cur["port"],
            "port_num": cur["port_num"],
            "mode": "trunk" if tg else "access",
            "pvid": pv,
            "tagged": tg,
            "untagged": ut,
        }
        cur = None

    for line in raw.splitlines():
        s = line.rstrip()
        m = re.match(r"^\s*Port\s+((?:Gi|Te|Fa|Eth|Fo|Hu)\d+(?:/\d+)+)\s*:\s*$", s, re.I)
        if m:
            _flush()
            iface = m.group(1)
            pfx = re.match(r"^[A-Za-z]+", iface).group(0).capitalize()
            iface = pfx + iface[len(pfx):]
            pn = re.search(r"(\d+)$", iface)
            cur = {"port": iface,
                   "port_num": int(pn.group(1)) if pn else None,
                   "pvid": None, "tagged": set(), "untagged": set()}
            continue
        if cur is None:
            continue
        m = re.match(r"^\s*PVID\s*:\s*(\d+)", s, re.I)
        if m:
            cur["pvid"] = int(m.group(1))
            continue
        m = re.match(r"^\s*(\d{1,4})\s+\S+.*?\s{2,}(Tagged|Untagged)\s*$", s, re.I)
        if m:
            vid = int(m.group(1))
            if 1 <= vid <= 4094:
                if m.group(2).lower() == "tagged":
                    cur["tagged"].add(vid)
                else:
                    cur["untagged"].add(vid)
    _flush()
    return out


# ------------------ LLDP ------------------

def _parse_lldp(raw):
    """
    'show lldp neighbor-information' TP-Link:
      Gi1/0/3   10.0.0.2   02:00:00:00:00:11   WAN PORT   SIP-PHONE
      Gi1/0/7   02:00:00:00:00:22   02:00:00:00:00:22
      Te1/0/52  02:00:00:00:00:33   0/8
    """
    out = []
    port_re = re.compile(r"^(?:Gi|Te|Fa|Eth|Fo|Hu)?\d+(?:/\d+)+$", re.I)
    mac_re = re.compile(r"^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$")
    ip_re = re.compile(r"^\d+\.\d+\.\d+\.\d+$")

    for line in raw.splitlines():
        s = line.strip()
        if not s or s.startswith("-"):
            continue
        tokens = s.split()
        if len(tokens) < 3 or not port_re.match(tokens[0]):
            continue
        iface, devid, portid = tokens[0], tokens[1], tokens[2]
        name = tokens[-1] if len(tokens) >= 4 else ""
        if mac_re.match(name) or ip_re.match(name) or name.upper() == "PORT":
            name = ""
        m = re.search(r"(\d+)$", iface)
        out.append({
            "port_num": int(m.group(1)) if m else None,
            "port": iface,
            "neighbor_name": name,
            "neighbor_port": portid,
            "neighbor_mac": devid.upper() if mac_re.match(devid) else None,
            "proto": "lldp",
        })
    return out


# ---------- LLDP verbose (платформа JetStream Smart: TL-SG2210MP и пр.) ----------

_LLDPPREFIX = {
    "gigabitethernet": "Gi", "ten-gigabitethernet": "Te",
    "fastethernet": "Fa", "ethernet": "Eth",
    "fortygigabitethernet": "Fo", "hundredgigabitethernet": "Hu",
}


def _lldp_iface(long_name, num):
    """'gigabitEthernet'+'1/0/9' -> 'Gi1/0/9'"""
    return _LLDPPREFIX.get(long_name.lower(), long_name) + num


def _parse_lldp_verbose(raw):
    """
    'show lldp neighbor-information interface' (JetStream Smart):
      gigabitEthernet 1/0/9:

      Neighbor index 1:
              Chassis ID:        02:00:00:00:00:44
              Port ID type:      Interface name
              Port ID:           ten-gigabitEthernet 1/0/3
              System name:       OFFICE-SW-09
    Порядок проверки полей важен: 'Port ID' не должен цеплять 'Port ID type'.
    """
    out = []
    cur = None
    hdr_re = re.compile(
        r"^(gigabitEthernet|ten-gigabitEthernet|fastEthernet|fortyGigabitEthernet|hundredGigabitEthernet|ethernet)"
        r"\s+(\d+(?:/\d+)+)\s*:\s*$", re.I)
    idx_re = re.compile(r"^Neighbor index\s+\d+\s*:", re.I)
    mac_re = re.compile(r"^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$")

    def _flush():
        nonlocal cur
        if cur and (cur["neighbor_name"] or cur["neighbor_port"] or cur["neighbor_mac"]):
            out.append(cur)
        cur = None

    for line in raw.splitlines():
        s = line.strip()
        m = hdr_re.match(s)
        if m:
            _flush()
            iface = _lldp_iface(m.group(1), m.group(2))
            pn = re.search(r"(\d+)$", m.group(2))
            cur = {
                "port_num": int(pn.group(1)) if pn else None,
                "port": iface,
                "neighbor_name": "", "neighbor_port": "",
                "neighbor_mac": None, "proto": "lldp",
            }
            continue
        if cur is None:
            continue
        if idx_re.match(s):
            _flush_started = cur["neighbor_name"] or cur["neighbor_port"] or cur["neighbor_mac"]
            if _flush_started:
                out.append(cur)
                cur = dict(cur, neighbor_name="", neighbor_port="", neighbor_mac=None)
            continue
        mm = re.match(r"^Chassis ID\s*:\s*(\S+)", s, re.I)
        if mm and mac_re.match(mm.group(1)):
            cur["neighbor_mac"] = mm.group(1).replace("-", ":").upper()
            continue
        mm = re.match(r"^Port ID\s*:\s*(.+?)\s*$", s, re.I)
        if mm:
            cur["neighbor_port"] = mm.group(1)
            continue
        mm = re.match(r"^System name\s*:\s*(.+?)\s*$", s, re.I)
        if mm:
            cur["neighbor_name"] = mm.group(1)
            continue
    _flush()
    return out

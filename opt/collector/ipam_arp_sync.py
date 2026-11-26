# ipam_arp_sync.py
# Роутеры из hardware (model_type='Роутер'): по MikroTik API собирает
#   1) ARP + DHCP leases -> IPAM (ipam_addresses)
#   2) паспорт устройства -> hardware: firmware_version, serial_number,
#      model_name, bootloader_version, uptime, temperature
# (Портами и FDB роутеров занимается НЕ этот скрипт — свичи обрабатывает harvest.py)
from pathlib import Path
import json
import os
import mysql.connector
from base64 import b64decode
import struct
import socket
import ipaddress
from librouteros import connect
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend

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

MASTER_KEY = config.get("key", {}).get("master_key", "")

# Сколько дней держать запись в статусе 'offline' перед физическим удалением
DAYS_TO_DELETE_OFFLINE = 3

# ------------------ Утилиты ------------------
def decrypt_password(encrypted_b64, master_key_b64):
    if not encrypted_b64 or not master_key_b64:
        return ""
    try:
        key = b64decode(master_key_b64)
        data = b64decode(encrypted_b64)
    except Exception:
        return ""
    if len(data) < 17:
        return ""
    iv = data[:16]
    ciphertext = data[16:]
    try:
        cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
        decryptor = cipher.decryptor()
        padded = decryptor.update(ciphertext) + decryptor.finalize()
        pad_len = padded[-1]
        if 1 <= pad_len <= 16:
            padded = padded[:-pad_len]
        return padded.decode("utf-8", errors="ignore")
    except Exception:
        return ""

def ip_to_int(ip_str):
    try:
        return struct.unpack("!I", socket.inet_aton(ip_str))[0]
    except:
        return 0

def find_subnet(ip_str, all_subnets):
    try:
        ip_obj = ipaddress.IPv4Address(ip_str)
    except ValueError:
        return None
        
    for sub in all_subnets:
        try:
            # Создаем объект сети, например 10.220.30.0/23
            net = ipaddress.IPv4Network(f"{sub['network_str']}/{sub['prefix_len']}", strict=False)
            if ip_obj in net:
                return sub['id']
        except ValueError:
            continue
    return None

# ------------------ История изменений (ipam_history) ------------------
def log_hist(cur, addr_id, action, old_st, new_st, comment=''):
    """Событие в ipam_history. user_id = NULL (запись от системы/сборщика)."""
    try:
        cur.execute(
            "INSERT INTO ipam_history (address_id, action, old_status, new_status, user_id, comment) "
            "VALUES (%s, %s, %s, %s, NULL, %s)",
            (addr_id, action, old_st, new_st, (comment or '')[:255]))
    except Exception as e:
        # история не должна ронять синхронизацию
        print(f"  ⚠️ ipam_history: {e}")

# ------------------ Сбор с MikroTik ------------------
def get_mikrotik_data(ip, login, password, port=8728):
    api = None
    try:
        api = connect(host=ip, username=login, password=password, port=port)
        
        arp_entries = []
        for item in api('/ip/arp/print'):
            arp_entries.append(item)

        dhcp_leases = {}
        for lease in api('/ip/dhcp-server/lease/print'):
            ip_addr = lease.get("address")
            hostname = lease.get("host-name", "")
            is_dynamic = lease.get("dynamic", "true") == "true" # MikroTik возвращает строки
            
            if ip_addr:
                dhcp_leases[ip_addr] = {
                    "hostname": hostname,
                    "is_dynamic": is_dynamic
                }

        # ---- Паспорт устройства (прошивка, серийник, модель, аптайм, температура) ----
        sysinfo = {}
        try:
            res = list(api('/system/resource/print'))
            if res:
                sysinfo["uptime"]           = res[0].get("uptime", "")
                sysinfo["firmware_version"] = res[0].get("version", "")
        except Exception as e:
            print(f"  Паспорт: resource не прочитан: {e}")
        try:
            rb = list(api('/system/routerboard/print'))
            if rb:
                sysinfo["bootloader_version"] = rb[0].get("current-firmware", "")
                sysinfo["serial_number"]      = rb[0].get("serial-number", "")
                sysinfo["model_name"]         = rb[0].get("model", "")
        except Exception as e:
            print(f"  Паспорт: routerboard не прочитан: {e}")
        try:
            for h in api('/system/health/print'):
                if h.get("name") in ("temperature", "cpu-temperature"):
                    try:
                        sysinfo["temperature"] = float(h.get("value", ""))
                        break
                    except (TypeError, ValueError):
                        pass
        except Exception:
            pass  # не у всех роутеров есть датчики — это нормально

        return arp_entries, dhcp_leases, sysinfo
    except Exception as e:
        print(f"  Ошибка {ip}: {e}")
        return [], {}, {}
    finally:
        if api:
            try: api.close()
            except: pass

# ------------------ Паспорт устройства -> hardware ------------------
def save_router_sysinfo(conn, router_id, sysinfo):
    """Прошивка/серийник/модель/аптайм/температура роутера — в hardware."""
    if not sysinfo:
        return
    fields, vals = [], []
    for col in ("firmware_version", "bootloader_version", "serial_number",
                "model_name", "uptime", "temperature"):
        if col in sysinfo and sysinfo[col] not in ("", None):
            fields.append(f"{col}=%s")
            vals.append(sysinfo[col])
    if not fields:
        return
    vals.append(router_id)
    cur = conn.cursor()
    try:
        cur.execute(f"UPDATE hardware SET {', '.join(fields)} WHERE id=%s", vals)
        conn.commit()
    except Exception as e:
        print(f"  Паспорт: ошибка записи в БД: {e}")
    finally:
        cur.close()

# ------------------ Запись в БД ------------------
def sync_to_ipam(conn, router_id, arp_entries, dhcp_leases, total_seen_ips):
    cur = conn.cursor(dictionary=True)
    processed = 0
    conflicts = 0
    orphans = {} 
    
    try:
        cur.execute("SELECT id, INET_NTOA(network) AS network_str, prefix_len FROM ipam_subnets")
        all_subnets = cur.fetchall()

        for entry in arp_entries:
            ip_str = entry.get("address")
            mac = entry.get("mac-address")
            host = entry.get("host", "")
            
            if not mac or not ip_str or mac.startswith("FF:FF:FF"):
                continue
            
            mac = mac.upper()
            
            # Определяем источник IP
            ip_source = 'arp_only'
            if ip_str in dhcp_leases:
                host = dhcp_leases[ip_str]['hostname'] or host
                if dhcp_leases[ip_str]['is_dynamic']:
                    ip_source = 'dhcp_dynamic'
                else:
                    ip_source = 'dhcp_static'
                
            ip_int = ip_to_int(ip_str)
            if ip_int == 0:
                continue
            
            subnet_id = find_subnet(ip_str, all_subnets)
            
            if not subnet_id:
                # ... (логгирование сирот без изменений) ...
                continue
            
            total_seen_ips.add(ip_int)

            cur.execute("SELECT id, mac, status, source FROM ipam_addresses WHERE ip = %s AND subnet_id = %s", (ip_int, subnet_id))
            existing = cur.fetchone()
            
            if existing:
                db_mac = (existing['mac'] or "").upper()

                if not db_mac or db_mac == mac:
                    cur.execute("UPDATE ipam_addresses SET mac=%s, updated_at=NOW(), status='assigned', ip_source=%s, hostname=IF(%s!='', %s, hostname) WHERE id=%s",
                                (mac, ip_source, host, host, existing['id']))
                    # вернулся в сеть из offline/conflict — фиксируем
                    if existing['status'] != 'assigned':
                        log_hist(cur, existing['id'], 'assign', existing['status'], 'assigned',
                                 f"снова в сети ({ip_source})")
                else:
                    if existing['source'] == 'manual':
                        cur.execute("UPDATE ipam_addresses SET status='conflict', updated_at=NOW(), ip_source=%s, description=CONCAT('Найден другой MAC: ', %s) WHERE id=%s",
                                    (ip_source, mac, existing['id']))
                        conflicts += 1
                        log_hist(cur, existing['id'], 'conflict', existing['status'], 'conflict',
                                 f"MAC: {db_mac} → {mac}")
                    else:
                        cur.execute("UPDATE ipam_addresses SET mac=%s, updated_at=NOW(), status='assigned', ip_source=%s, hostname=IF(%s!='', %s, hostname) WHERE id=%s",
                                    (mac, ip_source, host, host, existing['id']))
                        # у scan/dhcp-адреса сменился MAC — «железку заменили»
                        log_hist(cur, existing['id'], 'change', 'assigned', 'assigned',
                                 f"MAC: {db_mac} → {mac}")
            else:
                cur.execute("""
                    INSERT INTO ipam_addresses (subnet_id, ip, mac, hostname, status, source, ip_source, updated_at)
                    VALUES (%s, %s, %s, %s, 'assigned', 'scan', %s, NOW())
                """, (subnet_id, ip_int, mac, host, ip_source))
                log_hist(cur, cur.lastrowid, 'assign', None, 'assigned',
                         f"auto: {ip_source}" + (f", MAC: {mac}" if mac else ""))
            
            processed += 1
            
        conn.commit()
        print(f"  Обработано: {processed}, конфликтов (ручных): {conflicts}")
        
        if orphans:
            print(f"  ⚠️ Найдены IP вне известных подсетей (пропущены):")
            for net, count in sorted(orphans.items()):
                print(f"    - {net}: {count} адресов")
        
    except Exception as e:
        conn.rollback()
        print(f"  Ошибка записи в БД: {e}")
    finally:
        cur.close()

# ------------------ Очистка и статусы ------------------
def process_missing_addresses(conn, total_seen_ips):
    """Помечает пропавшие адреса как offline и удаляет старые offline."""
    cur = conn.cursor(dictionary=True)
    try:
        if not total_seen_ips:
            print("  Список увиденных IP пуст. Защита от массового сбоя: статусы не меняем.")
            return

        placeholders = ','.join(['%s'] * len(total_seen_ips))

        # 1. Помечаем пропавшие адреса как 'offline'
        # сначала выбираем id — для истории (по строке на адрес)
        cur.execute(f"""
            SELECT id FROM ipam_addresses
            WHERE source IN ('scan', 'dhcp')
              AND status = 'assigned'
              AND ip NOT IN ({placeholders})
        """, tuple(total_seen_ips))
        offline_ids = [r['id'] for r in cur.fetchall()]

        if offline_ids:
            id_ph = ','.join(['%s'] * len(offline_ids))
            cur.execute(f"""
                UPDATE ipam_addresses
                SET status = 'offline'
                WHERE id IN ({id_ph})
            """, tuple(offline_ids))
            for addr_id in offline_ids:
                log_hist(cur, addr_id, 'release', 'assigned', 'offline', 'пропал из сети')
            print(f"  🔌 Переведено в статус 'offline': {len(offline_ids)}")

        # 2. Удаляем те, что висят в offline дольше DAYS_TO_DELETE_OFFLINE дней
        # Используем updated_at, так как при переводе в offline мы не обновляем это поле
        cur.execute(f"""
            DELETE FROM ipam_addresses 
            WHERE status = 'offline'
              AND source IN ('scan', 'dhcp')
              AND updated_at < NOW() - INTERVAL {DAYS_TO_DELETE_OFFLINE} DAY
        """)
        
        deleted_count = cur.rowcount
        if deleted_count > 0:
            print(f"  🗑️ Удалено offline-записей (прошло >{DAYS_TO_DELETE_OFFLINE} дней): {deleted_count}")
            
        conn.commit()
    except Exception as e:
        conn.rollback()
        print(f"  Ошибка обработки пропавших адресов: {e}")
    finally:
        cur.close()

# ------------------ Основная функция ------------------
def main():
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor(dictionary=True)
    
    cur.execute("""
        SELECT h.id, h.switch_name, h.hardware_ip,
               c.login, c.pas, c.port_api
        FROM hardware h
        JOIN model m ON m.id = h.id_model
        JOIN model_type t ON t.id = m.model_type_id
        LEFT JOIN credentials c ON c.id = h.cred_id
        WHERE h.active = 1 AND t.name = 'Роутер'
    """)
    
    routers = cur.fetchall()
    cur.close()
    
    if not routers:
        print("Нет активных роутеров в БД.")
        return

    print(f"Найдено роутеров: {len(routers)}")

    # живой прогресс в БД (только при запуске из веба; иначе no-op)
    try:
        import job_progress
        job_progress.init(len(routers))
    except Exception:
        job_progress = None

    total_seen_ips = set()

    for r in routers:
        ip = r["hardware_ip"]
        if job_progress:
            job_progress.step(current=r["switch_name"])
        login = r["login"]
        enc_pass = r["pas"] or ""
        port = r["port_api"] or 8728
        
        print(f"\n[{r['switch_name']}] {ip}")
        
        if not login or not enc_pass:
            print("  Пропуск: нет кредов.")
            continue
            
        password = decrypt_password(enc_pass, MASTER_KEY)
        if not password:
            print("  Пропуск: не удалось расшифровать пароль.")
            continue

        arp, dhcp, sysinfo = get_mikrotik_data(ip, login, password, port)

        # паспорт устройства сохраняем всегда, когда удалось прочитать
        if sysinfo:
            save_router_sysinfo(conn, r["id"], sysinfo)
            print(f"  Паспорт: {sysinfo.get('model_name','?')}, "
                  f"RouterOS {sysinfo.get('firmware_version','?')}, "
                  f"SN {sysinfo.get('serial_number','?')}")

        if not arp:
            print("  Нет ARP-записей.")
            continue

        sync_to_ipam(conn, r["id"], arp, dhcp, total_seen_ips)

    # Обработка тех, кого не видели
    print("\n--- Обработка пропавших адресов ---")
    process_missing_addresses(conn, total_seen_ips)

    conn.close()
    print("\nСинхронизация завершена.")

if __name__ == "__main__":
    main()
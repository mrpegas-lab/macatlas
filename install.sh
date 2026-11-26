#!/bin/bash
# ============================================================
# MacAtlas — установка на AlmaLinux/Rocky/RHEL (dnf)
# Запуск:  ./install.sh            (из корня комплекта)
# Лог:     /var/log/macatlas-install.log
# ============================================================
set -euo pipefail

KIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="/var/log/macatlas-install.log"
CRED_FILE="/root/macatlas-credentials.txt"

# ---------- Настройки (install.conf) ----------
DOMAIN="macatlas.local"
SSL="no"
DB_HOST="127.0.0.1"
DB_NAME="macatlas"
DB_USER="macatlas"
DB_PASSWORD=""
DB_IMPORT="yes"
MYSQL_ROOT_PASSWORD=""
MASTER_KEY=""
ADMIN_PASSWORD=""
CREATE_DEMO="no"
# shellcheck disable=SC1091
[ -f "$KIT_DIR/install.conf" ] && source "$KIT_DIR/install.conf"

# Локальная БД или удалённая
case "$DB_HOST" in
    127.0.0.1|localhost|::1) DB_LOCAL="yes" ;;
    *)                       DB_LOCAL="no"  ;;
esac

# Доступность БД по ходу установки (no — шаги с БД пропускаем, напомним в конце)
DB_OK="yes"

# Единая обёртка для запросов к БД macatlas:
# локально — от root через unix_socket (как раньше), удалённо — по логину/паролю
mysql_db() {
    if [ "$DB_LOCAL" = "yes" ]; then
        mysql "$DB_NAME" "$@"
    else
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" "$@"
    fi
}

# ---------- Проверки ----------
[ "$(id -u)" -eq 0 ] || { echo "Запускайте от root: sudo ./install.sh"; exit 1; }
[ -d "$KIT_DIR/var/www/macatlas" ] || { echo "Нет $KIT_DIR/var/www/macatlas — комплект неполный"; exit 1; }

mkdir -p /var/log/macatlas
touch "$LOG_FILE"

log()  { echo -e "\033[1;32m==>\033[0m $*"; echo "==> $*" >> "$LOG_FILE"; }
warn() { echo -e "\033[1;33m[!]\033[0m $*"; echo "[!] $*" >> "$LOG_FILE"; }
die()  { echo -e "\033[1;31m[x]\033[0m $*"; exit 1; }
run()  { "$@" >> "$LOG_FILE" 2>&1 || die "Ошибка: $* (см. $LOG_FILE)"; }

gen_pass() { openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 20; }

log "MacAtlas: начало установки ($(date))"
log "Комплект: $KIT_DIR"

# ---------- 1. Пакеты ----------
log "Установка пакетов (может занять несколько минут)..."
run dnf install -y epel-release
run dnf install -y nginx mariadb-server php-fpm php-cli php-mysqlnd php-mbstring php-gd php-ldap \
                   python3 python3-pip cronie rsync tar unzip openssl \
                   policycoreutils-python-utils
if [ "$SSL" = "yes" ]; then
    run dnf install -y certbot python3-certbot-nginx
fi

# ---------- 2. Python-зависимости коллектора (venv) ----------
log "Настройка Python-окружения коллектора..."
if [ -d "$KIT_DIR/opt/collector" ]; then
    run rsync -a "$KIT_DIR/opt/collector/" /opt/collector/
    [ -x /opt/collector/.venv/bin/pip ] || run python3 -m venv /opt/collector/.venv
    run /opt/collector/.venv/bin/pip install --quiet --upgrade pip
    # Зависимости коллектора — список зашит здесь (единая точка установки),
    # при добавлении пакета меняйте эту строку
    run /opt/collector/.venv/bin/pip install --quiet \
        paramiko mysql-connector-python cryptography librouteros pyyaml lanname
else
    warn "В комплекте нет opt/collector — пропускаю"
fi

# ---------- 2б. Каталоги данных ----------
# /mnt/backups — архив конфигураций (backup.py); /var/log/macatlas — логи задач
run mkdir -p /mnt/backups /var/log/macatlas

# ---------- 2в. Python-зависимости в системный интерпретатор ----------
# Задачи планировщика (collector_jobs) вызывают /usr/bin/python3 напрямую,
# поэтому пакеты нужны и в системном python (venv выше — для ручной работы).
run pip3 install --quiet --disable-pip-version-check \
    paramiko mysql-connector-python cryptography librouteros pyyaml lanname \
    || warn "pip3 (system) не смог поставить пакеты — задачи cron не запустятся"

# ---------- 3. Веб-код ----------
log "Копирование веб-кода в /var/www/macatlas..."
run rsync -a "$KIT_DIR/var/www/macatlas/" /var/www/macatlas/
find /var/www/macatlas -name ".htaccess" -delete 2>/dev/null || true
run chown -R nginx:nginx /var/www/macatlas
chmod -R 775 /var/www/macatlas/files 2>/dev/null || true
[ -d /var/www/macatlas/mod/maps/icons ] && run chown -R nginx:nginx /var/www/macatlas/mod/maps/icons

# ---------- 4. SELinux ----------
if command -v getenforce >/dev/null && [ "$(getenforce)" != "Disabled" ]; then
    log "Настройка SELinux..."
    run semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/macatlas(/.*)?"
    run restorecon -Rv /var/www/macatlas
    run setsebool -P httpd_can_network_connect 1
    run setsebool -P httpd_can_network_connect_db 1
else
    warn "SELinux выключен или отсутствует — пропускаю"
fi

# ---------- 5. MariaDB ----------
if [ "$DB_LOCAL" = "yes" ]; then
    log "Настройка локальной MariaDB..."
    run systemctl enable --now mariadb
    sleep 2

    [ -n "$DB_PASSWORD" ] || DB_PASSWORD="$(gen_pass)"
    [ -n "$MYSQL_ROOT_PASSWORD" ] || MYSQL_ROOT_PASSWORD="$(gen_pass)"

    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
    GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
    FLUSH PRIVILEGES;" >> "$LOG_FILE" 2>&1 || die "Не удалось создать БД/пользователя"

    # Пароль root (в дополнение к unix_socket, локальный вход root без пароля сохраняется)
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket OR mysql_native_password USING PASSWORD('${MYSQL_ROOT_PASSWORD}');" \
        >> "$LOG_FILE" 2>&1 || warn "Не удалось задать пароль root MariaDB (не критично)"
else
    # Удалённая БД: сам сервер MariaDB уже работает на $DB_HOST (его
    # поднимает админ того сервера, мы туда не лезем). Здесь ставим только
    # консольный клиент mysql (пакет mariadb, НЕ mariadb-server) — он нужен
    # для проверки соединения и импорта дампа.
    # ВАЖНО: нет связи с удалённой БД — НЕ падаем: веб/nginx/php настроятся,
    # а про БД честно напомним в конце установки (флаг DB_OK).
    log "Удалённая БД: $DB_HOST (база: $DB_NAME, пользователь: $DB_USER)"
    run dnf install -y mariadb
    [ -n "$DB_PASSWORD" ] || { warn "Для удалённой БД не задан DB_PASSWORD в install.conf — шаги с БД пропускаю"; DB_OK="no"; }
    if [ "$DB_OK" = "yes" ]; then
        if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1;" >> "$LOG_FILE" 2>&1; then
            log "Соединение с удалённой БД — OK"
        else
            warn "Нет соединения с БД $DB_HOST — шаги с БД пропускаю, см. напоминание в конце"
            DB_OK="no"
        fi
    fi
fi

if [ "$DB_OK" = "no" ]; then
    warn "БД недоступна — импорт дампа и настройка учётных записей ПРОПУЩЕНЫ"
elif [ "$DB_IMPORT" = "yes" ]; then
    if [ -f "$KIT_DIR/bd/macatlas.sql" ]; then
        log "Импорт базы данных (bd/macatlas.sql)..."
        mysql_db < "$KIT_DIR/bd/macatlas.sql" >> "$LOG_FILE" 2>&1 \
            || die "Ошибка импорта дампа (см. $LOG_FILE)"
    else
        warn "Нет дампа $KIT_DIR/bd/macatlas.sql — БД пустая, импортируйте вручную"
    fi
else
    log "DB_IMPORT=no — таблицы НЕ заливаю (считаем, что уже есть в базе)"
fi

# Миграции при установке НЕ применяются: bd/macatlas.sql — всегда актуальный
# полный дамп схемы. Миграции из папки migrations/ — только для обновления
# существующих установок (применяются вручную на проде).

# ---------- 5б. Планировщик MacAtlas (cron) ----------
# job_runner бежит каждую минуту: пересобирает блок задач в crontab по
# collector_jobs и выполняет ручные запуски из веб-интерфейса.
log "Настройка cron (job_runner)..."
( crontab -l 2>/dev/null | grep -v 'job_runner\.py'; \
  echo "* * * * * /usr/bin/python3 /opt/collector/job_runner.py >> /var/log/macatlas/job_runner.log 2>&1" \
) | crontab -
# просим job_runner сразу пересобрать блок задач из collector_jobs
if [ "$DB_OK" = "yes" ]; then
    mysql_db -e "CREATE TABLE IF NOT EXISTS app_settings (name varchar(64) NOT NULL PRIMARY KEY, value varchar(255) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO app_settings (name, value) VALUES ('crontab_dirty','1')
ON DUPLICATE KEY UPDATE value='1';" >> "$LOG_FILE" 2>&1 \
        || warn "Не удалось выставить crontab_dirty — включите/выключите любую задачу в веб-настройках"
fi

# ---------- 5а. Учётные записи веб-интерфейса ----------
if [ "$DB_OK" = "yes" ]; then
log "Настройка учётных записей веб-интерфейса..."

[ -z "$ADMIN_PASSWORD" ] && ADMIN_PASSWORD=$(gen_pass)

# Хэш пароля генерирует PHP — тем же алгоритмом, что и модуль «Сотрудники»
mk_hash() { MKP="$1" php -r 'echo password_hash(getenv("MKP"), PASSWORD_DEFAULT);'; }

# Создать или обновить пользователя: $1 логин, $2 хэш, $3 ФИО, $4 код роли, $5 защита 0/1
upsert_user() {
    local login="$1" hash="$2" fio="$3" role="$4" prot="$5" cnt
    cnt=$(mysql_db -N -e "SELECT COUNT(*) FROM acl_staff WHERE login='$login'")
    if [ "$cnt" = "0" ]; then
        mysql_db -e "INSERT INTO acl_staff (login,password,auth_type,is_active,is_protected,fio,role_id)
            VALUES ('$login','$hash','local',1,$prot,'$fio',
                    (SELECT id FROM acl_roles WHERE code='$role' LIMIT 1))" >> "$LOG_FILE" 2>&1 \
            || warn "Не удалось создать учётную запись $login (см. $LOG_FILE)"
    else
        mysql_db -e "UPDATE acl_staff SET password='$hash', auth_type='local', is_active=1,
            is_protected=$prot, role_id=(SELECT id FROM acl_roles WHERE code='$role' LIMIT 1)
            WHERE login='$login'" >> "$LOG_FILE" 2>&1 \
            || warn "Не удалось обновить учётную запись $login (см. $LOG_FILE)"
    fi
}

ROOT_HASH=$(mk_hash "$ADMIN_PASSWORD")
upsert_user root "$ROOT_HASH" "Системный администратор" root 1

# Учётка demo/demo (только просмотр) — ТОЛЬКО по явному запросу CREATE_DEMO=yes
# (публичный релиз: известный всем логин-пароль на каждой установке недопустим)
if [ "$CREATE_DEMO" = "yes" ]; then
    # Роль «demo» (только просмотр всех активных разделов) — создаём, если нет
    mysql_db >> "$LOG_FILE" 2>&1 <<'SQL'
INSERT INTO acl_roles (code, name, is_system)
SELECT 'demo', 'Демо (только просмотр)', 0
 WHERE NOT EXISTS (SELECT 1 FROM acl_roles WHERE code='demo');
INSERT IGNORE INTO acl_permissions (role_id, resource_id, can_view)
SELECT r.id, res.id, 1 FROM acl_roles r, acl_resources res
 WHERE r.code='demo' AND res.is_active=1;
SQL
    DEMO_HASH=$(mk_hash "demo")
    upsert_user demo "$DEMO_HASH" "Демо-доступ (просмотр)" demo 0
fi
else
    warn "БД недоступна — учётные записи не создавались (создайте после подключения БД)"
fi

# ---------- 6. Конфигурация /etc/macatlas ----------
log "Установка конфигурации /etc/macatlas..."
run mkdir -p /etc/macatlas
[ -f "$KIT_DIR/etc/macatlas/config.json" ] || die "Нет $KIT_DIR/etc/macatlas/config.json"

# master_key (AES-256 паролей устройств): из install.conf или уникальный.
# ВНИМАНИЕ: если дамп содержит зашифрованные пароли устройств (таблица
# credentials), ключ ОБЯЗАН совпадать с тем, которым они зашифрованы,
# иначе креды станут нечитаемы — тогда укажите MASTER_KEY в install.conf.
if [ -z "$MASTER_KEY" ]; then
    MASTER_KEY=$(openssl rand -base64 32)
    log "Сгенерирован уникальный master_key для шифрования паролей устройств"
fi

sed -e "s/__DB_HOST__/${DB_HOST}/" \
    -e "s/__DB_NAME__/${DB_NAME}/" \
    -e "s/__DB_USER__/${DB_USER}/" \
    -e "s/__DB_PASSWORD__/${DB_PASSWORD}/" \
    -e "s|__MASTER_KEY__|${MASTER_KEY}|" \
    "$KIT_DIR/etc/macatlas/config.json" > /etc/macatlas/config.json
[ -f "$KIT_DIR/etc/macatlas/license.json" ] && cp "$KIT_DIR/etc/macatlas/license.json" /etc/macatlas/license.json
chown root:nginx /etc/macatlas /etc/macatlas/config.json
chmod 750 /etc/macatlas
chmod 640 /etc/macatlas/config.json
[ -f /etc/macatlas/license.json ] && { chown root:nginx /etc/macatlas/license.json; chmod 640 /etc/macatlas/license.json; }

# ротация логов cron-задач (/var/log/macatlas/*.log)
if [ -f "$KIT_DIR/etc/logrotate.d/macatlas" ]; then
    cp "$KIT_DIR/etc/logrotate.d/macatlas" /etc/logrotate.d/macatlas
    chmod 644 /etc/logrotate.d/macatlas
fi

# ---------- 7. php-fpm и php.ini ----------
log "Настройка php-fpm..."
PHP_FPM_WWW="/etc/php-fpm.d/www.conf"
if [ -f "$PHP_FPM_WWW" ]; then
    sed -i -E 's/^(user|group) = .*/\1 = nginx/' "$PHP_FPM_WWW"
    sed -i -E 's/^(listen\.(owner|group)) = .*/\1 = nginx/' "$PHP_FPM_WWW"
fi

# Каталог сессий принадлежит apache, а пул работает от nginx — чиним права
if [ -d /var/lib/php/session ]; then
    chgrp -R nginx /var/lib/php/session
    chmod 770 /var/lib/php/session
fi

run systemctl enable --now php-fpm

if [ -f /etc/php.ini ]; then
    sed -i -E 's/^upload_max_filesize\s*=.*/upload_max_filesize = 256M/' /etc/php.ini
    sed -i -E 's/^post_max_size\s*=.*/post_max_size = 256M/' /etc/php.ini
    sed -i -E 's/^memory_limit\s*=.*/memory_limit = 512M/' /etc/php.ini
    sed -i -E 's/^max_execution_time\s*=.*/max_execution_time = 300/' /etc/php.ini
fi

# ---------- 8. nginx ----------
log "Настройка nginx (домен: $DOMAIN)..."
# Файл конфига называем по домену: /etc/nginx/conf.d/<DOMAIN>.conf
# Старый файл от прежних версий установщика убираем, чтобы не было
# дубля server_name (nginx ругается "conflicting server name").
if [ -f /etc/nginx/conf.d/macatlas.conf ] && [ "$DOMAIN" != "macatlas" ]; then
    rm -f /etc/nginx/conf.d/macatlas.conf
fi
cat > "/etc/nginx/conf.d/${DOMAIN}.conf" <<EOF
# Из «красивого» URI (/brand/) получаем путь к index.php модуля.
# map работает на уровне http (conf.d подключается из http-контекста) —
# внутри @mod переменные rewrite \$1 ещё не существуют, поэтому проверку
# существования модуля делаем через эту карту, а не через \$1.
map \$uri \$macatlas_mod_index {
    default '';
    ~^/(?<macode>[a-z0-9_]+)(/.*)?\$  /var/www/macatlas/mod/\$macode/index.php;
}

server {
    listen      80;
    server_name ${DOMAIN};
    root        /var/www/macatlas;
    index       index.php;

    client_max_body_size 256m;
    access_log  /var/log/nginx/macatlas_access.log;
    error_log   /var/log/nginx/macatlas_error.log;

    location / {
        # сначала реальные файлы/папки (login.php, api/...), потом «красивые» адреса /<код>/
        try_files \$uri \$uri/ @mod;
    }

    # «Красивый» адрес модуля (напр. /brand/) отдаёт ГЛАВНУЮ страницу: приложение
    # одностраничное — index.php содержит оболочку и <div id="divfr">, а JS (furl())
    # по location.pathname сам подгружает нужный модуль в divfr.
    # REQUEST_URI должен остаться оригинальным (/brand/) — его разбирает furl().
    # Если папки модуля в mod/ нет — честный 404: иначе в divfr вставится
    # вторая копия главной страницы и сломает меню (см. модуль «История изменений»).
    location @mod {
        # Модуля с таким кодом нет → 404 (меню покажет «пункт не найден»),
        # иначе в divfr вставится вторая копия главной страницы и сломает меню.
        if (!-f \$macatlas_mod_index) { return 404; }
        # Модуль есть → отдаём ГЛАВНУЮ страницу (SPA подгрузит модуль в divfr)
        rewrite ^ /index.php last;
        return 404;
    }

    location ~ \.php\$ {
        try_files      \$uri =404;
        fastcgi_pass   unix:/run/php-fpm/www.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include        fastcgi_params;
    }

    location ~ /\.(?!well-known) { deny all; }
    location ~ /(core|etc)/      { deny all; }
}
EOF
run nginx -t
run systemctl enable --now nginx

# Firewall (если активен)
if systemctl is-active --quiet firewalld; then
    log "Открываю порты в firewalld..."
    firewall-cmd --permanent --add-service=http  >> "$LOG_FILE" 2>&1 || true
    firewall-cmd --permanent --add-service=https >> "$LOG_FILE" 2>&1 || true
    firewall-cmd --reload >> "$LOG_FILE" 2>&1 || true
fi

# ---------- 9. SSL ----------
if [ "$SSL" = "yes" ]; then
    log "Получение SSL-сертификата Let's Encrypt..."
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "admin@${DOMAIN}" \
        >> "$LOG_FILE" 2>&1 || warn "certbot не смог получить сертификат (DNS указывает на сервер?). Повторите вручную."
fi

# ---------- 10. Проверки ----------
log "Проверка синтаксиса PHP..."
ERR=$(find /var/www/macatlas -name "*.php" -exec php -l {} \; 2>/dev/null | grep -v "No syntax errors" || true)
[ -z "$ERR" ] || warn "Ошибки синтаксиса PHP:\n$ERR"

log "Перезапуск служб..."
if [ "$DB_LOCAL" = "yes" ]; then
    run systemctl restart php-fpm nginx mariadb
else
    # MariaDB на этом сервере не установлена/не запущена — рестартуем только веб
    run systemctl restart php-fpm nginx
fi

# ---------- Итог ----------
HTTP_CODE=$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1/login.php" || echo "000")

cat > "$CRED_FILE" <<EOF
MacAtlas — учётные данные установки ($(date))
=============================================
Домен:            ${DOMAIN}
Сайт:             http(s)://${DOMAIN}

База данных MariaDB:
  Хост:           ${DB_HOST}
  БД:             ${DB_NAME}
  Пользователь:   ${DB_USER}
  Пароль:         ${DB_PASSWORD}
  root-пароль:    ${MYSQL_ROOT_PASSWORD} (только при локальной БД)

Шифрование:
  master_key:     ${MASTER_KEY}
  (ключ AES-256 паролей устройств; сохраните — нужен при переносе базы)

Веб-интерфейс:
  root:           ${ADMIN_PASSWORD}
$( [ "$CREATE_DEMO" = "yes" ] && echo "  demo:           demo (только просмотр)" )

Конфиг:           /etc/macatlas/config.json
Лог установки:    ${LOG_FILE}
HTTP-ответ:       ${HTTP_CODE} (200 = сайт отвечает; 000 = ошибка, смотрите лог установки)
EOF

if [ "$DB_OK" = "no" ]; then
cat >> "$CRED_FILE" <<EOF

!!! ВНИМАНИЕ: БД ${DB_HOST} была недоступна во время установки !!!
Не выполнено: импорт дампа, флаг crontab_dirty, учётные записи веб-интерфейса.
После восстановления связи с БД просто запустите install.sh повторно —
он докончит пропущенные шаги (остальное уже настроено).
EOF
fi
chmod 600 "$CRED_FILE"

echo
log "Установка завершена!"
echo "──────────────────────────────────────────────"
cat "$CRED_FILE"
echo "──────────────────────────────────────────────"
echo
echo "Дальше:"
echo "  1. Проверьте вход: https://${DOMAIN} (root + пароль из файла выше)"
echo "  2. Задачи и расписание: веб → «Настройки системы» → «Задачи планировщика»"
echo "     (job_runner уже в cron; блок задач пересоберётся автоматически)"

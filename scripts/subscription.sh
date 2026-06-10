#!/usr/bin/env bash
# Gestiona "suscripciones" locales estilo IONOS: cada cliente es un subdominio
# (<cliente>.<BASE_DOMAIN>) con su propio contenedor app, su API_PACKAGE y su BD MariaDB.
#
# Credenciales de BD por suscripcion: database, usuario y password son el nombre
# del paquete en snake_case (ej. MiPaquete -> mi_paquete). Por eso cada paquete
# solo puede tener UNA suscripcion activa. Solo para local: en produccion cada
# deploy define sus credenciales reales en su .env.
#
# Uso (desde la raiz del repo o desde cualquier sitio):
#   bash scripts/subscription.sh init                       # prepara el wiring (compose + clients.yml)
#   bash scripts/subscription.sh add <cliente> <Package>    # alta: BD + servicio + vhost + hosts + seed
#   bash scripts/subscription.sh remove <cliente> [--drop-db]
#   bash scripts/subscription.sh list
#
# Requisitos: stack principal construida (docker compose up -d) y cert wildcard
# generado (bash scripts/setup-ssl.sh).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

CLIENTS_DIR="docker/clients"
CLIENTS_FILE="docker-compose.clients.yml"
NGINX_CONF_DIR="docker/nginx/conf.d"
CERTS_DIR="docker/nginx/certs"
WIN_HOSTS="/mnt/c/Windows/System32/drivers/etc/hosts"

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }

# ---------------------------------------------------------------- env / wiring

load_env() {
    [ -f .env ] || die "No existe .env en la raiz. Copia .env.example y ajusta valores."
    BASE_DOMAIN=$(grep -E "^BASE_DOMAIN=" .env | cut -d '=' -f2 || true)
    APP_DOMAIN=$(grep -E "^APP_DOMAIN=" .env | cut -d '=' -f2 || true)
    HTTPS_PORT=$(grep -E "^HTTPS_PORT=" .env | cut -d '=' -f2 || true)
    HTTPS_PORT="${HTTPS_PORT:-443}"
    [ -n "$BASE_DOMAIN" ] || die "BASE_DOMAIN no definido en .env"
}

# StudlyCase -> snake_case (MiPaquete -> mi_paquete)
snake() {
    echo "$1" | sed -r 's/([a-z0-9])([A-Z])/\1_\2/g' | tr '[:upper:]' '[:lower:]'
}

ensure_wiring() {
    mkdir -p "$CLIENTS_DIR"

    if [ ! -f "$CLIENTS_FILE" ]; then
        regen_compose
    fi

    if ! grep -qE "^COMPOSE_FILE=" .env; then
        if grep -qE "^# COMPOSE_FILE=" .env; then
            sed -i 's|^# COMPOSE_FILE=.*|COMPOSE_FILE=docker-compose.yml:docker-compose.clients.yml|' .env
        else
            printf '\nCOMPOSE_FILE=docker-compose.yml:docker-compose.clients.yml\n' >> .env
        fi
        info "COMPOSE_FILE activado en .env"
    fi

    docker compose config -q || die "docker compose config fallo: revisa $CLIENTS_FILE"
}

validate_name() {
    local c="$1"
    [[ "$c" =~ ^[a-z][a-z0-9-]{1,30}$ ]] || die "Nombre invalido [$c]: usa minusculas, numeros y guiones (2-31 chars, empieza por letra)."
    case "$c" in
        app|db|nginx|www) die "Nombre reservado: $c" ;;
    esac
    [ "$c.$BASE_DOMAIN" != "$APP_DOMAIN" ] || die "[$c.$BASE_DOMAIN] colisiona con APP_DOMAIN."
}

require_cert() {
    [ -f "$CERTS_DIR/wildcard.$BASE_DOMAIN.pem" ] || \
        die "Falta el cert wildcard.$BASE_DOMAIN.pem. Ejecuta: bash scripts/setup-ssl.sh"
}

# ------------------------------------------------------------------ generators

# Reescribe docker-compose.clients.yml completo desde docker/clients/*.env
regen_compose() {
    local tmp
    tmp=$(mktemp)
    {
        echo "# Archivo generado por subscription.sh - NO editar a mano."
        echo "services:"
    } > "$tmp"

    local found=0
    for state in "$CLIENTS_DIR"/*.env; do
        [ -e "$state" ] || break
        found=1
        # shellcheck disable=SC1090
        source "$state"
        cat >> "$tmp" <<EOF
  app-${CLIENT}:
    image: snake-laravel-api-app
    restart: unless-stopped
    working_dir: /var/www/src
    volumes:
      - ./src:/var/www/src
      - /var/www/src/bootstrap/cache
    environment:
      APP_NAME: ${CLIENT}
      APP_URL: https://${CLIENT}.\${BASE_DOMAIN}:\${HTTPS_PORT}
      API_PACKAGE: ${PACKAGE}
      LOG_CHANNEL: stderr
      CACHE_STORE: database
      SESSION_DRIVER: cookie
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: ${DB_DATABASE}
      DB_USERNAME: ${DB_USERNAME}
      DB_PASSWORD: "${DB_PASSWORD}"
    depends_on:
      db:
        condition: service_healthy
    networks:
      - app-network
EOF
    done

    if [ "$found" -eq 0 ]; then
        sed -i 's/^services:$/services: {}/' "$tmp"
    fi

    mv "$tmp" "$CLIENTS_FILE"
}

write_vhost() {
    local c="$1"
    cat > "$NGINX_CONF_DIR/client-$c.conf.template" <<EOF
# Generado por subscription.sh para "$c". NO editar a mano.
server {
    listen 80;
    server_name $c.$BASE_DOMAIN;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    server_name $c.$BASE_DOMAIN;
    index index.php;
    root /var/www/src/public;

    ssl_certificate     /etc/nginx/certs/wildcard.$BASE_DOMAIN.pem;
    ssl_certificate_key /etc/nginx/certs/wildcard.$BASE_DOMAIN-key.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    # Upstream por variable + DNS de Docker: nginx no muere si el contenedor esta parado
    resolver 127.0.0.11 valid=10s ipv6=off;
    set \$upstream app-$c:9000;

    location ~ \.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        fastcgi_pass \$upstream;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
        gzip_static on;
    }
}
EOF
}

# -------------------------------------------------------------------- database

# Root de MariaDB sin password: solo local (MARIADB_ALLOW_EMPTY_ROOT_PASSWORD
# en docker-compose.yml).
mariadb_exec() {
    docker compose exec -T db mariadb -uroot -e "$1"
}

create_database() {
    local db="$1" user="$2" pass="$3"
    mariadb_exec "
        CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS '$user'@'%' IDENTIFIED BY '$pass';
        GRANT ALL PRIVILEGES ON \`$db\`.* TO '$user'@'%';
        FLUSH PRIVILEGES;"
}

drop_database() {
    local db="$1" user="$2"
    mariadb_exec "DROP DATABASE IF EXISTS \`$db\`; DROP USER IF EXISTS '$user'@'%'; FLUSH PRIVILEGES;"
}

wait_for_app() {
    local c="$1" tries=60
    info "Esperando a que app-$c termine de arrancar (composer/migraciones)..."
    while [ "$tries" -gt 0 ]; do
        if docker compose exec -T "app-$c" php artisan migrate:status >/dev/null 2>&1; then
            return 0
        fi
        sleep 3
        tries=$((tries - 1))
    done
    die "app-$c no respondio a tiempo. Revisa: docker compose logs app-$c"
}

# ----------------------------------------------------------------------- hosts

# El hosts de Windows se edita DIRECTO (sin UAC) si setup-ssl.sh ya concedio
# permiso de escritura al usuario (icacls, una sola elevacion). Si no hay
# permiso, fallback a una ventana UAC puntual.

win_hosts_writable() {
    ( : >> "$WIN_HOSTS" ) 2>/dev/null
}

# Solo se toca el hosts de Windows (fuente de verdad): WSL copia ese archivo
# a su /etc/hosts en cada arranque. Hasta reiniciar WSL, dentro de WSL se
# puede probar con: curl --resolve <fqdn>:<puerto>:127.0.0.1 ...

hosts_add() {
    local fqdn="$1" c="$2"

    if [ -f "$WIN_HOSTS" ] && ! grep -q "$fqdn" "$WIN_HOSTS"; then
        if win_hosts_writable; then
            printf '\r\n127.0.0.1 %s # snake-sub:%s' "$fqdn" "$c" >> "$WIN_HOSTS"
            info "Hosts de Windows: añadido $fqdn (sin UAC)"
        elif command -v powershell.exe >/dev/null 2>&1; then
            info "Añadiendo $fqdn al hosts de Windows (acepta el UAC)..."
            local ps enc
            ps="Add-Content -Path \$env:SystemRoot\\System32\\drivers\\etc\\hosts -Value '127.0.0.1 $fqdn # snake-sub:$c'"
            enc=$(printf '%s' "$ps" | iconv -f UTF-8 -t UTF-16LE | base64 -w0)
            powershell.exe -NoProfile -Command \
                "Start-Process powershell -Verb RunAs -Wait -ArgumentList '-NoProfile','-EncodedCommand','$enc'" \
                >/dev/null 2>&1 || true
        fi
    fi

    if [ -f "$WIN_HOSTS" ] && ! grep -q "$fqdn" "$WIN_HOSTS"; then
        echo ""
        echo "AVISO: añade manualmente esta linea a C:\\Windows\\System32\\drivers\\etc\\hosts:"
        echo "  127.0.0.1 $fqdn # snake-sub:$c"
        echo "(o ejecuta 'bash scripts/setup-ssl.sh' para conceder permiso de hosts con una sola elevacion)"
        echo ""
    fi
}

hosts_remove() {
    local c="$1"

    if [ -f "$WIN_HOSTS" ] && grep -q "snake-sub:$c" "$WIN_HOSTS"; then
        if win_hosts_writable; then
            # Reescritura via truncado (permiso M sobre el archivo basta; sed -i
            # fallaria porque necesita crear un temporal en el directorio).
            # Regex anclada con \r opcional (CRLF) para no comerse cliente10 al quitar cliente1.
            local content
            content=$(grep -Ev "snake-sub:$c\r?\$" "$WIN_HOSTS")
            printf '%s' "$content" > "$WIN_HOSTS"
            info "Hosts de Windows: entrada de $c eliminada (sin UAC)"
        elif command -v powershell.exe >/dev/null 2>&1; then
            info "Quitando entrada del hosts de Windows (acepta el UAC)..."
            local ps enc
            ps="\$h=\"\$env:SystemRoot\\System32\\drivers\\etc\\hosts\"; Set-Content -Path \$h -Value (Get-Content \$h | Where-Object { \$_ -notmatch 'snake-sub:$c\$' })"
            enc=$(printf '%s' "$ps" | iconv -f UTF-8 -t UTF-16LE | base64 -w0)
            powershell.exe -NoProfile -Command \
                "Start-Process powershell -Verb RunAs -Wait -ArgumentList '-NoProfile','-EncodedCommand','$enc'" \
                >/dev/null 2>&1 || true
        fi
    fi

    if [ -f "$WIN_HOSTS" ] && grep -q "snake-sub:$c" "$WIN_HOSTS"; then
        echo "AVISO: limpia a mano la entrada snake-sub:$c del hosts de Windows."
    fi
}

# -------------------------------------------------------------------- commands

cmd_init() {
    load_env
    ensure_wiring
    info "Wiring listo. Crea suscripciones con: bash scripts/subscription.sh add <cliente> <Package>"
}

cmd_add() {
    local c="${1:-}" pkg="${2:-}"
    [ -n "$c" ] && [ -n "$pkg" ] || die "Uso: scripts/subscription.sh add <cliente> <Package>"

    load_env
    validate_name "$c"
    [ -d "src/packages/$pkg" ] || die "El paquete [$pkg] no existe en src/packages/. Crea uno: php artisan make:package $pkg"
    [ "$pkg" != "Core" ] || die "Core no es un paquete de negocio."
    [ ! -f "$CLIENTS_DIR/$c.env" ] || die "La suscripcion [$c] ya existe."

    # BD/usuario/password = snake_case del paquete => una suscripcion por paquete
    local existing
    for existing in "$CLIENTS_DIR"/*.env; do
        [ -e "$existing" ] || break
        grep -q "^PACKAGE=$pkg\$" "$existing" && \
            die "El paquete [$pkg] ya tiene una suscripcion ($(basename "${existing%.env}")). La BD se llama como el paquete: una suscripcion por paquete."
    done

    require_cert
    ensure_wiring

    local db
    db=$(snake "$pkg")

    info "Registrando suscripcion [$c] (paquete $pkg, BD $db)..."
    cat > "$CLIENTS_DIR/$c.env" <<EOF
CLIENT=$c
PACKAGE=$pkg
DB_DATABASE=$db
DB_USERNAME=$db
DB_PASSWORD=$db
CREATED_AT=$(date -Iseconds)
EOF

    regen_compose
    docker compose config -q || { rm -f "$CLIENTS_DIR/$c.env"; regen_compose; die "compose invalido, alta revertida"; }

    info "Asegurando MariaDB arriba..."
    docker compose up -d db
    until docker compose exec -T db healthcheck.sh --connect >/dev/null 2>&1; do sleep 2; done

    info "Creando base de datos y usuario..."
    create_database "$db" "$db" "$db"

    write_vhost "$c"

    info "Levantando app-$c..."
    docker compose up -d "app-$c"

    info "Recargando nginx (re-render de vhosts)..."
    docker compose restart nginx >/dev/null

    wait_for_app "$c"

    info "Ejecutando seeders..."
    docker compose exec -T "app-$c" php artisan core:seed --force

    hosts_add "$c.$BASE_DOMAIN" "$c"

    echo ""
    echo "Suscripcion [$c] lista:"
    echo "  URL:    https://$c.$BASE_DOMAIN:$HTTPS_PORT/api/v1"
    echo "  Docs:   https://$c.$BASE_DOMAIN:$HTTPS_PORT/api/v1/docs"
    echo "  Login:  admin@snake.com / secret1234 (seed por defecto)"
    echo "  BD:     $db (usuario $db)"
    echo "  Logs:   docker compose logs -f app-$c"
}

cmd_remove() {
    local c="${1:-}" drop="${2:-}"
    [ -n "$c" ] || die "Uso: scripts/subscription.sh remove <cliente> [--drop-db]"

    load_env
    [ -f "$CLIENTS_DIR/$c.env" ] || die "La suscripcion [$c] no existe."
    # shellcheck disable=SC1090
    source "$CLIENTS_DIR/$c.env"

    info "Parando y eliminando app-$c..."
    docker compose rm -sf "app-$c" >/dev/null 2>&1 || true

    rm -f "$NGINX_CONF_DIR/client-$c.conf.template"
    docker compose restart nginx >/dev/null 2>&1 || true

    if [ "$drop" = "--drop-db" ]; then
        echo "Esto BORRARA la base de datos [$DB_DATABASE] de forma permanente."
        read -r -p "Escribe el nombre del cliente para confirmar: " confirm
        if [ "$confirm" = "$c" ]; then
            drop_database "$DB_DATABASE" "$DB_USERNAME"
            info "BD $DB_DATABASE eliminada."
        else
            echo "Confirmacion incorrecta: BD conservada."
        fi
    else
        info "BD $DB_DATABASE conservada (usa --drop-db para borrarla)."
    fi

    rm -f "$CLIENTS_DIR/$c.env"
    regen_compose
    hosts_remove "$c"

    info "Suscripcion [$c] eliminada."
}

cmd_list() {
    load_env
    local found=0
    printf "%-15s %-15s %-35s %-20s %s\n" "CLIENTE" "PAQUETE" "DOMINIO" "BD" "ESTADO"
    for state in "$CLIENTS_DIR"/*.env; do
        [ -e "$state" ] || break
        found=1
        # shellcheck disable=SC1090
        source "$state"
        local status
        status=$(docker compose ps --format '{{.State}}' "app-$CLIENT" 2>/dev/null || true)
        printf "%-15s %-15s %-35s %-20s %s\n" \
            "$CLIENT" "$PACKAGE" "$CLIENT.$BASE_DOMAIN:$HTTPS_PORT" "$DB_DATABASE" "${status:-parado}"
    done
    [ "$found" -eq 1 ] || echo "(sin suscripciones; crea una con: bash scripts/subscription.sh add <cliente> <Package>)"
}

# ------------------------------------------------------------------------ main

case "${1:-}" in
    init)   cmd_init ;;
    add)    cmd_add "${2:-}" "${3:-}" ;;
    remove) cmd_remove "${2:-}" "${3:-}" ;;
    list)   cmd_list ;;
    *)
        echo "Uso: bash scripts/subscription.sh <init|add|remove|list>"
        echo "  init                        prepara compose + clients.yml"
        echo "  add <cliente> <Package>     alta de suscripcion"
        echo "  remove <cliente> [--drop-db] baja de suscripcion"
        echo "  list                        listado"
        exit 1
        ;;
esac

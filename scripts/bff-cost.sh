#!/usr/bin/env bash
# Qué le cuesta a la API cada página del BFF.
#
# No instrumenta nada: la API ya escribe una fila por petición en `commander_request_logs` con
# ruta, duración y bytes. El guion pide una página al BFF y se queda con las filas que aparecieron
# entre medias, así que atribuye a cada pantalla sus llamadas, su SQL y su peso.
#
#   bash scripts/bff-cost.sh                 # calle y panel
#   SCOPE=public bash scripts/bff-cost.sh    # solo la calle
#   LABEL=despues bash scripts/bff-cost.sh   # etiqueta la salida para comparar
#
# Recrea `app` con lote de metering 1 —con el lote de 20 las filas se quedan en el búfer del
# worker y la ventana sale vacía— y sin freno, y lo restaura al salir. Crea un token para el panel
# y lo borra. Borra sus propias filas de metering, que son la medida y no historia.
#
# La máquina tiene que estar quieta: esto mide milisegundos.
set -euo pipefail

API="${API:-/home/snake/DEV/PROJECTS/snk-api-commander}"
TENANT="${TENANT:-demo}"
READER_HOST="${READER_HOST:-${TENANT}.kilkana.test}"
API_HOST="${API_HOST:-${TENANT}.commander.test}"
BFF_IP="${BFF_IP:-127.0.0.1}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@snake.com}"
SCOPE="${SCOPE:-all}"
LABEL="${LABEL:-run}"
OUT="${OUT:-/tmp/commander-bff-cost-$LABEL-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"

dc() { docker compose -f "$API/docker-compose.yml" "$@"; }
sql() { dc exec -T db mariadb -uroot -N -B -e "$1"; }

PRIV="$(mktemp -d)"
printf 'services:\n  app:\n    environment:\n      CORE_METERING_BATCH: "1"\n      CORE_API_RATE_LIMIT: "1000000"\n' >"$PRIV/harness.yml"
TOKEN_ID=""
INSTANCE=""

page() { curl -sk -o "$1" -w '%{http_code} %{size_download} %{time_total}' \
    --resolve "${READER_HOST}:443:${BFF_IP}" "${@:3}" "https://${READER_HOST}$2"; }

wait_api() {
    local i
    for i in $(seq 1 60); do
        [[ "$(curl -sk -o /dev/null -w '%{http_code}' --resolve "${API_HOST}:8443:127.0.0.1" \
            "https://${API_HOST}:8443/api/v1/kilkana/public/site")" == 200 ]] && return 0
        sleep 1
    done
    echo "la API no responde" >&2; return 1
}

cleanup() {
    set +e
    [[ -z "$TOKEN_ID" ]] || dc exec -T -e TID="$TOKEN_ID" -e TENANT_DB="$TENANT_DB" db \
        mariadb -uroot -N -e "DELETE FROM ${TENANT_DB}.personal_access_tokens WHERE id=${TOKEN_ID};" >/dev/null 2>&1
    [[ -z "$FIRST_ID" ]] || sql "DELETE FROM commander.commander_request_logs WHERE id > ${FIRST_ID};" >/dev/null 2>&1
    echo 'restaurando app...'
    dc up -d --no-deps --force-recreate app >/dev/null 2>&1
    wait_api >/dev/null
    rm -rf "$PRIV"
}

# --- preparación ------------------------------------------------------------------------------

INSTANCE="$(sql "SELECT i.id FROM commander.commander_instances i
    JOIN commander.commander_clients c ON c.id=i.client_id
    JOIN commander.commander_applications a ON a.id=i.application_id
    WHERE c.slug='${TENANT}' AND a.slug='kilkana' AND i.provisioning_status='ready';" | tr -d '[:space:]')"
[[ "$INSTANCE" =~ ^[0-9]+$ ]] || { echo "sin instancia lista para ${TENANT}" >&2; exit 1; }
TENANT_DB="$(sql "SELECT \`database\` FROM commander.commander_instances WHERE id=${INSTANCE};" | tr -d '[:space:]')"

echo "recreando app con lote de metering 1 y sin freno..."
dc -f "$PRIV/harness.yml" up -d --no-deps --force-recreate app >/dev/null 2>&1
FIRST_ID=""
trap cleanup EXIT
wait_api

# Token del panel: la cookie de sesión del BFF es el token en claro, así que no hace falta
# pasar por el login y su Argon2, que mediría otra cosa.
read -r TOKEN_ID TOKEN <<<"$(dc exec -T -e BENCH_HOST="$API_HOST" -e BENCH_USER="$ADMIN_EMAIL" app php <<'PHP' | tail -n 1
<?php
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Models\CoreUserModel;
use Packages\Core\Support\CorePackageSelectorSupport;
chdir('/var/www/src');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$slug = CorePackageSelectorSupport::subdomainFromHost(getenv('BENCH_HOST'));
$instance = CommanderInstanceModel::query()->ready()
    ->whereHas('client', fn ($q) => $q->where('slug', $slug))
    ->whereHas('application', fn ($q) => $q->where('slug', 'kilkana'))->firstOrFail();
config([
    'database.connections.tenant.database' => $instance->database,
    'database.connections.tenant.username' => $instance->database_username,
    'database.connections.tenant.password' => $instance->database_password,
]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');
$user = CoreUserModel::query()->where('email', getenv('BENCH_USER'))->firstOrFail();
$token = $user->createToken('bff-cost', ['api'], now()->addHours(2));
echo $token->accessToken->id, ' ', $token->plainTextToken, "\n";
PHP
)"
[[ "$TOKEN_ID" =~ ^[0-9]+$ ]] || { echo 'no se pudo crear el token del panel' >&2; exit 1; }

# Direcciones reales del inquilino, para no medir 404 por inventarse un slug.
ART_PATH="$(sql "SELECT CONCAT(DATE_FORMAT(url_date,'/%Y/%m/%d/'), slug) FROM ${TENANT_DB}.kilkana_posts
    WHERE status='published' ORDER BY publish_at DESC LIMIT 1;" | tr -d '\r')"
POST_ID="$(sql "SELECT id FROM ${TENANT_DB}.kilkana_posts WHERE status='published' ORDER BY publish_at DESC LIMIT 1;" | tr -d '[:space:]')"
SECTION="$(sql "SELECT slug FROM ${TENANT_DB}.kilkana_categories WHERE status='live' ORDER BY position LIMIT 1;" | tr -d '[:space:]')"
AUTHOR="$(sql "SELECT a.slug FROM ${TENANT_DB}.kilkana_authors a JOIN ${TENANT_DB}.kilkana_posts p ON p.author_id=a.id
    WHERE p.status='published' GROUP BY a.slug ORDER BY COUNT(*) DESC LIMIT 1;" | tr -d '[:space:]')"
TAG="$(sql "SELECT t.slug FROM ${TENANT_DB}.kilkana_tags t JOIN ${TENANT_DB}.kilkana_post_tag pt ON pt.tag_id=t.id
    GROUP BY t.slug ORDER BY COUNT(*) DESC LIMIT 1;" | tr -d '[:space:]')"

PAGES=()
[[ "$SCOPE" == admin ]] || PAGES+=(
    "portada|/|" "sección|/${SECTION}|" "hemeroteca|/?page=2|" "artículo|${ART_PATH}|"
    "autor|/author/${AUTHOR}|" "etiqueta|/tag/${TAG}|" "rss|/rss.xml|" "sitemap|/sitemap.xml|"
)
# El panel se mide por su ruta de datos y no por la página: su layout exporta `ssr = false`, así
# que el servidor devuelve un armazón de 2 KB sin tocar la API y los `load` corren cuando el
# navegador pide `__data.json`. Ahí es donde está el coste de una pantalla.
[[ "$SCOPE" == public ]] || PAGES+=(
    "admin tablero|/admin/__data.json|1" "admin piezas|/admin/posts/__data.json|1"
    "admin editor|/admin/posts/${POST_ID}/__data.json|1"
    "admin mediateca|/admin/media/__data.json|1" "admin portada|/admin/front/__data.json|1"
    "admin roles|/admin/roles/__data.json|1" "admin secciones|/admin/categories/__data.json|1"
    "admin ajustes|/admin/settings/__data.json|1" "admin boletín|/admin/newsletter/__data.json|1"
    "admin papelera|/admin/trash/__data.json|1"
)

# --- medida -----------------------------------------------------------------------------------

FIRST_ID="$(sql 'SELECT COALESCE(MAX(id),0) FROM commander.commander_request_logs;' | tr -d '[:space:]')"
CSV="$OUT/paginas.csv"
echo 'pagina,http,ms_pagina,kb_html,llamadas,endpoints,ms_api_suma,ms_api_max,kb_api' >"$CSV"
printf '%-18s %5s %9s %8s %9s %10s %10s %9s %8s\n' \
    pagina HTTP ms_pagina kb_html llamadas endpoints ms_api ms_api_max kb_api

for entry in "${PAGES[@]}"; do
    IFS='|' read -r name path auth <<<"$entry"
    args=()
    [[ -z "$auth" ]] || args=(--cookie "kilkana_session=${TOKEN}")

    page /dev/null "$path" "${args[@]}" >/dev/null 2>&1 || true   # calentamiento
    before="$(sql 'SELECT COALESCE(MAX(id),0) FROM commander.commander_request_logs;' | tr -d '[:space:]')"
    read -r code size secs <<<"$(page "$OUT/$(echo "$name" | tr ' ' '-').html" "$path" "${args[@]}")"

    row="$(sql "SELECT COUNT(*), COUNT(DISTINCT route), COALESCE(SUM(duration_ms),0),
        COALESCE(MAX(duration_ms),0), ROUND(COALESCE(SUM(response_bytes),0)/1024,1)
        FROM commander.commander_request_logs WHERE id > ${before} AND instance_id = ${INSTANCE};")"
    IFS=$'\t' read -r calls endpoints ms msmax kb <<<"$row"

    sql "SELECT CONCAT('    ', LPAD(COUNT(*),2,' '), ' × ', route, '  ', ROUND(SUM(duration_ms),0), ' ms  ',
        ROUND(SUM(response_bytes)/1024,1), ' KB')
        FROM commander.commander_request_logs WHERE id > ${before} AND instance_id = ${INSTANCE}
        GROUP BY route ORDER BY SUM(duration_ms) DESC;" >"$OUT/$(echo "$name" | tr ' ' '-').endpoints.txt"

    ms_page="$(awk -v s="$secs" 'BEGIN{printf "%.0f", s*1000}')"
    kb_html="$(awk -v b="$size" 'BEGIN{printf "%.1f", b/1024}')"
    printf '%-18s %5s %9s %8s %9s %10s %10s %9s %8s\n' \
        "$name" "$code" "$ms_page" "$kb_html" "$calls" "$endpoints" "$ms" "$msmax" "$kb"
    echo "$name,$code,$ms_page,$kb_html,$calls,$endpoints,$ms,$msmax,$kb" >>"$CSV"
done

echo
echo "desglose por endpoint en $OUT/*.endpoints.txt · CSV en $CSV"

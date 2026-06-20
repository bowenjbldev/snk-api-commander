#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Simulacion end-to-end del Commander, en un solo comando:
#   limpiar BD  ->  scaffold packages  ->  provisionar (cuotas variadas)
#   ->  invoices (estados variados)  ->  trafico  ->  pipeline  ->  reporte
#
# Unifica el antiguo simulate-traffic.sh (el generador de trafico queda como
# el subcomando `traffic`).
#
# Uso:
#   bash scripts/simulate.sh                  # flujo completo (spec por defecto)
#   bash scripts/simulate.sh traffic <sub> [n]# SOLO trafico (ex simulate-traffic.sh)
#   bash scripts/simulate.sh invoices         # SOLO generar invoices
#   bash scripts/simulate.sh pipeline         # SOLO rollup + measure + refresh
#   bash scripts/simulate.sh report           # SOLO el reporte de estado
#
# Overrides por entorno:
#   ADMIN_EMAIL / ADMIN_PASSWORD   credenciales del operador (commander)
#   SCENARIO=shop|blog|generic     escenario de trafico (en `traffic`)
#   DOCKER_COMPOSE="docker compose"  runner de artisan/composer/mariadb
#
# La spec de packages (nombre, plan, cuota req/dia, subdominio, cliente, iters)
# se edita abajo en PACKAGES[]. El ultimo (Crm) lleva una cuota diminuta a
# proposito para que el medidor lo mande a `grace` y veas el enforcement.
# -----------------------------------------------------------------------------
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

get_env() { grep -E "^$1=" "$ROOT_DIR/.env" 2>/dev/null | head -1 | cut -d= -f2- || true; }
BASE_DOMAIN="$(get_env BASE_DOMAIN)"; BASE_DOMAIN="${BASE_DOMAIN:-snake.test}"
PORT="$(get_env HTTPS_PORT)"; PORT="${PORT:-8443}"
EMAIL="${ADMIN_EMAIL:-admin@snake.com}"
PASS="${ADMIN_PASSWORD:-secret1234}"
COMMANDER="commander.$BASE_DOMAIN"
CBASE="https://$COMMANDER:$PORT/api/v1"

DC="${DOCKER_COMPOSE:-docker compose}"
artisan()  { $DC exec -T app php artisan "$@"; }
composer_() { $DC exec -T app composer "$@"; }
dbq()      { $DC exec -T db mariadb -uroot "$@"; }

# name | plan-slug | q_requests_day | subdomain | client-slug | client-name | traffic-iters
PACKAGES=(
  "Shop shop-pro   100000 shop acme    ACME-Inc 40"
  "Blog blog-basic  50000 blog globex  Globex   30"
  "Crm  crm-mini       50 crm  initech Initech  45"
)

# ---------------------------------------------------------------- helpers JSON
jval() { # jval <json> <key>
  if command -v jq >/dev/null 2>&1; then
    jq -r ".$2 // empty" 2>/dev/null <<<"$1" || true
  else
    python3 -c "import sys,json
try: print(json.load(sys.stdin).get('$2',''))
except Exception: print('')" 2>/dev/null <<<"$1" || true
  fi
}
hr() { printf '%s\n' "------------------------------------------------------------"; }

# ------------------------------------------------------------------ trafico
# Genera trafico autenticado contra un deploy (tenant) para alimentar el
# metering. El escenario se elige por subdominio (shop|blog) o SCENARIO=...
run_traffic() { # run_traffic <sub> <iter>
  local SUB="$1" ITER="${2:-40}"
  local FQDN="$SUB.$BASE_DOMAIN"
  local BASE="https://$FQDN:$PORT/api/v1"
  local CURL=(curl -sk --resolve "$FQDN:$PORT:127.0.0.1")
  local TALLY; TALLY="$(mktemp)"
  local TOKEN=""

  api() { # api METHOD PATH [body]
    local m="$1" p="$2" b="${3:-}" tmp code
    local args=(-X "$m" "$BASE$p" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json')
    [ -n "$b" ] && args+=(-H 'Content-Type: application/json' -d "$b")
    tmp="$("${CURL[@]}" -w $'\n%{http_code}' "${args[@]}")"
    code="${tmp##*$'\n'}"; echo "$code" >> "$TALLY"
    printf '%s' "${tmp%$'\n'*}"
  }
  rnd() { echo $(( RANDOM % $1 )); }

  echo "==> [$SUB] login $EMAIL @ $BASE"
  local LOGIN; LOGIN="$("${CURL[@]}" -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
      -H 'Accept: application/json' -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")"
  TOKEN="$(jval "$LOGIN" token)"
  if [ -z "$TOKEN" ]; then echo "    ERROR login: $LOGIN"; rm -f "$TALLY"; return 1; fi

  case "${SCENARIO:-$SUB}" in
    shop)
      local PRODUCTS=() CUSTOMERS=() id body i n
      for i in $(seq 1 5); do
        body=$(api POST /products "{\"name\":\"Producto $i\",\"sku\":\"SKU-$i-$RANDOM\",\"price\":$(( RANDOM%9000+100 )).99,\"stock\":$(rnd 200)}")
        id="$(jval "$body" id)"; [ -n "$id" ] && PRODUCTS+=("$id")
      done
      for i in $(seq 1 4); do
        body=$(api POST /customers "{\"name\":\"Cliente $i\",\"email\":\"c$i-$RANDOM@ex.test\"}")
        id="$(jval "$body" id)"; [ -n "$id" ] && CUSTOMERS+=("$id")
      done
      for n in $(seq 1 "$ITER"); do
        api GET /products >/dev/null; api GET /customers >/dev/null
        [ ${#PRODUCTS[@]} -gt 0 ] && api GET "/products/${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}" >/dev/null
        [ $(( n % 5 )) -eq 0 ] && api GET "/products/999999" >/dev/null
        if [ ${#PRODUCTS[@]} -gt 0 ] && [ ${#CUSTOMERS[@]} -gt 0 ]; then
          api POST /orders "{\"customer_id\":${CUSTOMERS[$(rnd ${#CUSTOMERS[@]})]},\"items\":[{\"product_id\":${PRODUCTS[$(rnd ${#PRODUCTS[@]})]},\"qty\":1}]}" >/dev/null
        fi
        [ $(( n % 7 )) -eq 0 ] && api POST /products '{"name":"sin sku"}' >/dev/null
        sleep 0.2
      done ;;
    blog)
      local AUTHORS=() POSTS=() id body au n
      for i in $(seq 1 4); do
        body=$(api POST /authors "{\"name\":\"Autor $i\",\"email\":\"a$i-$RANDOM@ex.test\"}")
        id="$(jval "$body" id)"; [ -n "$id" ] && AUTHORS+=("$id")
      done
      for n in $(seq 1 "$ITER"); do
        api GET /authors >/dev/null; api GET /posts >/dev/null
        if [ ${#AUTHORS[@]} -gt 0 ]; then
          au="${AUTHORS[$(rnd ${#AUTHORS[@]})]}"
          body=$(api POST /posts "{\"author_id\":$au,\"title\":\"Post $n-$RANDOM\",\"slug\":\"p-$n-$RANDOM\",\"body\":\"lorem $n\"}")
          id="$(jval "$body" id)"; [ -n "$id" ] && POSTS+=("$id")
        fi
        [ ${#POSTS[@]} -gt 0 ] && api GET "/posts/${POSTS[$(rnd ${#POSTS[@]})]}" >/dev/null
        [ $(( n % 5 )) -eq 0 ] && api GET "/posts/999999" >/dev/null
        [ $(( n % 7 )) -eq 0 ] && api POST /authors '{"name":"sin email"}' >/dev/null
        sleep 0.2
      done ;;
    *)
      local n
      for n in $(seq 1 "$ITER"); do
        api GET /auth/me >/dev/null
        api GET /users   >/dev/null
        api GET "/users/999999" >/dev/null
        [ $(( n % 4 )) -eq 0 ] && api POST /users '{"email":"x"}' >/dev/null
        sleep 0.2
      done ;;
  esac

  echo "    status:"; sort "$TALLY" | uniq -c | while read -r c code; do printf '      %s : %d\n' "$code" "$c"; done
  rm -f "$TALLY"
}

# ------------------------------------------------------------------ stages
login_operator() {
  local resp
  resp="$(curl -sk --resolve "$COMMANDER:$PORT:127.0.0.1" -X POST "$CBASE/auth/login" \
      -H 'Content-Type: application/json' -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")"
  OP_TOKEN="$(jval "$resp" token)"
  [ -n "$OP_TOKEN" ] || { echo "ERROR: login operador fallo: $resp"; exit 1; }
}
capi() { # capi METHOD PATH [body]  -> request autenticado al commander
  local m="$1" p="$2" b="${3:-}"
  local args=(--resolve "$COMMANDER:$PORT:127.0.0.1" -X "$m" "$CBASE$p"
              -H "Authorization: Bearer $OP_TOKEN" -H 'Accept: application/json')
  [ -n "$b" ] && args+=(-H 'Content-Type: application/json' -d "$b")
  curl -sk "${args[@]}"
}

stage_clean() {
  hr; echo "STAGE 1/5 — limpiar BD (drop tenants + fresh commander + seed)"; hr
  local row name sub
  for row in "${PACKAGES[@]}"; do
    read -r name _ _ sub _ _ _ <<<"$row"
    dbq -e "DROP DATABASE IF EXISTS \`$sub\`;" 2>/dev/null || true
  done
  artisan core:migrate --fresh --force >/dev/null
  artisan core:seed --force >/dev/null
  echo "    commander reseteada + admin sembrado ($EMAIL)"
}

stage_scaffold() {
  hr; echo "STAGE 2/5 — scaffold de packages (make:package)"; hr
  local row name made=0
  for row in "${PACKAGES[@]}"; do
    read -r name _ <<<"$row"
    if [ -d "$ROOT_DIR/src/packages/$name" ]; then
      echo "    $name ya existe en disco — se reutiliza"
    else
      artisan make:package "$name" >/dev/null && echo "    $name scaffolded" && made=1
    fi
  done
  if [ "$made" -eq 1 ]; then composer_ dump-autoload -o >/dev/null && echo "    composer dump-autoload"; fi
  return 0
}

stage_provision() {
  hr; echo "STAGE 3/5 — provisionar vía Commander API"; hr
  login_operator
  local row name slug qreq sub cslug cname _it pid resp err
  for row in "${PACKAGES[@]}"; do
    read -r name slug qreq sub cslug cname _it <<<"$row"
    pid="$(jval "$(capi POST /plans "{\"slug\":\"$slug\",\"package\":\"$name\",\"name\":\"$name plan\",\"price_monthly\":19.99,\"q_requests_day\":$qreq,\"q_disk_db_mb\":1024,\"q_users_total\":50,\"status\":\"active\"}")" id)"
    [ -n "$pid" ] || { echo "    $name: plan FALLO"; continue; }
    resp="$(capi POST /provisioning "{\"plan_id\":$pid,\"subdomain\":\"$sub\",\"client\":{\"slug\":\"$cslug\",\"name\":\"$cname\",\"email\":\"ops@$cslug.test\"}}")"
    err="$(jval "$resp" error)"
    if [ -n "$err" ]; then
      printf '    %-5s plan=%-3s -> FALLO: %s\n' "$name" "$pid" "$err"
    else
      printf '    %-5s plan=%-3s subdominio=%s cuota_req/dia=%s -> OK\n' "$name" "$pid" "$sub" "$qreq"
    fi
  done
}

stage_invoices() {
  hr; echo "STAGE — generar invoices (estados variados: paid/pending/overdue)"; hr
  [ -n "${OP_TOKEN:-}" ] || login_operator
  local row name sub _r idx=0 ids dep cli status num err
  local statuses=(paid pending overdue)
  for row in "${PACKAGES[@]}"; do
    read -r name _r _r sub _r _r _r <<<"$row"
    ids="$(dbq -N commander -e "SELECT d.id, d.client_id FROM commander_deployments d JOIN commander_packages p ON p.id=d.package_id WHERE p.subdomain='$sub' LIMIT 1;" 2>/dev/null)"
    dep="$(awk '{print $1}' <<<"$ids")"; cli="$(awk '{print $2}' <<<"$ids")"
    [ -n "$dep" ] || { echo "    $name: sin deployment, salto"; continue; }
    status="${statuses[$((idx % 3))]}"; idx=$((idx + 1))
    num="INV-2026-$(printf '%04d' $((1000 + idx)))"
    err="$(jval "$(capi POST /invoices "{\"number\":\"$num\",\"client_id\":$cli,\"deployment_id\":$dep,\"amount\":19.99,\"issued_at\":\"2026-06-01\",\"due_at\":\"2026-06-15\",\"status\":\"$status\"}")" error)"
    if [ -n "$err" ]; then echo "    $name: invoice FALLO: $err"; else printf '    %-5s %s  status=%s  $19.99\n' "$name" "$num" "$status"; fi
  done
  return 0
}

stage_traffic() {
  hr; echo "STAGE 4/5 — generar trafico"; hr
  local row name sub iter
  for row in "${PACKAGES[@]}"; do
    read -r name _ _ sub _ _ iter <<<"$row"
    SCENARIO="${SCENARIO:-generic}" run_traffic "$sub" "$iter" || true
  done
}

stage_pipeline() {
  hr; echo "STAGE 5/5 — pipeline de metering (rollup + measure + refresh)"; hr
  echo "    quota:measure (mide + aplica enforcement)…"
  artisan commander:quota:measure >/dev/null || true
  echo "    forzando rollup del bucket actual…"
  artisan tinker --execute='
$s=new Packages\Commander\Services\CommanderUsageRollupService();
$r=new ReflectionMethod($s,"rollupBucket");$r->setAccessible(true);
$n=Carbon\CarbonImmutable::now("UTC");
$r->invoke($s,"hour",$n->startOfHour());$r->invoke($s,"day",$n->startOfDay());' >/dev/null 2>&1 || true
  artisan commander:refresh >/dev/null || true
  echo "    pipeline OK"
}

stage_report() {
  hr; echo "REPORTE — estado del Commander"; hr
  echo "Packages (estado runtime + cuota req/dia):"
  dbq commander -e "SELECT p.name, p.subdomain, p.status, COALESCE(p.reason,'-') reason, p.changed_by,
      d.requests_24h, d.quota_health
    FROM commander_packages p
    LEFT JOIN commander_deployments d ON d.package_id=p.id
    ORDER BY p.id;" 2>/dev/null || true
  echo; echo "Invoices (estados):"
  dbq commander -e "SELECT number, deployment_id, amount, status FROM commander_invoices ORDER BY id;" 2>/dev/null || true
  echo; echo "Tablas commander_* con filas:"
  dbq commander -N -e "SELECT 'packages',COUNT(*) FROM commander_packages
    UNION ALL SELECT 'deployments',COUNT(*) FROM commander_deployments
    UNION ALL SELECT 'subscriptions',COUNT(*) FROM commander_subscriptions
    UNION ALL SELECT 'invoices',COUNT(*) FROM commander_invoices
    UNION ALL SELECT 'request_logs',COUNT(*) FROM commander_request_logs
    UNION ALL SELECT 'usage_rollups',COUNT(*) FROM commander_usage_rollups
    UNION ALL SELECT 'activity_events',COUNT(*) FROM commander_activity_events;" 2>/dev/null || true
  hr; echo "Hecho. Panel: https://$COMMANDER:$PORT  (admin: $EMAIL / $PASS)"
}

# ------------------------------------------------------------------ main
case "${1:-all}" in
  traffic)  run_traffic "${2:?uso: simulate.sh traffic <sub> [iter]}" "${3:-40}" ;;
  invoices) stage_invoices ;;
  pipeline) stage_pipeline; stage_report ;;
  report)   stage_report ;;
  all)      stage_clean; stage_scaffold; stage_provision; stage_invoices; stage_traffic; stage_pipeline; stage_report ;;
  *) echo "uso: simulate.sh [all|traffic <sub> [n]|invoices|pipeline|report]"; exit 1 ;;
esac

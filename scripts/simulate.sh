#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Simulacion end-to-end del Commander, en un solo comando:
#   limpiar BD  ->  scaffold packages  ->  provisionar (cuotas variadas)
#   ->  invoices (estados variados)  ->  trafico  ->  pipeline  ->  reporte
#
# Modelo CLIENTE-PRIMERO, packages ANIDADOS por cliente: el subdominio es el
# CLIENTE ({client}.{BASE_DOMAIN}) y el slug del package va en el path
# (/api/v1/{slug}). El codigo vive en packages/{Client}/{Package} y cada par
# (cliente, package) corre en su propia BD {client}_{slug}.
#
# Uso:
#   bash scripts/simulate.sh                     # flujo completo (spec por defecto)
#   bash scripts/simulate.sh traffic <cli> <slug> [n]  # SOLO trafico HTTP a un deploy
#   bash scripts/simulate.sh invoices            # SOLO generar invoices
#   bash scripts/simulate.sh pipeline            # SOLO rollup + measure + refresh
#   bash scripts/simulate.sh volume [filas]      # bulk-seed masivo de request_logs en 48h (default 100000/pkg)
#   bash scripts/simulate.sh year [dias]         # densidad de UN AÑO por DB: rollups (diario+horario),
#                                                # commits y actividad git → charts del dashboard + Insights densos
#   bash scripts/simulate.sh report              # SOLO el reporte de estado
#
# Overrides por entorno:
#   ADMIN_EMAIL / ADMIN_PASSWORD   credenciales del operador (commander)
#   SCENARIO=shop|blog|generic     escenario de trafico (en `traffic`)
#   DOCKER_COMPOSE="docker compose"  runner de artisan/composer/mariadb
#
# La spec se edita abajo en PACKAGES[] (cliente, package, plan, cuota, iters).
# El ultimo (Initech/Crm) lleva una cuota diminuta a proposito para que el
# medidor lo mande a `grace` y veas el enforcement.
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

# client-slug | client-name | Package | plan-slug | q_requests_day | traffic-iters
# El codigo vive en packages/{StudlyClient}/{Package}; URL {client}.dom/api/v1/{slug}; BD {client}_{slug}.
PACKAGES=(
  "acme    ACME-Inc Shop shop-pro   100000 40"
  "acme    ACME-Inc Blog blog-basic  50000 30"
  "initech Initech  Crm  crm-mini       50 45"
)

# Helpers de derivacion (demo: packages de una sola palabra -> slug = minusculas).
slug_of() { tr '[:upper:]' '[:lower:]' <<<"$1"; }                 # Shop -> shop
studly_of() { local s="$1"; echo "$(tr '[:lower:]' '[:upper:]' <<<"${s:0:1}")${s:1}"; } # acme -> Acme
db_of() { echo "$(slug_of "$1")_$(slug_of "$2")"; }              # (acme, Shop) -> acme_shop

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
# Genera trafico autenticado contra un deploy (cliente x package) para alimentar
# el metering. URL: {client}.{BASE}/api/v1/{slug}. Escenario por slug o SCENARIO=.
run_traffic() { # run_traffic <client> <slug> <iter>
  local CLI="$1" SLUG="$2" ITER="${3:-40}"
  local FQDN="$CLI.$BASE_DOMAIN"
  local BASE="https://$FQDN:$PORT/api/v1/$SLUG"
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

  echo "==> [$CLI/$SLUG] login $EMAIL @ $BASE"
  local LOGIN; LOGIN="$("${CURL[@]}" -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
      -H 'Accept: application/json' -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")"
  TOKEN="$(jval "$LOGIN" token)"
  if [ -z "$TOKEN" ]; then echo "    ERROR login: $LOGIN"; rm -f "$TALLY"; return 1; fi

  case "${SCENARIO:-$SLUG}" in
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
        # Rutas definidas del scaffold (metered): generan carga real aunque el tenant aun no tenga
        # endpoints de negocio. Los /products etc. dan 404 (el tenant no los ha construido todavia).
        api GET /users >/dev/null; api GET /auth/me >/dev/null
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
        # Rutas definidas del scaffold (metered) + intentos de negocio (404 hasta que el tenant los cree).
        api GET /users >/dev/null; api GET /auth/me >/dev/null
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

# Busca el id de la fila viva del package por (cliente, slug). Echo: "id client_id".
pkg_ids() { # pkg_ids <client-slug> <package-slug>
  dbq -N commander -e "SELECT p.id, p.client_id FROM commander_packages p
    JOIN commander_clients c ON c.id = p.client_id
    WHERE c.slug='$1' AND p.slug='$2' AND p.provisioned_at IS NOT NULL LIMIT 1;" 2>/dev/null
}

stage_clean() {
  hr; echo "STAGE 1/5 — limpiar BD (drop tenants + fresh commander + seed)"; hr
  local row cli cname name
  for row in "${PACKAGES[@]}"; do
    read -r cli cname name _ _ _ <<<"$row"
    dbq -e "DROP DATABASE IF EXISTS \`$(db_of "$cli" "$name")\`;" 2>/dev/null || true
  done
  artisan core:migrate --fresh --force >/dev/null
  artisan core:seed --force >/dev/null
  echo "    commander reseteada + admin sembrado ($EMAIL)"
}

stage_scaffold() {
  hr; echo "STAGE 2/5 — scaffold de packages (make:package <Client> <Package>)"; hr
  local row cli cname name made=0 cstudly
  for row in "${PACKAGES[@]}"; do
    read -r cli cname name _ _ _ <<<"$row"
    cstudly="$(studly_of "$cli")"
    if [ -d "$ROOT_DIR/src/packages/$cstudly/$name" ]; then
      echo "    $cstudly/$name ya existe en disco — se reutiliza"
    else
      artisan make:package "$cli" "$name" --no-dump >/dev/null && echo "    $cstudly/$name scaffolded" && made=1
    fi
  done
  if [ "$made" -eq 1 ]; then composer_ dump-autoload -o >/dev/null && echo "    composer dump-autoload"; fi
  return 0
}

stage_provision() {
  hr; echo "STAGE 3/5 — provisionar vía Commander API (cliente-primero)"; hr
  login_operator
  local row cli cname name slug qreq _it pid resp err
  for row in "${PACKAGES[@]}"; do
    read -r cli cname name slug qreq _it <<<"$row"
    pid="$(jval "$(capi POST /plans "{\"slug\":\"$slug\",\"package\":\"$name\",\"name\":\"$name plan\",\"price_monthly\":19.99,\"q_requests_day\":$qreq,\"q_disk_db_mb\":1024,\"q_users_total\":50,\"status\":\"active\"}")" id)"
    [ -n "$pid" ] || { echo "    $cli/$name: plan FALLO"; continue; }
    resp="$(capi POST /provisioning "{\"plan_id\":$pid,\"client\":{\"slug\":\"$cli\",\"name\":\"$cname\",\"email\":\"ops@$cli.test\"}}")"
    err="$(jval "$resp" error)"
    if [ -n "$err" ]; then
      # Cliente ya existe (2ª package del mismo cliente): reintenta con client_id.
      local cid; cid="$(dbq -N commander -e "SELECT id FROM commander_clients WHERE slug='$cli' LIMIT 1;" 2>/dev/null | tr -d '[:space:]')"
      if [ -n "$cid" ]; then
        resp="$(capi POST /provisioning "{\"plan_id\":$pid,\"client_id\":$cid}")"
        err="$(jval "$resp" error)"
      fi
    fi
    if [ -n "$err" ]; then
      printf '    %-7s %-4s -> FALLO: %s\n' "$cli" "$name" "$err"
    else
      printf '    %-7s %-4s url=%s.%s/api/v1/%s  BD=%s  cuota_req/dia=%s -> OK\n' \
        "$cli" "$name" "$cli" "$BASE_DOMAIN" "$(jval "$resp" package.slug)" "$(jval "$resp" package.database)" "$qreq"
    fi
  done
}

stage_change_plan() {
  hr; echo "STAGE — cambiar de plan (1ª package): cierra el término vigente, abre uno nuevo (historial)"; hr
  [ -n "${OP_TOKEN:-}" ] || login_operator
  local cli cname name slug qreq _it pid err
  read -r cli cname name slug qreq _it <<<"${PACKAGES[0]}"
  # Segundo plan activo para ese package lógico, y cambio a él (el operador direcciona por name).
  pid="$(jval "$(capi POST /plans "{\"slug\":\"${slug}-v2\",\"package\":\"$name\",\"name\":\"$name Pro\",\"price_monthly\":49.99,\"q_requests_day\":$qreq,\"q_disk_db_mb\":2048,\"q_users_total\":100,\"status\":\"active\"}")" id)"
  [ -n "$pid" ] || { echo "    $name: 2º plan FALLO"; return 0; }
  err="$(jval "$(capi POST "/packages/$name/change-plan" "{\"plan_id\":$pid}")" error)"
  if [ -n "$err" ]; then
    echo "    $cli/$name: change-plan FALLO: $err"
  else
    printf '    %-7s %-4s -> plan nuevo id=%s (%s Pro); el término anterior queda en el historial\n' "$cli" "$name" "$pid" "$name"
  fi
  return 0
}

stage_invoices() {
  hr; echo "STAGE — generar invoices (estados variados: paid/pending/overdue)"; hr
  [ -n "${OP_TOKEN:-}" ] || login_operator
  local row cli cname name slug _r idx=0 ids pkgid cliid status num err
  local statuses=(paid pending overdue)
  for row in "${PACKAGES[@]}"; do
    read -r cli cname name slug _r _r <<<"$row"
    ids="$(pkg_ids "$cli" "$(slug_of "$name")")"
    pkgid="$(awk '{print $1}' <<<"$ids")"; cliid="$(awk '{print $2}' <<<"$ids")"
    [ -n "$pkgid" ] || { echo "    $cli/$name: sin package provisionado, salto"; continue; }
    status="${statuses[$((idx % 3))]}"; idx=$((idx + 1))
    num="INV-2026-$(printf '%04d' $((1000 + idx)))"
    err="$(jval "$(capi POST /invoices "{\"number\":\"$num\",\"client_id\":$cliid,\"package_id\":$pkgid,\"amount\":19.99,\"issued_at\":\"2026-06-01\",\"due_at\":\"2026-06-15\",\"status\":\"$status\"}")" error)"
    if [ -n "$err" ]; then echo "    $cli/$name: invoice FALLO: $err"; else printf '    %-7s %-4s %s  status=%s  $19.99\n' "$cli" "$name" "$num" "$status"; fi
  done
  return 0
}

stage_traffic() {
  hr; echo "STAGE 4/5 — generar trafico (cliente.dom/api/v1/slug)"; hr
  local row cli cname name iter
  for row in "${PACKAGES[@]}"; do
    read -r cli cname name _ _ iter <<<"$row"
    SCENARIO="${SCENARIO:-}" run_traffic "$cli" "$(slug_of "$name")" "$iter" || true
  done
}

# ------------------------------------------------------------------ volumen
# Siembra cientos de miles de commander_request_logs por package, repartidos en
# las ultimas 48h, con UN SOLO `INSERT ... SELECT FROM seq_1_to_N` (motor SEQUENCE
# de MariaDB): set-based, sin loops ni HTTP. Luego `commander:usage:rollup` agrega
# TODOS los buckets cerrados (48h). Uso: simulate.sh volume [filas_por_package].
stage_seed_volume() {
  local ROWS="${1:-100000}"
  hr; echo "STAGE — seed VOLUMEN (~$ROWS filas/package base, repartidas en 48h, bulk INSERT)"; hr
  local factors=(1.0 0.6 0.3) idx=0 row cli cname name pid n
  for row in "${PACKAGES[@]}"; do
    read -r cli cname name _ _ _ <<<"$row"
    pid="$(awk '{print $1}' <<<"$(pkg_ids "$cli" "$(slug_of "$name")")")"
    [ -n "$pid" ] || { echo "    $cli/$name: sin package provisionado, salto"; continue; }
    n="$(awk "BEGIN{printf \"%d\", $ROWS * ${factors[$((idx % 3))]}}")"; idx=$((idx + 1))
    echo "    $cli/$name (id=$pid): bulk insert de $n filas…"
    if ! dbq commander <<SQL
INSERT INTO commander_request_logs
  (package_id, created_at, updated_at, method, route, path, status, duration_ms, user_id, token_id, ip, request_bytes, response_bytes, memory_kb)
SELECT
  $pid,
  NOW() - INTERVAL FLOOR(RAND() * 172800) SECOND,
  NOW(),
  ELT(FLOOR(1 + RAND() * 4), 'GET', 'GET', 'GET', 'POST'),
  ELT(FLOOR(1 + RAND() * 5), '/products', '/orders', '/customers', '/users', '/auth/me'),
  ELT(FLOOR(1 + RAND() * 5), '/api/v1/products', '/api/v1/orders', '/api/v1/customers', '/api/v1/users', '/api/v1/auth/me'),
  CASE WHEN RAND() < 0.04 THEN 404 WHEN RAND() < 0.012 THEN 500 ELSE 200 END,
  FLOOR(20 + RAND() * 480),
  FLOOR(1 + RAND() * 40),
  1,
  '127.0.0.1',
  FLOOR(RAND() * 2000),
  FLOOR(RAND() * 9000),
  FLOOR(2000 + RAND() * 8000)
FROM seq_1_to_$n;
SQL
    then
      echo "    ERROR en el bulk insert (¿motor SEQUENCE de MariaDB disponible?)"
    fi
  done
  echo "    recomputando rollups de las 48h (reset + rollup hour+day)…"
  dbq commander -e "DELETE FROM commander_usage_rollups;" >/dev/null 2>&1 || true
  artisan commander:usage:rollup --period=both >/dev/null || true
  stage_pipeline
}

# ------------------------------------------------------------------ año (densidad)
# Siembra densidad de UN AÑO 100% por DB (cero HTTP/loops → rapidísimo), directo a las tablas
# AGREGADAS (no procesa logs crudos con el rollup PHP, que con un año sería lentísimo):
#   - commander_usage_rollups: buckets DIARIOS (DAYS días) + HORARIOS (48h), una fila por
#     status_class (2xx/4xx/5xx) → alimenta Requests, Error rate (4xx/5xx) y el tab Usage en TODOS
#     los rangos del filtro (24h/48h/semana/mes/año).
#   - commander_request_logs: ~LOGS crudos repartidos en 48h (para la página Request Logs).
#   - commander_package_commits + _activity_daily + _repos: commits y actividad diaria repartidos en
#     el año → Recent commits, Code frequency y el tab Insights (heatmap + code freq).
# Pone requests_24h/last_seen por SQL (sin artisan). Uso: simulate.sh year [dias] (default 365).
stage_seed_year() {
  local DAYS="${1:-365}" COMMITS=420 LOGS=6000
  hr; echo "STAGE — seed AÑO (rollups diarios ${DAYS}d + horarios 48h · git: ${COMMITS} commits + ${DAYS}d actividad) — todo por DB"; hr
  local factors=(1.0 0.65 0.35) idx=0 row cli cname name pid f sc
  for row in "${PACKAGES[@]}"; do
    read -r cli cname name _ _ _ <<<"$row"
    pid="$(awk '{print $1}' <<<"$(pkg_ids "$cli" "$(slug_of "$name")")")"
    [ -n "$pid" ] || { echo "    $cli/$name: sin package provisionado, salto"; continue; }
    f="${factors[$((idx % 3))]}"; idx=$((idx + 1)); sc="$(studly_of "$cli")"
    echo "    $cli/$name (id=$pid, x$f): usage + git…"
    dbq commander <<SQL || echo "    ERROR seeding $cli/$name"
DELETE FROM commander_usage_rollups WHERE package_id=$pid;
DELETE FROM commander_package_commits WHERE package_id=$pid;
DELETE FROM commander_package_activity_daily WHERE package_id=$pid;

INSERT INTO commander_usage_rollups
  (package_id, period, period_start, route, method, status_class, request_count, error_count,
   avg_duration_ms, p95_duration_ms, total_request_bytes, total_response_bytes, unique_users, created_at, updated_at)
SELECT package_id, period, period_start, route, method, status_class,
  rc, CASE WHEN status_class='2xx' THEN 0 ELSE rc END, avg_d, p95_d, treq, tresp, uu, NOW(), NOW()
FROM (
  SELECT $pid package_id, 'day' period,
    TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL (s.seq-1) DAY)) period_start,
    '/api/v1' route, 'GET' method, sc.c status_class,
    CASE sc.c WHEN '2xx' THEN FLOOR((1500+RAND()*3500)*$f)
              WHEN '4xx' THEN FLOOR((10+RAND()*180)*$f)
              ELSE FLOOR((RAND()*45)*$f) END rc,
    FLOOR(40+RAND()*220) avg_d, FLOOR(150+RAND()*500) p95_d,
    FLOOR(RAND()*8000) treq, FLOOR(RAND()*40000) tresp, FLOOR(1+RAND()*40) uu
  FROM seq_1_to_$DAYS s
  CROSS JOIN (SELECT '2xx' c UNION ALL SELECT '4xx' c UNION ALL SELECT '5xx' c) sc
) d;

INSERT INTO commander_usage_rollups
  (package_id, period, period_start, route, method, status_class, request_count, error_count,
   avg_duration_ms, p95_duration_ms, total_request_bytes, total_response_bytes, unique_users, created_at, updated_at)
SELECT package_id, period, period_start, route, method, status_class,
  rc, CASE WHEN status_class='2xx' THEN 0 ELSE rc END, avg_d, p95_d, treq, tresp, uu, NOW(), NOW()
FROM (
  SELECT $pid package_id, 'hour' period,
    TIMESTAMP(DATE_FORMAT(NOW() - INTERVAL (s.seq-1) HOUR, '%Y-%m-%d %H:00:00')) period_start,
    '/api/v1' route, 'GET' method, sc.c status_class,
    CASE sc.c WHEN '2xx' THEN FLOOR((60+RAND()*180)*$f)
              WHEN '4xx' THEN FLOOR((RAND()*12)*$f)
              ELSE FLOOR((RAND()*4)*$f) END rc,
    FLOOR(40+RAND()*220) avg_d, FLOOR(150+RAND()*500) p95_d,
    FLOOR(RAND()*8000) treq, FLOOR(RAND()*40000) tresp, FLOOR(1+RAND()*40) uu
  FROM seq_1_to_48 s
  CROSS JOIN (SELECT '2xx' c UNION ALL SELECT '4xx' c UNION ALL SELECT '5xx' c) sc
) d;

INSERT INTO commander_request_logs
  (package_id, created_at, updated_at, method, route, path, status, duration_ms, user_id, token_id, ip, request_bytes, response_bytes, memory_kb)
SELECT $pid, NOW() - INTERVAL FLOOR(RAND()*172800) SECOND, NOW(),
  ELT(FLOOR(1+RAND()*4),'GET','GET','GET','POST'),
  ELT(FLOOR(1+RAND()*5),'/products','/orders','/customers','/users','/auth/me'),
  ELT(FLOOR(1+RAND()*5),'/api/v1/products','/api/v1/orders','/api/v1/customers','/api/v1/users','/api/v1/auth/me'),
  CASE WHEN RAND()<0.04 THEN 404 WHEN RAND()<0.012 THEN 500 ELSE 200 END,
  FLOOR(20+RAND()*480), FLOOR(1+RAND()*40), 1, '127.0.0.1',
  FLOOR(RAND()*2000), FLOOR(RAND()*9000), FLOOR(2000+RAND()*8000)
FROM seq_1_to_$LOGS;

INSERT INTO commander_package_commits
  (package_id, sha, committed_at, author_name, author_email, message, additions, deletions, files_changed, created_at, updated_at)
SELECT $pid,
  SUBSTRING(MD5(CONCAT($pid,'-',s.seq,'-',RAND())),1,40),
  NOW() - INTERVAL FLOOR(RAND()*$DAYS*86400) SECOND,
  ELT(FLOOR(1+RAND()*4),'Jorge Bowen','Ada Lovelace','Grace Hopper','Linus T'),
  ELT(FLOOR(1+RAND()*4),'jorge@snake.dev','ada@snake.dev','grace@snake.dev','linus@snake.dev'),
  ELT(FLOOR(1+RAND()*6),'feat: nuevo endpoint','fix: guard de null','refactor: extrae servicio','docs: actualiza readme','test: cobertura','chore: bump deps'),
  FLOOR(RAND()*420), FLOOR(RAND()*210), FLOOR(1+RAND()*12), NOW(), NOW()
FROM seq_1_to_$COMMITS s;

INSERT INTO commander_package_activity_daily
  (package_id, day, commit_count, additions, deletions, files_changed, created_at, updated_at)
SELECT package_id, day, cc, FLOOR(cc*(20+RAND()*200)), FLOOR(cc*(5+RAND()*80)),
  CASE WHEN cc>0 THEN FLOOR(1+RAND()*cc*3) ELSE 0 END, NOW(), NOW()
FROM (
  SELECT $pid package_id, DATE_SUB(CURDATE(), INTERVAL (s.seq-1) DAY) day, FLOOR(RAND()*RAND()*9) cc
  FROM seq_1_to_$DAYS s
) d;

INSERT INTO commander_package_repos
  (package_id, provider, workspace, repo_slug, path_prefix, default_branch, last_synced_at, last_synced_sha, created_at, updated_at)
VALUES ($pid, 'local', 'snake', 'snake-laravel-api', 'packages/$sc/$name', 'main', NOW(), SUBSTRING(MD5(RAND()),1,40), NOW(), NOW())
ON DUPLICATE KEY UPDATE last_synced_at=VALUES(last_synced_at), last_synced_sha=VALUES(last_synced_sha);

UPDATE commander_packages SET
  requests_24h = (SELECT COALESCE(SUM(request_count),0) FROM commander_usage_rollups r
                  WHERE r.package_id=$pid AND r.period='hour' AND r.period_start >= NOW() - INTERVAL 24 HOUR),
  last_seen = NOW()
WHERE id=$pid;
SQL
  done
  echo "    listo (densidad de ${DAYS}d sembrada por DB)."
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
  echo "Packages (cliente x package; estado runtime + cuota req/dia):"
  dbq commander -e "SELECT c.slug AS client, p.slug AS package, p.database AS db, p.status,
      COALESCE(p.reason,'-') reason, p.requests_24h, p.quota_health
    FROM commander_packages p
    JOIN commander_clients c ON c.id = p.client_id
    WHERE p.provisioned_at IS NOT NULL
    ORDER BY p.id;" 2>/dev/null || true
  echo; echo "Invoices (estados):"
  dbq commander -e "SELECT number, package_id, amount, status FROM commander_invoices ORDER BY id;" 2>/dev/null || true
  echo; echo "Subscriptions (términos; vigente = ended_at NULL, lo demás es historial):"
  dbq commander -e "SELECT package_id, plan, price_monthly, billing_status, ended_at FROM commander_subscriptions ORDER BY package_id, id;" 2>/dev/null || true
  echo; echo "Tablas commander_* con filas:"
  dbq commander -N -e "SELECT 'packages',COUNT(*) FROM commander_packages
    UNION ALL SELECT 'subscriptions',COUNT(*) FROM commander_subscriptions
    UNION ALL SELECT 'invoices',COUNT(*) FROM commander_invoices
    UNION ALL SELECT 'request_logs',COUNT(*) FROM commander_request_logs
    UNION ALL SELECT 'usage_rollups',COUNT(*) FROM commander_usage_rollups
    UNION ALL SELECT 'activity_events',COUNT(*) FROM commander_activity_events
    UNION ALL SELECT 'package_commits',COUNT(*) FROM commander_package_commits
    UNION ALL SELECT 'package_activity_daily',COUNT(*) FROM commander_package_activity_daily;" 2>/dev/null || true
  hr; echo "Hecho. Panel: https://$COMMANDER:$PORT  (admin: $EMAIL / $PASS)"
}

# ------------------------------------------------------------------ main
case "${1:-all}" in
  traffic)  run_traffic "${2:?uso: simulate.sh traffic <client> <slug> [iter]}" "${3:?uso: simulate.sh traffic <client> <slug> [iter]}" "${4:-40}" ;;
  invoices) stage_invoices ;;
  change-plan) stage_change_plan; stage_report ;;
  pipeline) stage_pipeline; stage_report ;;
  volume)   stage_seed_volume "${2:-100000}"; stage_report ;;
  year)     stage_seed_year "${2:-365}"; stage_report ;;
  report)   stage_report ;;
  all)      stage_clean; stage_scaffold; stage_provision; stage_change_plan; stage_invoices; stage_traffic; stage_pipeline; stage_seed_year 365; stage_report ;;
  *) echo "uso: simulate.sh [all|traffic <client> <slug> [n]|invoices|change-plan|pipeline|volume [filas]|year [dias]|report]"; exit 1 ;;
esac

#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Genera trafico autenticado realista contra un deploy (tenant) para alimentar
# el metering de Core (core_request_logs) y ver Logs/Usage reales en el Commander.
# Mezcla CRUD del paquete con algunos errores (404/422) para variar metodos/status.
#
# Uso:   bash scripts/simulate-traffic.sh <subdominio> [iteraciones]
# Ej:    bash scripts/simulate-traffic.sh shop 40
#        bash scripts/simulate-traffic.sh blog 30
#
# El escenario se elige por el subdominio (shop|blog); cualquier otro usa el
# generico (users + auth + errores), valido para cualquier paquete. Override con
# SCENARIO=<shop|blog|generic>.
#
# Credenciales: admin sembrado (admin@snake.com / secret1234). Override con
# ADMIN_EMAIL / ADMIN_PASSWORD. Resuelve <sub>.<BASE_DOMAIN>:<HTTPS_PORT> a
# 127.0.0.1 (nginx publica el puerto), asi no depende del hosts del SO.
# -----------------------------------------------------------------------------
set -euo pipefail

SUB="${1:-}"
ITER="${2:-40}"
[ -n "$SUB" ] || { echo "Uso: bash scripts/simulate-traffic.sh <subdominio> [iteraciones]"; exit 1; }

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
get_env() { grep -E "^$1=" "$ROOT_DIR/.env" 2>/dev/null | head -1 | cut -d= -f2- || true; }

BASE_DOMAIN="$(get_env BASE_DOMAIN)"; BASE_DOMAIN="${BASE_DOMAIN:-snake.test}"
PORT="$(get_env HTTPS_PORT)"; PORT="${PORT:-8443}"
EMAIL="${ADMIN_EMAIL:-admin@snake.com}"
PASS="${ADMIN_PASSWORD:-secret1234}"

FQDN="$SUB.$BASE_DOMAIN"
BASE="https://$FQDN:$PORT/api/v1"
CURL=(curl -sk --resolve "$FQDN:$PORT:127.0.0.1")

# jq si esta; si no, python3 como fallback para extraer una clave del JSON
jval() { # jval <json> <key>
    if command -v jq >/dev/null 2>&1; then
        printf '%s' "$1" | jq -r ".$2 // empty" 2>/dev/null || true
    else
        printf '%s' "$1" | python3 -c "import sys,json
try:
    print(json.load(sys.stdin).get('$2',''))
except Exception:
    print('')" 2>/dev/null || true
    fi
}

# Contador en fichero (no array): api() se invoca dentro de $(...) en los creates,
# y un subshell no propagaria un array al padre. Un append a fichero si persiste.
TALLY_FILE="$(mktemp)"
trap 'rm -f "$TALLY_FILE"' EXIT
note() { echo "$1" >> "$TALLY_FILE"; }

# api METHOD PATH [body]  -> devuelve el cuerpo de la respuesta y contabiliza el status
api() {
    local m="$1" p="$2" b="${3:-}" tmp code
    local args=(-X "$m" "$BASE$p" -H "Authorization: Bearer ${TOKEN:-}" -H 'Accept: application/json')
    [ -n "$b" ] && args+=(-H 'Content-Type: application/json' -d "$b")
    tmp="$("${CURL[@]}" -w $'\n%{http_code}' "${args[@]}")"
    code="${tmp##*$'\n'}"
    note "$code"
    printf '%s' "${tmp%$'\n'*}"
}

rnd() { echo $(( RANDOM % $1 )); }

# ------------------------------------------------------------------- escenarios

scenario_shop() {
    local PRODUCTS=() CUSTOMERS=() id body
    echo "==> [shop] Sembrando catalogo (5 productos, 4 clientes)"
    for i in $(seq 1 5); do
        body=$(api POST /products "{\"name\":\"Producto $i\",\"sku\":\"SKU-$i-$RANDOM\",\"price\":$(( RANDOM%9000+100 )).99,\"stock\":$(rnd 200)}")
        id="$(jval "$body" id)"; [ -n "$id" ] && PRODUCTS+=("$id")
    done
    for i in $(seq 1 4); do
        body=$(api POST /customers "{\"name\":\"Cliente $i\",\"email\":\"cliente$i-$RANDOM@example.com\",\"phone\":\"+34 600 000 00$i\"}")
        id="$(jval "$body" id)"; [ -n "$id" ] && CUSTOMERS+=("$id")
    done
    [ ${#PRODUCTS[@]} -gt 0 ] && [ ${#CUSTOMERS[@]} -gt 0 ] || { echo "ERROR: no se pudo sembrar catalogo"; exit 1; }

    echo "==> [shop] $ITER iteraciones..."
    for n in $(seq 1 "$ITER"); do
        api GET /products  >/dev/null
        api GET /customers >/dev/null
        api GET "/products/${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}" >/dev/null
        [ $(( n % 5 )) -eq 0 ] && api GET "/products/999999" >/dev/null   # 404
        local cust p1 p2
        cust="${CUSTOMERS[$(rnd ${#CUSTOMERS[@]})]}"
        p1="${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}"; p2="${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}"
        api POST /orders "{\"customer_id\":$cust,\"status\":\"pending\",\"items\":[{\"product_id\":$p1,\"qty\":$(( RANDOM%3+1 ))},{\"product_id\":$p2,\"qty\":$(( RANDOM%3+1 ))}]}" >/dev/null
        api GET /orders >/dev/null
        [ $(( n % 3 )) -eq 0 ] && api PUT "/products/${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}" "{\"price\":$(( RANDOM%9000+100 )).50,\"stock\":$(rnd 500)}" >/dev/null
        [ $(( n % 7 )) -eq 0 ] && api POST /products '{"name":"sin sku ni precio"}' >/dev/null   # 422
        sleep 0.2
    done
}

scenario_blog() {
    local AUTHORS=() POSTS=() id body
    echo "==> [blog] Sembrando autores (4)"
    for i in $(seq 1 4); do
        body=$(api POST /authors "{\"name\":\"Autor $i\",\"email\":\"autor$i-$RANDOM@example.com\",\"bio\":\"Escribe sobre el tema $i\"}")
        id="$(jval "$body" id)"; [ -n "$id" ] && AUTHORS+=("$id")
    done
    [ ${#AUTHORS[@]} -gt 0 ] || { echo "ERROR: no se pudo sembrar autores"; exit 1; }

    echo "==> [blog] $ITER iteraciones..."
    for n in $(seq 1 "$ITER"); do
        api GET /authors >/dev/null
        api GET /posts   >/dev/null
        # crear post de un autor aleatorio
        local au status pid
        au="${AUTHORS[$(rnd ${#AUTHORS[@]})]}"
        status=$([ $(( RANDOM % 2 )) -eq 0 ] && echo published || echo draft)
        body=$(api POST /posts "{\"author_id\":$au,\"title\":\"Post $n-$RANDOM\",\"slug\":\"post-$n-$RANDOM\",\"body\":\"Lorem ipsum $n\",\"status\":\"$status\",\"views\":$(rnd 500)}")
        pid="$(jval "$body" id)"
        [ -n "$pid" ] && POSTS+=("$pid")
        # comentar un post existente
        if [ ${#POSTS[@]} -gt 0 ]; then
            local tp="${POSTS[$(rnd ${#POSTS[@]})]}"
            api GET "/posts/$tp" >/dev/null
            api POST /comments "{\"post_id\":$tp,\"author_name\":\"Lector $(rnd 1000)\",\"body\":\"Buen articulo $n\",\"approved\":$([ $(( RANDOM % 2 )) -eq 0 ] && echo true || echo false)}" >/dev/null
            [ $(( n % 3 )) -eq 0 ] && api PUT "/posts/$tp" "{\"status\":\"published\",\"views\":$(rnd 999)}" >/dev/null
        fi
        api GET /comments >/dev/null
        [ $(( n % 5 )) -eq 0 ] && api GET "/posts/999999" >/dev/null                         # 404
        [ $(( n % 7 )) -eq 0 ] && api POST /authors '{"name":"sin email"}' >/dev/null         # 422
        sleep 0.2
    done
}

# Valido para cualquier paquete: usa la superficie universal (users + auth/me) + errores.
scenario_generic() {
    echo "==> [generic] $ITER iteraciones (users + auth + errores)"
    for n in $(seq 1 "$ITER"); do
        api GET /auth/me >/dev/null
        api GET /users   >/dev/null
        api GET "/users/999999" >/dev/null                                                    # 404
        [ $(( n % 4 )) -eq 0 ] && api POST /users '{"email":"x"}' >/dev/null                   # 422
        sleep 0.2
    done
}

# ------------------------------------------------------------------------- main

echo "==> Deploy: $BASE"
echo "==> Login $EMAIL"
LOGIN="$("${CURL[@]}" -X POST "$BASE/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")"
TOKEN="$(jval "$LOGIN" token)"
[ -n "$TOKEN" ] || { echo "ERROR: login fallo: $LOGIN"; exit 1; }
echo "    token ${TOKEN:0:16}..."

case "${SCENARIO:-$SUB}" in
    shop) scenario_shop ;;
    blog) scenario_blog ;;
    *)    scenario_generic ;;
esac

echo
echo "==> Resumen de status codes:"
sort "$TALLY_FILE" | uniq -c | while read -r count code; do
    printf '    %s : %d\n' "$code" "$count"
done
echo "==> Hecho. Corre el rollup/measure en el deploy y sincroniza desde el Commander."

#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Genera trafico autenticado realista contra un deploy (tenant) para alimentar
# el metering de Core (core_request_logs) y poder ver Logs/Usage reales en el
# Commander. Mezcla CRUD de Shop (products/customers/orders) con algunos errores
# (404/422) para variar metodos y status.
#
# Uso:   bash scripts/simulate-traffic.sh <subdominio> [iteraciones]
# Ej:    bash scripts/simulate-traffic.sh shop 40
#
# Credenciales: por defecto el admin sembrado (admin@snake.com / secret1234).
# Override con ADMIN_EMAIL / ADMIN_PASSWORD en el entorno.
#
# Resuelve <sub>.<BASE_DOMAIN>:<HTTPS_PORT> a 127.0.0.1 (nginx publica el puerto
# en el host), asi que no depende de la entrada de hosts del SO.
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

# jq si esta; si no, python3 como fallback para extraer .id
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

declare -A TALLY
note() { TALLY["$1"]=$(( ${TALLY["$1"]:-0} + 1 )); }

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

echo "==> Deploy: $BASE"
echo "==> Login $EMAIL"
LOGIN="$("${CURL[@]}" -X POST "$BASE/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")"
TOKEN="$(jval "$LOGIN" token)"
[ -n "$TOKEN" ] || { echo "ERROR: login fallo: $LOGIN"; exit 1; }
echo "    token ${TOKEN:0:16}..."

PRODUCTS=(); CUSTOMERS=()

echo "==> Sembrando catalogo (5 productos, 4 clientes)"
for i in $(seq 1 5); do
    body=$(api POST /products "{\"name\":\"Producto $i\",\"sku\":\"SKU-$i-$RANDOM\",\"price\":$(( RANDOM%9000+100 )).99,\"stock\":$(rnd 200)}")
    id="$(jval "$body" id)"; [ -n "$id" ] && PRODUCTS+=("$id")
done
for i in $(seq 1 4); do
    body=$(api POST /customers "{\"name\":\"Cliente $i\",\"email\":\"cliente$i-$RANDOM@example.com\",\"phone\":\"+34 600 000 00$i\"}")
    id="$(jval "$body" id)"; [ -n "$id" ] && CUSTOMERS+=("$id")
done
echo "    productos: ${PRODUCTS[*]:-(ninguno)}"
echo "    clientes:  ${CUSTOMERS[*]:-(ninguno)}"

[ ${#PRODUCTS[@]} -gt 0 ] && [ ${#CUSTOMERS[@]} -gt 0 ] || { echo "ERROR: no se pudo sembrar catalogo"; exit 1; }

echo "==> Generando $ITER iteraciones de trafico..."
for n in $(seq 1 "$ITER"); do
    api GET /products  >/dev/null
    api GET /customers >/dev/null

    # show de un producto existente, y a veces uno inexistente (404)
    api GET "/products/${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}" >/dev/null
    [ $(( n % 5 )) -eq 0 ] && api GET "/products/999999" >/dev/null   # 404

    # crear un pedido enlazando cliente + 1-2 productos
    cust="${CUSTOMERS[$(rnd ${#CUSTOMERS[@]})]}"
    p1="${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}"
    p2="${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}"
    api POST /orders "{\"customer_id\":$cust,\"status\":\"pending\",\"items\":[{\"product_id\":$p1,\"qty\":$(( RANDOM%3+1 ))},{\"product_id\":$p2,\"qty\":$(( RANDOM%3+1 ))}]}" >/dev/null
    api GET /orders >/dev/null

    # cada 3: actualizar precio/stock de un producto
    if [ $(( n % 3 )) -eq 0 ]; then
        api PUT "/products/${PRODUCTS[$(rnd ${#PRODUCTS[@]})]}" "{\"price\":$(( RANDOM%9000+100 )).50,\"stock\":$(rnd 500)}" >/dev/null
    fi
    # cada 7: POST invalido (422) para variar status
    [ $(( n % 7 )) -eq 0 ] && api POST /products '{"name":"sin sku ni precio"}' >/dev/null

    sleep 0.2
done

echo
echo "==> Resumen de status codes:"
for code in $(printf '%s\n' "${!TALLY[@]}" | sort); do
    printf '    %s : %d\n' "$code" "${TALLY[$code]}"
done
echo "==> Hecho. Corre el rollup/measure en el deploy y sincroniza desde el Commander."

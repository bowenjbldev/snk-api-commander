#!/usr/bin/env bash
# Coste común visto desde fuera: oha contra nginx por la red compose (mismo patrón que
# scripts/octane-ab.sh), tres endpoints del mismo inquilino que solo se diferencian en la
# parte común: público, autenticado y autenticado con permiso. Alterna rondas y guarda el JSON.
#
#   LABEL=antes bash scripts/common-cost-http.sh
#   C=10 Z=20s ROUNDS=2 LABEL=despues bash scripts/common-cost-http.sh
set -euo pipefail

PROJECT="${PROJECT:-$(cd "$(dirname "$0")/.." && pwd)}"
HOST="${BENCH_HOST:-demo.commander.test}"
USER_EMAIL="${BENCH_USER:-admin@snake.com}"
C="${C:-10}"
Z="${Z:-20s}"
ROUNDS="${ROUNDS:-2}"
WARM="${WARM:-3s}"
LABEL="${LABEL:-run}"
OHA_IMAGE="${OHA_IMAGE:-ghcr.io/hatoo/oha:latest}"
OUT="${OUT:-/tmp/commander-common-cost-$LABEL-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"

dc() { docker compose -f "$PROJECT/docker-compose.yml" "$@"; }

# Como simulate.sh: el freno de la API (240/min por IP o usuario) se levanta recreando solo
# `app` con un override de Compose, y se restaura al salir. nginx conserva la IP de api-app.
PRIV="$(mktemp -d)"
printf 'services:\n  app:\n    environment:\n      CORE_API_RATE_LIMIT: "1000000"\n' >"$PRIV/capacity.yml"
wait_app() {
    local i
    for i in $(seq 1 60); do
        [[ "$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$HOST:8443:127.0.0.1" "https://$HOST:8443/api/v1/kilkana/public/site")" == 200 ]] && return 0
        sleep 1
    done
    echo "app no responde tras recrearla" >&2; return 1
}
restore_app() { dc up -d --no-deps --force-recreate app >/dev/null 2>&1; wait_app; rm -rf "$PRIV"; }
echo "recreando app sin freno de API..."
dc -f "$PRIV/capacity.yml" up -d --no-deps --force-recreate app >/dev/null 2>&1
trap 'echo "restaurando app..."; restore_app' EXIT
wait_app
NET="$(docker inspect "$(dc ps -q nginx)" --format '{{range $k, $_ := .NetworkSettings.Networks}}{{$k}}{{end}}')"

# Token para BENCH_USER en la base del inquilino; se borra al salir.
token_php() {
    dc exec -T -e BENCH_HOST="$HOST" -e BENCH_USER="$USER_EMAIL" -e MODE="$1" -e TOKEN_ID="${2:-0}" app php <<'PHP'
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
    ->whereHas('application', fn ($q) => $q->where('slug', 'kilkana'))
    ->firstOrFail();
config([
    'database.connections.tenant.database' => $instance->database,
    'database.connections.tenant.username' => $instance->database_username,
    'database.connections.tenant.password' => $instance->database_password,
]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');
if (getenv('MODE') === 'delete') {
    DB::table('personal_access_tokens')->where('id', (int) getenv('TOKEN_ID'))->delete();
    exit;
}
$user = CoreUserModel::query()->where('email', getenv('BENCH_USER'))->firstOrFail();
$token = $user->createToken('bench-http', ['api'], now()->addHours(2));
echo $token->accessToken->id, "\t", $token->plainTextToken, "\n";
PHP
}

IFS=$'\t' read -r TOKEN_ID TOKEN < <(token_php create | tail -n 1)
[[ "$TOKEN_ID" =~ ^[0-9]+$ ]] || { echo "No se pudo crear el token" >&2; exit 1; }
trap 'token_php delete "$TOKEN_ID" >/dev/null 2>&1 || true; echo "restaurando app..."; restore_app' EXIT

oha_run() { # nombre, path, auth(0/1), duración → JSON en $OUT
    local name="$1" path="$2" auth="$3" duration="$4" file="$5"
    local args=(--no-tui --insecure --output-format json -c "$C" -z "$duration" --host "$HOST" -H 'Accept: application/json')
    (( auth == 0 )) || args+=(-H "Authorization: Bearer $TOKEN")
    docker run --rm --network "$NET" "$OHA_IMAGE" "${args[@]}" "https://nginx:443$path" >"$file" 2>/dev/null
}

SCENARIOS=(
    "publico|/api/v1/kilkana/public/site|0"
    "auth-me|/api/v1/kilkana/auth/me|1"
    "permiso-tags|/api/v1/kilkana/tags|1"
)

printf '%-14s %-6s %8s %8s %8s %8s  %s\n' escenario ronda rps p50ms p95ms p99ms status
for round in $(seq 1 "$ROUNDS"); do
    for entry in "${SCENARIOS[@]}"; do
        IFS='|' read -r name path auth <<<"$entry"
        oha_run "$name" "$path" "$auth" "$WARM" /dev/null
        file="$OUT/$name-$round.json"
        oha_run "$name" "$path" "$auth" "$Z" "$file"
        jq -r --arg n "$name" --arg r "$round" '
            [$n, $r, (.summary.requestsPerSec*100|round/100),
             (.latencyPercentiles.p50*100000|round/100), (.latencyPercentiles.p95*100000|round/100), (.latencyPercentiles.p99*100000|round/100),
             (.statusCodeDistribution|to_entries|map("\(.key)=\(.value)")|join(","))]
            | "\(.[0]|.+"              "|.[0:14]) \(.[1]|.+"      "|.[0:6]) \(.[2]|tostring|.+"        "|.[0:8]) \(.[3]|tostring|.+"        "|.[0:8]) \(.[4]|tostring|.+"        "|.[0:8]) \(.[5]|tostring|.+"        "|.[0:8])  \(.[6])"' "$file"
    done
done

echo
echo "medianas por escenario ($ROUNDS rondas):"
for entry in "${SCENARIOS[@]}"; do
    IFS='|' read -r name _ _ <<<"$entry"
    jq -rs --arg n "$name" '
        def med: sort | if length % 2 == 1 then .[length/2|floor] else (.[length/2-1] + .[length/2]) / 2 end;
        "  \($n): \(map(.summary.requestsPerSec)|med|.*100|round/100) rps · p50 \(map(.latencyPercentiles.p50)|med|.*100000|round/100) ms · p95 \(map(.latencyPercentiles.p95)|med|.*100000|round/100) ms"' "$OUT"/"$name"-*.json
done
echo "JSON en $OUT"

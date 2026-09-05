#!/usr/bin/env bash
set -euo pipefail

# A/B de runtimes HTTP —php-fpm contra Octane (FrankenPHP, Swoole)— sobre la misma API, el
# mismo nginx y el mismo TLS, con las cuatro preguntas que decide la adopción: cuánto rinde,
# si mezcla inquilinos, si la memoria aguanta cien mil peticiones y dónde está el punto dulce
# de memoria y workers. Presta de simulate.sh todo lo que ya existe —usuarios efímeros, tokens,
# preflight de cuotas, modo capacidad, muestreo de recursos— y añade oha como generador con
# keepalive real, que es lo que enseña el techo cuando curl-por-proceso ya es el cuello.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/simulate.sh
source "$ROOT_DIR/scripts/simulate.sh"

RUNTIMES="${RUNTIMES:-fpm:8443:app franken:8444:octane swoole:8445:octane-swoole}"
ITERATIONS="${ITERATIONS:-3}"
OHA_DURATION="${OHA_DURATION:-20s}"
OHA_IMAGE="${OHA_IMAGE:-ghcr.io/hatoo/oha:latest}"
SOAK_REQUESTS="${SOAK_REQUESTS:-100000}"
ISOLATION_ROUNDS="${ISOLATION_ROUNDS:-100}"
TUNE_MEMORY="${TUNE_MEMORY:-2g 3g 4g}"
TUNE_WORKERS="${TUNE_WORKERS:-8 12 16}"

AB_FILE=""
COMPOSE_NET=""
declare -a RT_NAMES=()
declare -A RT_PORT=()
declare -A RT_SERVICE=()
declare -A RT_INTERNAL_PORT=()
declare -A SITE_NAME=()
declare -A POSTS_COUNT=()

# --- runtimes ----------------------------------------------------------------------------

parse_runtimes() {
    local entry name port service fpm_port
    fpm_port="$(env_value "$ROOT_DIR/.env" HTTPS_PORT)"
    fpm_port="${fpm_port:-443}"
    for entry in $RUNTIMES; do
        IFS=: read -r name port service <<<"$entry"
        [[ "$name" =~ ^[a-z0-9-]+$ && "$port" =~ ^[0-9]+$ && "$service" =~ ^[a-z0-9-]+$ ]] \
            || { echo "Bad runtime entry: $entry (expected name:port:service)" >&2; exit 2; }
        RT_NAMES+=("$name")
        RT_PORT["$name"]="$port"
        RT_SERVICE["$name"]="$service"
        # oha corre dentro de la red compose, donde nginx escucha en 443 y no en HTTPS_PORT.
        RT_INTERNAL_PORT["$name"]="$port"
        [[ "$port" != "$fpm_port" ]] || RT_INTERNAL_PORT["$name"]=443
    done
}

# Levanta los perfiles que hagan falta y espera a que cada runtime conteste por su puerto.
ensure_runtimes() {
    local name profiles=()
    for name in "${RT_NAMES[@]}"; do
        case "${RT_SERVICE[$name]}" in
            octane) profiles+=(--profile octane) ;;
            octane-swoole) profiles+=(--profile swoole) ;;
        esac
    done
    (( ${#profiles[@]} == 0 )) || dc "${profiles[@]}" up -d --no-build >/dev/null
    for name in "${RT_NAMES[@]}"; do
        TENANT_PORT="${RT_PORT[$name]}" wait_app || { echo "Runtime $name is not answering on ${RT_PORT[$name]}." >&2; exit 1; }
    done
    export COMPOSE_PROFILES=octane,swoole
}

# Modo capacidad (frenos de rate limit fuera) en todos los runtimes a la vez: conviven, y
# recrear un contenedor por iteración tiraría el estado caliente que precisamente se mide.
ab_capacity() {
    local mode="$1" name
    for name in "${RT_NAMES[@]}"; do
        if [[ "$mode" == on ]]; then
            BENCH_SERVICE="${RT_SERVICE[$name]}" TENANT_PORT="${RT_PORT[$name]}" enable_capacity_mode
        else
            BENCH_SERVICE="${RT_SERVICE[$name]}" TENANT_PORT="${RT_PORT[$name]}" CAPACITY_ACTIVE=1 disable_capacity_mode
        fi
    done
    [[ "$mode" == on ]] && CAPACITY_ACTIVE=1 || CAPACITY_ACTIVE=0
}

ab_cleanup() {
    local exit_code=$?
    set +e
    (( CAPACITY_ACTIVE == 0 )) || ab_capacity off
    CAPACITY_ACTIVE=0
    cleanup_all
    exit "$exit_code"
}

# --- oha ------------------------------------------------------------------------------------

# oha contra nginx por la red compose, con el Host del inquilino y el certificado local.
# Argumentos: runtime, host, path, fichero de auth (o vacío), concurrencia, y el resto va a oha
# tal cual (-z duración o -n peticiones). Escribe el JSON en el fichero indicado por OHA_OUT.
oha_run() {
    local name="$1" host="$2" path="$3" auth_file="$4" concurrency="$5"; shift 5
    local args=(--no-tui --insecure --output-format json -c "$concurrency" --host "$host" -H 'Accept: application/json') token
    if [[ -n "$auth_file" ]]; then
        token="$(sed -n 's/^header = "Authorization: Bearer \(.*\)"$/\1/p' "$auth_file")"
        args+=(-H "Authorization: Bearer $token")
    fi
    docker run --rm --network "$COMPOSE_NET" "$OHA_IMAGE" "${args[@]}" "$@" \
        "https://nginx:${RT_INTERNAL_PORT[$name]}$path" >"$OHA_OUT" 2>/dev/null
}

# Una fila de ab.csv a partir de uno o dos JSON de oha (dos = RPS sumado, p95/p99 máximo).
oha_row() {
    local name="$1" iteration="$2" scenario="$3" concurrency="$4" resources="$5"; shift 5
    local row peaks
    row="$(jq -rs '
        def pct(f): (map(.latencyPercentiles[f]) | max) * 1000;
        def codes(cond): map(.statusCodeDistribution | to_entries | map(select(.key | tonumber | cond) | .value) | add // 0) | add;
        [ (map(.summary.requestsPerSec) | add),
          pct("p50"), pct("p95"), pct("p99"),
          codes(. >= 500),
          # Las peticiones en vuelo al vencer `-z` cuentan como «aborted due to deadline»: no son fallos.
          (codes(. < 200 or . >= 300) + (map(.errorDistribution | to_entries | map(select(.key | test("deadline") | not) | .value) | add // 0) | add))
        ] | map(if type == "number" then (. * 100 | round / 100) else . end) | @csv' "$@")"
    peaks="$(awk -F, 'NR>1 {for(i=2;i<=4;i++) if($i+0>max[i]) max[i]=$i+0} END{printf "%.2f,%.2f,%.2f",max[2],max[3],max[4]}' "$resources")"
    printf '%s,%s,%s,oha,%s,%s,%s\n' "$name" "$iteration" "$scenario" "$concurrency" "$row" "$peaks" >>"$AB_FILE"
    printf '  %-7s %-12s c=%-3s %s\n' "$name" "$scenario" "$concurrency" "$row"
}

# Muestrea CPU/memoria del runtime mientras corre lo que se le pase como comando.
with_sampling() {
    local name="$1" resources="$2"; shift 2
    local stop sampler
    stop="$PRIVATE_DIR/sample.$BASHPID.stop"
    rm -f "$stop"
    BENCH_SERVICE="${RT_SERVICE[$name]}" sample_resources "$stop" "$resources" &
    sampler=$!
    "$@"
    touch "$stop"
    wait "$sampler"
}

oha_warmup() {
    local name="$1" key="${KILKANA_KEYS[0]}"
    OHA_OUT=/dev/null oha_run "$name" "${TARGET_HOST[$key]}" "${TARGET_READ_PATH[$key]}" '' 8 -n 200
}

# Dos oha a la vez, uno por inquilino, sobre los mismos workers. Espera solo a esos dos: un
# `wait` a secas se quedaría también con el muestreador de recursos.
oha_pair() {
    local out_a="$1" out_b="$2" name="$3" host_a="$4" host_b="$5" path="$6" concurrency="$7"; shift 7
    local pid_a pid_b
    OHA_OUT="$out_a" oha_run "$name" "$host_a" "$path" '' "$concurrency" "$@" &
    pid_a=$!
    OHA_OUT="$out_b" oha_run "$name" "$host_b" "$path" '' "$concurrency" "$@" &
    pid_b=$!
    wait "$pid_a" "$pid_b"
}

# Última fila de summary.csv (la que acaba de escribir run_load) copiada a ab.csv con runtime.
curl_row() {
    local name="$1" iteration="$2" scenario="$3"
    awk -F, -v rt="$name" -v it="$iteration" -v sc="$scenario" 'END {
        printf "%s,%s,%s,curl,%s,%s,%.2f,%.2f,%.2f,%s,%s,%s,%s,%s\n", rt, it, sc, $3, $6, $7*1000, $8*1000, $9*1000, $10, $11+$12, $13, $14, $15
    }' "$SUMMARY_FILE" >>"$AB_FILE"
}

# --- bench -------------------------------------------------------------------------------

bench_runtime() {
    local name="$1" iteration="$2" a="${KILKANA_KEYS[0]}" b="${KILKANA_KEYS[1]}" res
    TENANT_PORT="${RT_PORT[$name]}"
    BENCH_SERVICE="${RT_SERVICE[$name]}"
    oha_warmup "$name"

    res="$OUTPUT_DIR/$name-$iteration-read-single-c10-resources.csv"
    OHA_OUT="$OUTPUT_DIR/$name-$iteration-read-single-c10.json"
    with_sampling "$name" "$res" oha_run "$name" "${TARGET_HOST[$a]}" "${TARGET_READ_PATH[$a]}" '' 10 -z "$OHA_DURATION"
    oha_row "$name" "$iteration" read-single 10 "$res" "$OHA_OUT"

    res="$OUTPUT_DIR/$name-$iteration-read-single-c25-resources.csv"
    OHA_OUT="$OUTPUT_DIR/$name-$iteration-read-single-c25.json"
    with_sampling "$name" "$res" oha_run "$name" "${TARGET_HOST[$a]}" "${TARGET_READ_PATH[$a]}" '' 25 -z "$OHA_DURATION"
    oha_row "$name" "$iteration" read-single 25 "$res" "$OHA_OUT"

    # Dos inquilinos a la vez sobre los mismos workers: cada uno cambia de base en cada turno.
    res="$OUTPUT_DIR/$name-$iteration-read-cross-c10-resources.csv"
    with_sampling "$name" "$res" oha_pair "$OUTPUT_DIR/$name-$iteration-read-cross-a.json" "$OUTPUT_DIR/$name-$iteration-read-cross-b.json" \
        "$name" "${TARGET_HOST[$a]}" "${TARGET_HOST[$b]}" "${TARGET_READ_PATH[$a]}" 5 -z "$OHA_DURATION"
    oha_row "$name" "$iteration" read-cross 10 "$res" "$OUTPUT_DIR/$name-$iteration-read-cross-a.json" "$OUTPUT_DIR/$name-$iteration-read-cross-b.json"

    # Escritura, login y mixto necesitan cuerpos únicos por petición: generador curl heredado.
    run_load write single 10 300 >/dev/null;        curl_row "$name" "$iteration" write
    run_load login single 8 200 >/dev/null;         curl_row "$name" "$iteration" login
    run_load mixed same-package 5 300 >/dev/null;   curl_row "$name" "$iteration" mixed
    tail -n 3 "$AB_FILE" | awk -F, '{printf "  %-7s %-12s c=%-3s rps=%s p95=%sms 5xx=%s\n",$1,$3,$5,$6,$8,$10}'
}

bench() {
    local iteration name
    ab_capacity on
    for iteration in $(seq 1 "$ITERATIONS"); do
        echo "Iteration $iteration/$ITERATIONS"
        for name in "${RT_NAMES[@]}"; do
            bench_runtime "$name" "$iteration"
        done
    done
    ab_capacity off
}

# --- isolation ---------------------------------------------------------------------------

# Cuerpo JSON de una petición, o vacío si no contesta.
curl_body() {
    local host="$1" path="$2" config_file="${3:-}" method="${4:-GET}" body="${5:-}"
    local args=(-ksS --resolve "$host:$TENANT_PORT:127.0.0.1" --connect-timeout 5 --max-time "$CURL_TIMEOUT" -X "$method" -H 'Accept: application/json')
    [[ -z "$config_file" ]] || args+=(-K "$config_file")
    [[ -z "$body" ]] || args+=(-H 'Content-Type: application/json' --data "$body")
    curl "${args[@]}" "$(url_for "$host" "$path")" 2>/dev/null || true
}

isolation_expect() {
    local log="$1" round="$2" step="$3" expected="$4" actual="$5"
    [[ "$expected" == "$actual" ]] || printf 'round=%s step=%s expected=%s got=%s\n' "$round" "$step" "$expected" "$actual" >>"$log"
}

# Un bucle: alterna A → B → fantasma → A con curl secuencial, comprobando que cada respuesta
# pertenece al inquilino del host. Con varios bucles a la vez cada worker ve inquilinos
# distintos en turnos consecutivos, que es justo lo que un worker persistente puede mezclar.
isolation_loop() {
    local log="$1" a="${KILKANA_KEYS[0]}" b="${KILKANA_KEYS[1]}" round body
    local ha="${TARGET_HOST[$a]}" hb="${TARGET_HOST[$b]}" ta="${TARGET_AUTH_FILE[$a]}" tb="${TARGET_AUTH_FILE[$b]}"
    for round in $(seq 1 "$ISOLATION_ROUNDS"); do
        body="$(curl_body "$ha" /api/v1/kilkana/auth/me "$ta")"
        isolation_expect "$log" "$round" me-a "${BENCH_EMAIL[$a]}" "$(jq -r '.email // .user.email // "none"' <<<"$body" 2>/dev/null || echo parse)"
        isolation_expect "$log" "$round" token-a-on-b 401 "$(curl_status "$hb" /api/v1/kilkana/auth/me "$ta")"
        body="$(curl_body "$hb" /api/v1/kilkana/auth/me "$tb")"
        isolation_expect "$log" "$round" me-b "${BENCH_EMAIL[$b]}" "$(jq -r '.email // .user.email // "none"' <<<"$body" 2>/dev/null || echo parse)"
        isolation_expect "$log" "$round" ghost 404 "$(curl_status "ghost.$TENANT_BASE_DOMAIN" /api/v1/kilkana/public/posts)"
        body="$(curl_body "$hb" '/api/v1/kilkana/public/posts?count=1')"
        isolation_expect "$log" "$round" posts-b "${POSTS_COUNT[$b]}" "$(jq -r 'if type=="array" then length else "shape" end' <<<"$body" 2>/dev/null || echo parse)"
        isolation_expect "$log" "$round" invalid-write-a 422 "$(curl_status "$ha" /api/v1/kilkana/tags "$ta" POST '{}')"
        body="$(curl_body "$hb" /api/v1/kilkana/public/site)"
        isolation_expect "$log" "$round" site-b "${SITE_NAME[$b]}" "$(jq -r '.tenant.name // "none"' <<<"$body" 2>/dev/null || echo parse)"
        # La cabecera que pisa el Host no puede elegir inquilino: manda el Host y solo el Host.
        body="$(curl -ksS --resolve "$ha:$TENANT_PORT:127.0.0.1" --max-time "$CURL_TIMEOUT" -H 'Accept: application/json' \
            -H "X-Forwarded-Host: $hb" "$(url_for "$ha" /api/v1/kilkana/public/site)" 2>/dev/null || true)"
        isolation_expect "$log" "$round" forwarded-host-a "${SITE_NAME[$a]}" "$(jq -r '.tenant.name // "none"' <<<"$body" 2>/dev/null || echo parse)"
        # Desconexión a mitad de respuesta y, acto seguido, identidad en el mismo inquilino.
        curl -ksS --resolve "$ha:$TENANT_PORT:127.0.0.1" --max-time 0.05 -K "$ta" -o /dev/null \
            "$(url_for "$ha" '/api/v1/kilkana/public/posts?count=50')" 2>/dev/null || true
        body="$(curl_body "$ha" /api/v1/kilkana/public/site)"
        isolation_expect "$log" "$round" site-a-after-abort "${SITE_NAME[$a]}" "$(jq -r '.tenant.name // "none"' <<<"$body" 2>/dev/null || echo parse)"
    done
}

# Doce fallos de login en A deben frenar A y no B: la clave del freno lleva el inquilino.
isolation_throttle() {
    local log="$1" a="${KILKANA_KEYS[0]}" b="${KILKANA_KEYS[1]}" payload attempt limited=0
    payload="$(jq -nc '{email:"nobody@example.test",password:"deliberately-wrong"}')"
    for attempt in $(seq 1 12); do
        [[ "$(curl_status "${TARGET_HOST[$a]}" /api/v1/kilkana/auth/login '' POST "$payload")" != 429 ]] || limited=$((limited + 1))
    done
    (( limited > 0 )) || printf 'step=throttle-a expected=429 got=none-in-12\n' >>"$log"
    isolation_expect "$log" 0 login-b-after-throttle-a 200 \
        "$(curl_status "${TARGET_HOST[$b]}" /api/v1/kilkana/auth/login '' POST "@${TARGET_LOGIN_FILE[$b]}")"
}

isolation_runtime() {
    local name="$1" log="$OUTPUT_DIR/isolation-$name.txt" concurrency i pids failures
    TENANT_PORT="${RT_PORT[$name]}"
    export TENANT_PORT
    for concurrency in 1 2 4 8; do
        pids=()
        for i in $(seq 1 "$concurrency"); do
            isolation_loop "$log" &
            pids+=($!)
        done
        wait "${pids[@]}"
    done
    failures="$(wc -l <"$log")"
    if (( failures == 0 )); then
        printf 'PASS  %-7s isolation: %s alternating rounds at c=1,2,4,8 with no cross-tenant answer\n' "$name" "$ISOLATION_ROUNDS"
        echo "isolation,$name,PASS" >>"$OUTPUT_DIR/verdicts.csv"
    else
        printf 'FAIL  %-7s isolation: %s mismatches, see %s\n' "$name" "$failures" "$log"
        head -n 5 "$log"
        echo "isolation,$name,FAIL,$failures" >>"$OUTPUT_DIR/verdicts.csv"
    fi
}

# El freno por inquilino se comprueba con los límites normales; los bucles de alternancia van
# muy por encima de 240 peticiones/minuto y corren con los frenos fuera.
isolation() {
    local name
    for name in "${RT_NAMES[@]}"; do
        : >"$OUTPUT_DIR/isolation-$name.txt"
        TENANT_PORT="${RT_PORT[$name]}" isolation_throttle "$OUTPUT_DIR/isolation-$name.txt"
    done
    ab_capacity on
    for name in "${RT_NAMES[@]}"; do
        isolation_runtime "$name"
    done
    ab_capacity off
}

# --- soak --------------------------------------------------------------------------------

# Cien mil peticiones repartidas entre dos inquilinos con reciclaje cada 500: la memoria del
# último tramo no puede superar en más de un 15 % la del primero, y no puede haber ningún
# fallo. FPM corre lo mismo como control.
soak_runtime() {
    local name="$1" a="${KILKANA_KEYS[0]}" b="${KILKANA_KEYS[1]}" half=$((SOAK_REQUESTS / 2))
    local res="$OUTPUT_DIR/soak-$name-resources.csv" verdict
    TENANT_PORT="${RT_PORT[$name]}"
    SAMPLE_SECONDS=5 with_sampling "$name" "$res" oha_pair "$OUTPUT_DIR/soak-$name-a.json" "$OUTPUT_DIR/soak-$name-b.json" \
        "$name" "${TARGET_HOST[$a]}" "${TARGET_HOST[$b]}" "${TARGET_READ_PATH[$a]}" 4 -n "$half"
    verdict="$(jq -rs --arg name "$name" '
        (map(.statusCodeDistribution | to_entries | map(select(.key != "200") | .value) | add // 0) | add) as $bad
        | (map(.errorDistribution | to_entries | map(select(.key | test("deadline") | not) | .value) | add // 0) | add) as $errors
        | (map(.summary.requestsPerSec) | add) as $rps
        | "\($rps * 100 | round / 100),\($bad + $errors)"' "$OUTPUT_DIR/soak-$name-a.json" "$OUTPUT_DIR/soak-$name-b.json")"
    verdict="$verdict,$(awk -F, 'NR>1 {mem[NR-1]=$3; n=NR-1}
        END {
            if (n < 10) { print "0,0,SHORT"; exit }
            for (i=int(n*0.1)+1; i<=int(n*0.3); i++) { early+=mem[i]; ec++ }
            for (i=int(n*0.8)+1; i<=n; i++) { late+=mem[i]; lc++ }
            early/=ec; late/=lc
            printf "%.1f,%.1f,%s", early, late, (late <= early * 1.15 ? "STABLE" : "GROWING")
        }' "$res")"
    IFS=, read -r rps bad early late trend <<<"$verdict"
    if [[ "$bad" == 0 && "$trend" == STABLE ]]; then
        printf 'PASS  %-7s soak: %s requests at %s rps, memory %s→%s MiB, no failures\n' "$name" "$SOAK_REQUESTS" "$rps" "$early" "$late"
        echo "soak,$name,PASS,$rps,$early,$late" >>"$OUTPUT_DIR/verdicts.csv"
    else
        printf 'FAIL  %-7s soak: %s non-200/errors, memory %s→%s MiB (%s)\n' "$name" "$bad" "$early" "$late" "$trend"
        echo "soak,$name,FAIL,$rps,$early,$late,$bad,$trend" >>"$OUTPUT_DIR/verdicts.csv"
    fi
}

soak() {
    local name
    ab_capacity on
    for name in "${RT_NAMES[@]}"; do
        oha_warmup "$name"
        soak_runtime "$name"
    done
    ab_capacity off
}

# --- tune --------------------------------------------------------------------------------

# Barrido de memoria del contenedor × workers para cada runtime Octane: lectura a c=25 con oha
# y mixto con curl por celda. El punto dulce es la celda de más RPS en lectura cuyo p95 no
# supera el doble del mejor y sin fallos; la memoria que no mejora nada sobra.
tune_runtime() {
    local name="$1" a="${KILKANA_KEYS[0]}" memory workers res iteration=0
    [[ "${RT_SERVICE[$name]}" != app ]] || return 0
    TENANT_PORT="${RT_PORT[$name]}"
    BENCH_SERVICE="${RT_SERVICE[$name]}"
    for memory in $TUNE_MEMORY; do
        for workers in $TUNE_WORKERS; do
            iteration=$((iteration + 1))
            OCTANE_CONTAINER_MEMORY="$memory" OCTANE_WORKERS="$workers" enable_capacity_mode >/dev/null
            oha_warmup "$name"
            res="$OUTPUT_DIR/tune-$name-$memory-w$workers-resources.csv"
            OHA_OUT="$OUTPUT_DIR/tune-$name-$memory-w$workers.json"
            with_sampling "$name" "$res" oha_run "$name" "${TARGET_HOST[$a]}" "${TARGET_READ_PATH[$a]}" '' 25 -z "$OHA_DURATION"
            oha_row "$name" "$iteration" "tune-read-$memory-w$workers" 25 "$res" "$OHA_OUT"
            run_load mixed same-package 10 300 >/dev/null
            curl_row "$name" "$iteration" "tune-mixed-$memory-w$workers"
        done
    done
    # Vuelve a los valores del compose.
    OCTANE_CONTAINER_MEMORY= OCTANE_WORKERS= disable_capacity_mode >/dev/null
    CAPACITY_ACTIVE=0
    awk -F, -v rt="$name" '$1==rt && $3 ~ /^tune-read-/ && $10==0 && $11==0 { if ($8 < bestp95 || bestp95 == 0) bestp95 = $8 }
        $1==rt && $3 ~ /^tune-read-/ && $10==0 && $11==0 && $8 <= bestp95 * 2 && $6 > best { best=$6; cell=$3; p95=$8; mem=$13 }
        END { if (best > 0) printf "sweet_spot,%s,%s,rps=%s,p95=%sms,mem_peak=%sMiB\n", rt, cell, best, p95, mem }' "$AB_FILE" \
        | tee -a "$OUTPUT_DIR/verdicts.csv"
}

tune() {
    local name
    for name in "${RT_NAMES[@]}"; do
        tune_runtime "$name"
    done
}

# --- report ------------------------------------------------------------------------------

compare_report() {
    local report="$OUTPUT_DIR/compare.md"
    {
        echo "# A/B $RUN_ID"
        echo
        echo "Mediana de $ITERATIONS iteraciones. RPS y p95 (ms) por runtime; 5xx y memoria pico (MiB) del contenedor."
        echo
        awk -F, -v runtimes="${RT_NAMES[*]}" '
            function median(arr, n,   i, j, t) {
                for (i = 2; i <= n; i++) { t = arr[i]; j = i - 1; while (j > 0 && arr[j] > t) { arr[j+1] = arr[j]; j-- } arr[j+1] = t }
                return (n % 2) ? arr[(n+1)/2] : (arr[n/2] + arr[n/2+1]) / 2
            }
            BEGIN { split(runtimes, rt, " ") }
            NR > 1 && $3 !~ /^tune-/ {
                key = $3 "|" $4 "|" $5
                if (!(key in seen)) { seen[key] = 1; order[++k] = key }
                n = ++cnt[key, $1]
                rps[key, $1, n] = $6; p95[key, $1, n] = $8; err[key, $1] += $10; mem[key, $1] = ($13 > mem[key, $1] ? $13 : mem[key, $1])
            }
            END {
                printf "| escenario | gen | c |"; for (i = 1; i in rt; i++) printf " RPS %s |", rt[i]; printf " Δ vs %s |", rt[1]
                for (i = 1; i in rt; i++) printf " p95 %s |", rt[i]; printf " 5xx | mem pico |\n"
                printf "|---|---|---|"; for (i = 1; i in rt; i++) printf "---|"; printf "---|"; for (i = 1; i in rt; i++) printf "---|"; printf "---|---|\n"
                for (o = 1; o <= k; o++) {
                    key = order[o]; split(key, parts, "|")
                    printf "| %s | %s | %s |", parts[1], parts[2], parts[3]
                    base = 0
                    for (i = 1; i in rt; i++) {
                        n = cnt[key, rt[i]]; for (j = 1; j <= n; j++) tmp[j] = rps[key, rt[i], j]
                        m = (n ? median(tmp, n) : 0); if (i == 1) base = m
                        printf " %.1f |", m
                    }
                    printf " "
                    for (i = 2; i in rt; i++) {
                        n = cnt[key, rt[i]]; for (j = 1; j <= n; j++) tmp[j] = rps[key, rt[i], j]
                        m = (n ? median(tmp, n) : 0)
                        printf "%s%+.0f%%", (i > 2 ? " / " : ""), (base ? (m / base - 1) * 100 : 0)
                    }
                    printf " |"
                    for (i = 1; i in rt; i++) {
                        n = cnt[key, rt[i]]; for (j = 1; j <= n; j++) tmp[j] = p95[key, rt[i], j]
                        printf " %.0f |", (n ? median(tmp, n) : 0)
                    }
                    e = ""; mm = ""
                    for (i = 1; i in rt; i++) { e = e (i > 1 ? "/" : "") err[key, rt[i]] + 0; mm = mm (i > 1 ? "/" : "") sprintf("%.0f", mem[key, rt[i]]) }
                    printf " %s | %s |\n", e, mm
                }
            }' "$AB_FILE"
        if [[ -s "$OUTPUT_DIR/verdicts.csv" ]]; then
            echo
            echo '## Veredictos'
            echo
            sed 's/^/- /' "$OUTPUT_DIR/verdicts.csv"
        fi
        if grep -q ',tune-' "$AB_FILE" 2>/dev/null; then
            echo
            echo '## Barrido memoria × workers'
            echo
            echo '| runtime | celda | c | RPS | p95 ms | 5xx | mem pico |'
            echo '|---|---|---|---|---|---|---|'
            awk -F, 'NR>1 && $3 ~ /^tune-/ { printf "| %s | %s | %s | %s | %s | %s | %s |\n", $1, $3, $5, $6, $8, $10, $13 }' "$AB_FILE"
        fi
    } >"$report"
    echo
    cat "$report"
}

# --- main --------------------------------------------------------------------------------

ab_setup() {
    local key
    parse_runtimes
    setup
    trap ab_cleanup EXIT INT TERM
    ensure_runtimes
    docker run --rm "$OHA_IMAGE" --version >/dev/null || { echo "Cannot run $OHA_IMAGE." >&2; exit 1; }
    COMPOSE_NET="$(docker inspect "$(dc ps -q nginx)" --format '{{range $k, $_ := .NetworkSettings.Networks}}{{$k}}{{end}}')"
    AB_FILE="$OUTPUT_DIR/ab.csv"
    printf 'runtime,iteration,scenario,generator,c,rps,p50_ms,p95_ms,p99_ms,http_5xx,non2xx,svc_cpu_peak,svc_mem_peak_mib,db_cpu_peak\n' >"$AB_FILE"
    : >"$OUTPUT_DIR/verdicts.csv"
    declare -gA BENCH_EMAIL=()
    for key in "${KILKANA_KEYS[@]}"; do
        BENCH_EMAIL["$key"]="$(jq -r '.email' "${TARGET_LOGIN_FILE[$key]}")"
        SITE_NAME["$key"]="$(curl_body "${TARGET_HOST[$key]}" /api/v1/kilkana/public/site | jq -r '.tenant.name // "none"')"
        POSTS_COUNT["$key"]="$(curl_body "${TARGET_HOST[$key]}" '/api/v1/kilkana/public/posts?count=1' | jq -r 'if type=="array" then length else "shape" end')"
    done
    [[ "${SITE_NAME[${KILKANA_KEYS[0]}]}" != "${SITE_NAME[${KILKANA_KEYS[1]}]}" ]] \
        || echo 'Notice: both Kilkana tenants share a site name; identity checks rely on tokens and post counts.'
}

ab_usage() {
    cat <<'EOF'
Usage:
  bash scripts/octane-ab.sh bench       # lectura (oha), cruzada, escritura, login y mixto por runtime
  bash scripts/octane-ab.sh isolation   # alternancia estricta de inquilinos a c=1,2,4,8 + freno por inquilino
  bash scripts/octane-ab.sh soak        # 100k peticiones con reciclaje de workers, memoria estable
  bash scripts/octane-ab.sh tune        # barrido memoria × workers de los runtimes Octane
  bash scripts/octane-ab.sh all         # isolation, bench, soak, tune

Overrides: RUNTIMES="fpm:8443:app franken:8444:octane swoole:8445:octane-swoole", ITERATIONS=3,
OHA_DURATION=20s, SOAK_REQUESTS=100000, ISOLATION_ROUNDS=100, TUNE_MEMORY="2g 3g 4g",
TUNE_WORKERS="8 12 16", OUTPUT_DIR.
EOF
}

ab_main() {
    local command="${1:-all}"
    [[ "$command" == help || "$command" == --help || "$command" == -h ]] && { ab_usage; return; }
    [[ "$command" =~ ^(bench|isolation|soak|tune|all)$ ]] || { ab_usage; return 2; }
    ab_setup
    case "$command" in
        bench) bench ;;
        isolation) isolation ;;
        soak) soak ;;
        tune) tune ;;
        all) isolation; bench; soak; tune ;;
    esac
    compare_report
    echo "Results: $OUTPUT_DIR"
}

ab_main "$@"

#!/usr/bin/env bash
set -euo pipefail

# Load and isolation harness for the local multi-tenant API. It deliberately uses only tools
# already present in the repository's development environment: bash, curl, jq, awk and Docker.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT_PATH="$ROOT_DIR/scripts/simulate.sh"
RUN_ID="${RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)-$$}"
REQUESTS="${REQUESTS:-300}"
WARMUP_REQUESTS="${WARMUP_REQUESTS:-10}"
CONCURRENCIES="${CONCURRENCIES:-1 5 10 25 50}"
SAMPLE_SECONDS="${SAMPLE_SECONDS:-1}"
CURL_TIMEOUT="${CURL_TIMEOUT:-15}"
TARGET_RPS="${TARGET_RPS:-100}"
OUTPUT_DIR="${OUTPUT_DIR:-/tmp/commander-benchmark-$RUN_ID}"

TENANT_PORT="${TENANT_PORT:-}"
# Servicio compose que sirve la API medida: `app` (Octane/FrankenPHP), `fpm` u `octane-swoole`.
# Decide a quién se le cambian los frenos en modo capacidad y a quién se le muestrea CPU/memoria.
BENCH_SERVICE="${BENCH_SERVICE:-app}"
TENANT_BASE_DOMAIN="${TENANT_BASE_DOMAIN:-}"
BASE_DOMAIN="${BASE_DOMAIN:-}"
PRIVATE_DIR="${COMMANDER_BENCH_PRIVATE_DIR:-}"
BENCH_USERS_FILE=""
CAPACITY_ACTIVE=0
CAPACITY_OVERRIDE=""
MANIFEST=""
SUMMARY_FILE=""

declare -a TARGET_KEYS=()
declare -a KILKANA_KEYS=()
declare -A TARGET_HOST=()
declare -A TARGET_APP=()
declare -A TARGET_READ_PATH=()
declare -A TARGET_AUTH_FILE=()
declare -A TARGET_DB=()
declare -A TARGET_LOGIN_FILE=()

dc() { docker compose "$@"; }
dbq() { dc exec -T db mariadb -uroot "$@"; }

env_value() {
    local file="$1" key="$2"
    sed -n "s/^${key}=//p" "$file" 2>/dev/null | tail -n 1
}

url_for() {
    local host="$1" path="$2" suffix=""
    [[ "$TENANT_PORT" == "443" ]] || suffix=":$TENANT_PORT"
    printf 'https://%s%s%s' "$host" "$suffix" "$path"
}

curl_status() {
    local host="$1" path="$2" config_file="${3:-}" method="${4:-GET}" body="${5:-}"
    local status args=(-ksS --resolve "$host:$TENANT_PORT:127.0.0.1" --connect-timeout 5 --max-time "$CURL_TIMEOUT"
        -o /dev/null -w '%{http_code}' -X "$method")
    [[ -z "$config_file" ]] || args+=(-K "$config_file")
    [[ -z "$body" ]] || args+=(-H 'Content-Type: application/json' --data "$body")
    status="$(curl "${args[@]}" "$(url_for "$host" "$path")" 2>/dev/null || true)"
    [[ "$status" =~ ^[0-9]{3}$ ]] || status=000
    printf '%s' "$status"
}

http_request() {
    local target="$1" app="$2" operation="$3" host="$4" path="$5" config_file="$6"
    local method="$7" body="$8" response_file="$9" results="${10}"
    local metrics rc code connect ttfb total bytes
    local args=(-ksS --resolve "$host:$TENANT_PORT:127.0.0.1" --connect-timeout 5 --max-time "$CURL_TIMEOUT"
        -K "$config_file" -X "$method" -o "$response_file"
        -w '%{http_code},%{time_connect},%{time_starttransfer},%{time_total},%{size_download}')
    [[ -z "$body" ]] || args+=(-H 'Content-Type: application/json' --data "$body")

    set +e
    metrics="$(curl "${args[@]}" "$(url_for "$host" "$path")" 2>/dev/null)"
    rc=$?
    set -e
    IFS=, read -r code connect ttfb total bytes <<<"$metrics"
    [[ "$code" =~ ^[0-9]{3}$ ]] || code=000
    connect="${connect:-0}"
    ttfb="${ttfb:-0}"
    total="${total:-0}"
    bytes="${bytes:-0}"
    printf '%s,%s,%s,%s,%s,%s,%s,%s,%s\n' \
        "$target" "$app" "$operation" "$code" "$connect" "$ttfb" "$total" "$bytes" "$rc" >>"$results"
}

worker_read() {
    local target="$1" host="$2" app="$3" path="$4" config_file="$5" results="$6"
    http_request "$target" "$app" read "$host" "$path" "$config_file" GET '' /dev/null "$results"
}

worker_write() {
    local target="$1" host="$2" app="$3" config_file="$4" login_file="$5" results="$6" sequence="$7"
    local marker="bench-${RUN_ID}-${BASHPID}-${sequence}" response id create_path delete_path payload
    response="$(mktemp "$PRIVATE_DIR/response.XXXXXX")"

    if [[ "$app" == "kilkana" ]]; then
        create_path="/api/v1/kilkana/tags"
        payload="$(jq -nc --arg name "$marker" '{name:$name,slug:$name}')"
        http_request "$target" "$app" create "$host" "$create_path" "$config_file" POST "$payload" "$response" "$results"
        id="$(jq -r '.id // empty' "$response" 2>/dev/null || true)"
        if [[ "$id" =~ ^[0-9]+$ ]]; then
            delete_path="$create_path/$id"
            http_request "$target" "$app" delete "$host" "$delete_path" "$config_file" DELETE '' /dev/null "$results"
        fi
    else
        create_path="/api/v1/demo-app/auth/login"
        http_request "$target" "$app" login "$host" "$create_path" "$config_file" POST "@$login_file" "$response" "$results"
        payload="$(jq -r '.token // empty' "$response" 2>/dev/null || true)"
        if [[ -n "$payload" ]]; then
            delete_path="$(mktemp "$PRIVATE_DIR/logout.XXXXXX")"
            printf 'header = "Accept: application/json"\nheader = "Authorization: Bearer %s"\n' "$payload" >"$delete_path"
            chmod 600 "$delete_path"
            http_request "$target" "$app" logout "$host" '/api/v1/demo-app/auth/logout' "$delete_path" POST '' /dev/null "$results"
            rm -f "$delete_path"
        fi
    fi
    rm -f "$response"
}

worker_login() {
    local target="$1" host="$2" app="$3" login_file="$4" results="$5" response
    response="$(mktemp "$PRIVATE_DIR/login-load.XXXXXX")"
    http_request "$target" "$app" login "$host" "/api/v1/$app/auth/login" /dev/null POST \
        "@$login_file" "$response" "$results"
    rm -f "$response"
}

worker_request() {
    local profile="$1" scenario_file="$2" results="$3" sequence="$4"
    local count line_number target host app path config_file login_file
    count="$(wc -l <"$scenario_file")"
    (( count > 0 )) || exit 0
    line_number=$(( (sequence - 1) % count + 1 ))
    IFS=$'\t' read -r target host app path config_file login_file < <(sed -n "${line_number}p" "$scenario_file")

    case "$profile" in
        read) worker_read "$target" "$host" "$app" "$path" "$config_file" "$results" ;;
        write) worker_write "$target" "$host" "$app" "$config_file" "$login_file" "$results" "$sequence" ;;
        login) worker_login "$target" "$host" "$app" "$login_file" "$results" ;;
        mixed)
            if (( sequence % 20 == 0 )); then
                worker_write "$target" "$host" "$app" "$config_file" "$login_file" "$results" "$sequence"
            else
                worker_read "$target" "$host" "$app" "$path" "$config_file" "$results"
            fi
            ;;
        *) exit 2 ;;
    esac
}

if [[ "${1:-}" == "_request" ]]; then
    worker_request "$2" "$3" "$4" "$5"
    exit 0
fi

pass() { printf 'PASS  %s\n' "$1" | tee -a "$OUTPUT_DIR/security.txt"; }
warn() { printf 'WARN  %s\n' "$1" | tee -a "$OUTPUT_DIR/security.txt"; }
fail() { printf 'FAIL  %s\n' "$1" | tee -a "$OUTPUT_DIR/security.txt"; SECURITY_FAILURES=$((SECURITY_FAILURES + 1)); }

require_tools() {
    local tool
    for tool in docker curl jq awk sort xargs sed; do
        command -v "$tool" >/dev/null || { echo "Missing required tool: $tool" >&2; exit 1; }
    done
    dc ps >/dev/null
}

wait_app() {
    local host="api.$BASE_DOMAIN" attempt
    for attempt in $(seq 1 60); do
        if [[ "$(curl_status "$host" '/api/v1/')" == "200" ]]; then
            return 0
        fi
        sleep 1
    done
    echo 'The app did not become healthy within 60 seconds.' >&2
    return 1
}

discover_targets() {
    local client app database key
    while IFS=$'\t' read -r client app database; do
        [[ "$database" =~ ^[a-z0-9_]{1,64}$ ]] || { echo "Unsafe tenant database: $database" >&2; exit 1; }
        key="$client-$app"
        case "$app" in
            kilkana)
                TARGET_READ_PATH["$key"]='/api/v1/kilkana/public/posts?count=10'
                KILKANA_KEYS+=("$key")
                ;;
            demo-app) TARGET_READ_PATH["$key"]='/api/v1/demo-app/auth/me' ;;
            *) continue ;;
        esac
        TARGET_KEYS+=("$key")
        TARGET_HOST["$key"]="$client.$TENANT_BASE_DOMAIN"
        TARGET_APP["$key"]="$app"
        TARGET_DB["$key"]="$database"
    done < <(dbq -N commander -e "SELECT c.slug,a.slug,i.database
        FROM commander_instances i
        JOIN commander_clients c ON c.id=i.client_id
        JOIN commander_applications a ON a.id=i.application_id
        WHERE i.provisioning_status='ready' AND i.service_status='active'
        ORDER BY i.id;")

    (( ${#KILKANA_KEYS[@]} >= 2 )) || { echo 'At least two active Kilkana tenants are required.' >&2; exit 1; }
}

create_benchmark_identity() {
    local key="$1" database="${TARGET_DB[$1]}" email password hash id role_count
    email="bench+$RUN_ID.$key@example.test"
    password="Bench-$(tr -d '-' </proc/sys/kernel/random/uuid)-9a"
    hash="$(printf '%s' "$password" | dc exec -T app php -r '
        require "vendor/autoload.php";
        $app=require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo Illuminate\Support\Facades\Hash::make(stream_get_contents(STDIN));')"
    role_count="$(dbq -N "$database" -e "SELECT COUNT(*) FROM roles WHERE name='admin' AND guard_name='sanctum';" | tr -d '[:space:]')"
    [[ "$role_count" == "1" ]] || { echo "Tenant $key has no unique admin role." >&2; exit 1; }
    id="$(dbq -N "$database" -e "START TRANSACTION;
        INSERT INTO users (name,email,password,created_at,updated_at) VALUES ('Commander benchmark','$email','$hash',NOW(),NOW());
        SET @benchmark_user_id=LAST_INSERT_ID();
        INSERT INTO model_has_roles (role_id,model_type,model_id)
          SELECT id,(SELECT model_type FROM model_has_roles ORDER BY model_id LIMIT 1),@benchmark_user_id
          FROM roles WHERE name='admin' AND guard_name='sanctum';
        SELECT @benchmark_user_id;
        COMMIT;" | tail -n 1 | tr -d '[:space:]')"
    [[ "$id" =~ ^[0-9]+$ ]] || { echo "Could not create benchmark identity for $key." >&2; exit 1; }
    printf '%s\t%s\n' "$database" "$id" >>"$BENCH_USERS_FILE"
    TARGET_BENCH_EMAIL="$email"
    TARGET_BENCH_PASSWORD="$password"
}

login_target() {
    local key="$1" app="${TARGET_APP[$1]}" host="${TARGET_HOST[$1]}" email password response status token file payload
    if [[ "$app" == "kilkana" ]]; then
        email="${KILKANA_ADMIN_EMAIL:-}"
        password="${KILKANA_ADMIN_PASSWORD:-}"
    else
        email="${DEMOAPP_ADMIN_EMAIL:-}"
        password="${DEMOAPP_ADMIN_PASSWORD:-}"
    fi
    if [[ -z "$email" && -z "$password" ]]; then
        create_benchmark_identity "$key"
        email="$TARGET_BENCH_EMAIL"
        password="$TARGET_BENCH_PASSWORD"
    elif [[ -z "$email" || -z "$password" ]]; then
        echo "Both email and password must be provided for $app." >&2
        exit 1
    fi
    response="$(mktemp "$PRIVATE_DIR/login.XXXXXX")"
    payload="$(jq -nc --arg email "$email" --arg password "$password" '{email:$email,password:$password}')"
    status="$(curl -ksS --resolve "$host:$TENANT_PORT:127.0.0.1" --connect-timeout 5 --max-time "$CURL_TIMEOUT" \
        -o "$response" -w '%{http_code}' -H 'Accept: application/json' -H 'Content-Type: application/json' \
        --data "$payload" "$(url_for "$host" "/api/v1/$app/auth/login")" 2>/dev/null || true)"
    token="$(jq -r '.token // empty' "$response" 2>/dev/null || true)"
    rm -f "$response"
    if [[ "$status" != "200" || -z "$token" ]]; then
        echo "Login failed for $key ($email); provide the matching *_ADMIN_EMAIL/PASSWORD variables." >&2
        exit 1
    fi
    file="$PRIVATE_DIR/$key.curl"
    printf 'header = "Accept: application/json"\nheader = "Authorization: Bearer %s"\n' "$token" >"$file"
    chmod 600 "$file"
    TARGET_AUTH_FILE["$key"]="$file"
    file="$PRIVATE_DIR/$key-login.json"
    printf '%s' "$payload" >"$file"
    chmod 600 "$file"
    TARGET_LOGIN_FILE["$key"]="$file"
}

write_scenarios() {
    local key
    MANIFEST="$PRIVATE_DIR/targets.tsv"
    : >"$MANIFEST"
    for key in "${TARGET_KEYS[@]}"; do
        printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$key" "${TARGET_HOST[$key]}" "${TARGET_APP[$key]}" \
            "${TARGET_READ_PATH[$key]}" "${TARGET_AUTH_FILE[$key]}" "${TARGET_LOGIN_FILE[$key]}" >>"$MANIFEST"
    done
    cp "$MANIFEST" "$PRIVATE_DIR/cross-package.tsv"
    : >"$PRIVATE_DIR/same-package.tsv"
    for key in "${KILKANA_KEYS[@]}"; do
        grep -F "${key}"$'\t' "$MANIFEST" >>"$PRIVATE_DIR/same-package.tsv"
    done
    grep -F "${KILKANA_KEYS[0]}"$'\t' "$MANIFEST" >"$PRIVATE_DIR/single.tsv"
}

logout_tokens() {
    local key host app config_file
    [[ -f "$MANIFEST" ]] || return 0
    while IFS=$'\t' read -r key host app _ config_file; do
        curl -ksS --resolve "$host:$TENANT_PORT:127.0.0.1" --connect-timeout 2 --max-time 5 \
            -K "$config_file" -X POST -o /dev/null "$(url_for "$host" "/api/v1/$app/auth/logout")" 2>/dev/null || true
    done <"$MANIFEST"
}

cleanup_records() {
    local key database
    [[ "$RUN_ID" =~ ^[A-Za-z0-9-]+$ ]] || return 0
    for key in "${KILKANA_KEYS[@]}"; do
        database="${TARGET_DB[$key]:-}"
        [[ "$database" =~ ^[a-z0-9_]{1,64}$ ]] || continue
        dbq "$database" -e "DELETE FROM kilkana_tags WHERE slug LIKE 'bench-$RUN_ID-%';" \
            </dev/null >/dev/null 2>&1 || true
    done
}

cleanup_benchmark_identities() {
    local database id
    [[ -f "$BENCH_USERS_FILE" ]] || return 0
    while IFS=$'\t' read -r database id; do
        [[ "$database" =~ ^[a-z0-9_]{1,64}$ && "$id" =~ ^[0-9]+$ ]] || continue
        dbq "$database" -e "START TRANSACTION;
            DELETE FROM personal_access_tokens WHERE tokenable_id=$id;
            DELETE FROM model_has_permissions WHERE model_id=$id;
            DELETE FROM model_has_roles WHERE model_id=$id;
            DELETE FROM users WHERE id=$id AND email LIKE 'bench+%@example.test';
            COMMIT;" </dev/null >/dev/null 2>&1 || true
    done <"$BENCH_USERS_FILE"
}

disable_capacity_mode() {
    (( CAPACITY_ACTIVE == 1 )) || return 0
    echo 'Restoring normal API rate limits...'
    dc up -d --no-deps --force-recreate "$BENCH_SERVICE" >/dev/null
    CAPACITY_ACTIVE=0
    wait_app
}

cleanup_all() {
    local exit_code=$?
    set +e
    cleanup_records
    logout_tokens
    cleanup_benchmark_identities
    disable_capacity_mode
    [[ -z "$PRIVATE_DIR" ]] || rm -rf "$PRIVATE_DIR"
    exit "$exit_code"
}

setup() {
    cd "$ROOT_DIR"
    require_tools
    [[ "$RUN_ID" =~ ^[A-Za-z0-9-]+$ ]] || { echo 'RUN_ID may contain only letters, numbers and hyphens.' >&2; exit 1; }
    BASE_DOMAIN="${BASE_DOMAIN:-$(env_value "$ROOT_DIR/src/.env" BASE_DOMAIN)}"
    BASE_DOMAIN="${BASE_DOMAIN:-snakepc.test}"
    TENANT_BASE_DOMAIN="${TENANT_BASE_DOMAIN:-$(env_value "$ROOT_DIR/src/.env" TENANT_BASE_DOMAIN)}"
    TENANT_BASE_DOMAIN="${TENANT_BASE_DOMAIN:-$BASE_DOMAIN}"
    TENANT_PORT="${TENANT_PORT:-$(env_value "$ROOT_DIR/.env" HTTPS_PORT)}"
    TENANT_PORT="${TENANT_PORT:-443}"
    [[ "$BASE_DOMAIN" == *.test && "$TENANT_BASE_DOMAIN" == *.test ]] || {
        echo 'Refusing to run: this harness is restricted to local .test domains.' >&2
        exit 1
    }
    dc exec -T app php artisan env --no-ansi 2>/dev/null | grep -qi 'local' || {
        echo 'Refusing to run: APP_ENV must be local.' >&2
        exit 1
    }
    mkdir -p "$OUTPUT_DIR"
    chmod 700 "$OUTPUT_DIR"
    PRIVATE_DIR="$(mktemp -d /tmp/commander-benchmark-private.XXXXXX)"
    chmod 700 "$PRIVATE_DIR"
    BENCH_USERS_FILE="$PRIVATE_DIR/benchmark-users.tsv"
    : >"$BENCH_USERS_FILE"
    SUMMARY_FILE="$OUTPUT_DIR/summary.csv"
    printf 'profile,scenario,concurrency,http_requests,elapsed_s,rps,p50_s,p95_s,p99_s,http_5xx,timeouts,http_429,app_cpu_peak,app_memory_peak_mib,db_cpu_peak,db_memory_peak_mib,threads_connected_peak,threads_running_peak,max_used_connections\n' >"$SUMMARY_FILE"
    export TENANT_PORT TENANT_BASE_DOMAIN BASE_DOMAIN RUN_ID PRIVATE_DIR CURL_TIMEOUT
    export COMMANDER_BENCH_PRIVATE_DIR="$PRIVATE_DIR"
    trap cleanup_all EXIT INT TERM
    discover_targets
    local key
    for key in "${TARGET_KEYS[@]}"; do login_target "$key"; done
    write_scenarios
    environment_report
    quota_preflight
}

environment_report() {
    local app_id db_id
    app_id="$(dc ps -q "$BENCH_SERVICE")"
    db_id="$(dc ps -q db)"
    {
        echo "measured_at=$(date -u +%FT%TZ)"
        echo "host_cpus=$(nproc)"
        echo "host_memory=$(free -h | awk '/^Mem:/{print $2}')"
        docker inspect "$app_id" "$db_id" --format '{{.Name}} memory_limit={{.HostConfig.Memory}} nano_cpus={{.HostConfig.NanoCpus}}'
        if [[ "$BENCH_SERVICE" == "fpm" ]]; then
            dc exec -T fpm sh -lc "php-fpm -tt 2>&1 | grep -E 'pm =|pm.max_children|pm.max_requests'"
        else
            dc exec -T "$BENCH_SERVICE" php artisan octane:status --no-ansi 2>&1 | tail -n 1
            docker inspect "$app_id" --format 'octane_command={{join .Config.Cmd " "}}'
        fi
        dc exec -T "$BENCH_SERVICE" php -i | grep -E '^memory_limit =>|^opcache.enable =>|^opcache.enable_cli =>|^opcache.memory_consumption =>'
        dbq -N -e "SELECT CONCAT('mariadb_version=',@@version),CONCAT('max_connections=',@@max_connections),CONCAT('innodb_buffer_pool=',@@innodb_buffer_pool_size),CONCAT('thread_cache_size=',@@thread_cache_size),CONCAT('wait_timeout=',@@wait_timeout);"
        dbq -N commander -e "SELECT CONCAT('ready_instances=',COUNT(*)) FROM commander_instances WHERE provisioning_status='ready';"
    } >"$OUTPUT_DIR/environment.txt"
}

quota_preflight() {
    local blockers finite
    blockers="$(dbq -N commander -e "SELECT COUNT(*) FROM commander_quotas q
        JOIN commander_application_metrics m ON m.id=q.metric_id
        WHERE m.key='requests_day' AND q.action='suspend' AND q.limit_value IS NOT NULL;" | tr -d '[:space:]')"
    finite="$(dbq -N commander -e "SELECT COUNT(*) FROM commander_quotas q
        JOIN commander_application_metrics m ON m.id=q.metric_id
        WHERE m.key='requests_day' AND q.limit_value IS NOT NULL;" | tr -d '[:space:]')"
    if (( blockers > 0 )) && [[ "${ALLOW_QUOTA_EFFECTS:-0}" != "1" ]]; then
        echo 'A finite requests_day quota can suspend a target; set ALLOW_QUOTA_EFFECTS=1 to accept that risk.' >&2
        exit 1
    fi
    (( finite == 0 )) || echo "Notice: $finite target(s) have a finite requests_day quota; metering rows will remain after the run."
}

smoke() {
    local key status failures=0
    echo 'Smoke: tenant routing, SQL-backed reads and authentication'
    for key in "${TARGET_KEYS[@]}"; do
        status="$(curl_status "${TARGET_HOST[$key]}" "${TARGET_READ_PATH[$key]}" "${TARGET_AUTH_FILE[$key]}")"
        printf '  %-30s %s\n' "$key" "$status"
        [[ "$status" == "200" ]] || failures=$((failures + 1))
    done
    (( failures == 0 )) || { echo "$failures smoke target(s) failed." >&2; return 1; }
}

memory_mib() {
    local value="$1"
    awk -v value="$value" 'BEGIN {
        if (value ~ /GiB$/) { sub(/GiB$/, "", value); printf "%.3f", value * 1024 }
        else if (value ~ /MiB$/) { sub(/MiB$/, "", value); printf "%.3f", value }
        else if (value ~ /KiB$/) { sub(/KiB$/, "", value); printf "%.3f", value / 1024 }
        else { gsub(/[^0-9.]/, "", value); printf "%.3f", value / 1048576 }
    }'
}

sample_resources() {
    local stop_file="$1" output="$2" app_id db_id stats app_stats db_stats app_cpu app_mem db_cpu db_mem status
    app_id="$(dc ps -q "$BENCH_SERVICE")"
    db_id="$(dc ps -q db)"
    printf 'timestamp,app_cpu_percent,app_memory_mib,db_cpu_percent,db_memory_mib,threads_connected,threads_running,connections_total,max_used_connections\n' >"$output"
    while [[ ! -e "$stop_file" ]]; do
        mapfile -t stats < <(docker stats --no-stream --format '{{.CPUPerc}}|{{.MemUsage}}' "$app_id" "$db_id" 2>/dev/null)
        app_stats="${stats[0]:-0%|0B / 0B}"
        db_stats="${stats[1]:-0%|0B / 0B}"
        app_cpu="${app_stats%%|*}"; app_cpu="${app_cpu%%%}"
        app_mem="${app_stats#*|}"; app_mem="${app_mem%% / *}"
        db_cpu="${db_stats%%|*}"; db_cpu="${db_cpu%%%}"
        db_mem="${db_stats#*|}"; db_mem="${db_mem%% / *}"
        status="$(dbq -N -e "SELECT MAX(IF(VARIABLE_NAME='THREADS_CONNECTED',VARIABLE_VALUE,NULL)),MAX(IF(VARIABLE_NAME='THREADS_RUNNING',VARIABLE_VALUE,NULL)),MAX(IF(VARIABLE_NAME='CONNECTIONS',VARIABLE_VALUE,NULL)),MAX(IF(VARIABLE_NAME='MAX_USED_CONNECTIONS',VARIABLE_VALUE,NULL)) FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME IN ('THREADS_CONNECTED','THREADS_RUNNING','CONNECTIONS','MAX_USED_CONNECTIONS');" 2>/dev/null || printf '0\t0\t0\t0')"
        printf '%s,%s,%s,%s,%s,%s\n' "$(date -u +%FT%T.%3NZ)" "$app_cpu" "$(memory_mib "$app_mem")" \
            "$db_cpu" "$(memory_mib "$db_mem")" "${status//$'\t'/,}" >>"$output"
        sleep "$SAMPLE_SECONDS"
    done
}

percentile() {
    local sorted_file="$1" percentile="$2" count="$3"
    awk -v p="$percentile" -v n="$count" 'NR == int(n * p + 0.999999) { print; exit }' "$sorted_file"
}

summarize_run() {
    local profile="$1" scenario="$2" concurrency="$3" results="$4" resources="$5" elapsed="$6"
    local count sorted p50 p95 p99 rps errors timeouts limited peaks
    count="$(awk 'END{print NR-1}' "$results")"
    sorted="$PRIVATE_DIR/times.$BASHPID"
    awk -F, 'NR>1{print $7}' "$results" | sort -n >"$sorted"
    if (( count > 0 )); then
        p50="$(percentile "$sorted" 0.50 "$count")"
        p95="$(percentile "$sorted" 0.95 "$count")"
        p99="$(percentile "$sorted" 0.99 "$count")"
    else
        p50=0; p95=0; p99=0
    fi
    rm -f "$sorted"
    rps="$(awk -v n="$count" -v duration="$elapsed" 'BEGIN{printf "%.2f", (duration > 0 ? n/duration : 0)}')"
    errors="$(awk -F, 'NR>1 && $4+0>=500{n++} END{print n+0}' "$results")"
    timeouts="$(awk -F, 'NR>1 && ($4=="000" || $9!=0){n++} END{print n+0}' "$results")"
    limited="$(awk -F, 'NR>1 && $4=="429"{n++} END{print n+0}' "$results")"
    peaks="$(awk -F, 'NR>1 {for(i=2;i<=9;i++) if($i+0>max[i]) max[i]=$i+0} END{printf "%.2f,%.2f,%.2f,%.2f,%d,%d,%d",max[2],max[3],max[4],max[5],max[6],max[7],max[9]}' "$resources")"
    printf '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n' "$profile" "$scenario" "$concurrency" \
        "$count" "$elapsed" "$rps" "$p50" "$p95" "$p99" "$errors" "$timeouts" "$limited" "$peaks" >>"$SUMMARY_FILE"
    printf '  %-5s %-13s c=%-2s requests=%-4s rps=%-7s p95=%-8ss 5xx=%s timeout=%s 429=%s\n' \
        "$profile" "$scenario" "$concurrency" "$count" "$rps" "$p95" "$errors" "$timeouts" "$limited"
}

run_load() {
    local profile="$1" scenario="$2" concurrency="$3" count="${4:-$REQUESTS}"
    local scenario_file="$PRIVATE_DIR/$scenario.tsv" slug results resources stop sampler start end elapsed
    [[ "$profile" =~ ^(read|write|mixed|login)$ ]] || { echo 'Profile must be read, write, mixed or login.' >&2; return 2; }
    [[ -s "$scenario_file" ]] || { echo "Unknown scenario: $scenario" >&2; return 2; }
    [[ "$concurrency" =~ ^[1-9][0-9]*$ && "$count" =~ ^[1-9][0-9]*$ ]] || return 2
    slug="$profile-$scenario-c$concurrency"
    results="$OUTPUT_DIR/$slug.csv"
    resources="$OUTPUT_DIR/$slug-resources.csv"
    stop="$PRIVATE_DIR/$slug.stop"
    # El mismo slug puede correr varias veces en una sesión: sin esto el muestreador ve la parada
    # de la tanda anterior y sale sin una sola muestra.
    rm -f "$stop"
    printf 'target,package,operation,status,time_connect_s,time_starttransfer_s,time_total_s,response_bytes,curl_exit\n' >"$results"

    if (( WARMUP_REQUESTS > 0 )); then
        seq 1 "$WARMUP_REQUESTS" | xargs -P "$concurrency" -n 1 bash "$SCRIPT_PATH" _request "$profile" "$scenario_file" /dev/null
    fi
    sample_resources "$stop" "$resources" &
    sampler=$!
    start="$(date +%s%N)"
    seq 1 "$count" | xargs -P "$concurrency" -n 1 bash "$SCRIPT_PATH" _request "$profile" "$scenario_file" "$results"
    end="$(date +%s%N)"
    touch "$stop"
    wait "$sampler"
    elapsed="$(awk -v start="$start" -v end="$end" 'BEGIN{printf "%.3f", (end-start)/1000000000}')"
    summarize_run "$profile" "$scenario" "$concurrency" "$results" "$resources" "$elapsed"
}

enable_capacity_mode() {
    CAPACITY_OVERRIDE="$PRIVATE_DIR/compose.capacity.yml"
    printf 'services:\n  %s:\n    environment:\n      CORE_API_RATE_LIMIT: "%s"\n      CORE_LOGIN_RATE_LIMIT: "%s"\n' \
        "$BENCH_SERVICE" "${CAPACITY_RATE_LIMIT:-1000000}" "${CAPACITY_LOGIN_RATE_LIMIT:-1000000}" >"$CAPACITY_OVERRIDE"
    echo "Recreating only the local $BENCH_SERVICE container with benchmark rate limits..."
    docker compose -f "$ROOT_DIR/docker-compose.yml" -f "$CAPACITY_OVERRIDE" up -d --no-deps --force-recreate "$BENCH_SERVICE" >/dev/null
    CAPACITY_ACTIVE=1
    wait_app
}

capacity_report() {
    local report="$OUTPUT_DIR/capacity.txt"
    awk -F, -v target="$TARGET_RPS" '
        NR==2 { }
        NR>1 && $1=="mixed" && $2=="cross-package" {
            if (baseline==0) baseline=$8
            if ($10==0 && $11==0 && $12==0 && $8<=baseline*2 && $6>best) {best=$6; bestc=$3; bestp95=$8}
        }
        END {
            print "criterion=highest mixed/cross-package step with no 5xx, timeout or 429 and p95 <= 2x c=1 baseline"
            printf "sustainable_rps=%.2f\nconcurrency=%s\np95_s=%s\ntarget_rps=%d\n",best,bestc,bestp95,target
            printf "target_result=%s\n",best>=target?"PASS":"FAIL"
            if (best>0) printf "nodes_for_target_with_30_percent_margin=%d\n",int(target/(best*0.7)+0.999999)
        }' "$SUMMARY_FILE" >"$report"
    cat >>"$report" <<'EOF'
registered_tenants_note=100 registered tenants do not imply 100 simultaneous SQL connections; concurrency and PHP-FPM workers do. Repeat on production-sized infrastructure before deployment.
connection_model=each busy FPM worker can hold Commander and tenant connections during one request; tenant PDO is purged after every request.
EOF
    echo
    cat "$report"
}

matrix() {
    local profile concurrency
    enable_capacity_mode
    for profile in read write mixed; do
        for concurrency in $CONCURRENCIES; do
            run_load "$profile" cross-package "$concurrency" "$REQUESTS"
        done
    done
    run_load read single 5 "$REQUESTS"
    run_load read same-package 5 "$REQUESTS"
    capacity_report
    disable_capacity_mode
}

login_load() {
    local concurrency="${1:-5}" count="${2:-100}"
    [[ -z "${KILKANA_ADMIN_EMAIL:-}" && -z "${KILKANA_ADMIN_PASSWORD:-}" ]] || {
        echo 'login-load requires its ephemeral user; do not provide KILKANA_ADMIN_* credentials.' >&2
        return 2
    }
    enable_capacity_mode
    run_load login single "$concurrency" "$count"
    disable_capacity_mode
}

expect_status() {
    local expected="$1" label="$2" host="$3" path="$4" config_file="${5:-}"
    local actual
    actual="$(curl_status "$host" "$path" "$config_file")"
    if [[ "$actual" == "$expected" ]]; then pass "$label ($actual)"; else fail "$label (expected $expected, got $actual)"; fi
}

security_audit() {
    local north="${KILKANA_KEYS[0]}" centre="${KILKANA_KEYS[1]}" demo='' key violations commander_user ddl_count cors
    SECURITY_FAILURES=0
    : >"$OUTPUT_DIR/security.txt"
    for key in "${TARGET_KEYS[@]}"; do [[ "${TARGET_APP[$key]}" == 'demo-app' ]] && demo="$key"; done

    expect_status 401 'Kilkana token is rejected by another Kilkana tenant' "${TARGET_HOST[$centre]}" \
        '/api/v1/kilkana/auth/me' "${TARGET_AUTH_FILE[$north]}"
    expect_status 401 'Authenticated route rejects missing token' "${TARGET_HOST[$north]}" '/api/v1/kilkana/auth/me'
    expect_status 404 'Host cannot select an unprovisioned package' "${TARGET_HOST[$north]}" '/api/v1/demo-app/auth/me'
    expect_status 404 'Unknown tenant is hidden' "ghost.$TENANT_BASE_DOMAIN" '/api/v1/kilkana/public/posts'
    local site_name forwarded_name
    site_name="$(curl -ksS --resolve "${TARGET_HOST[$north]}:$TENANT_PORT:127.0.0.1" --max-time "$CURL_TIMEOUT" \
        "$(url_for "${TARGET_HOST[$north]}" '/api/v1/kilkana/public/site')" 2>/dev/null | jq -r '.tenant.name // "none"')"
    forwarded_name="$(curl -ksS --resolve "${TARGET_HOST[$north]}:$TENANT_PORT:127.0.0.1" --max-time "$CURL_TIMEOUT" \
        -H "X-Forwarded-Host: ${TARGET_HOST[$centre]}" \
        "$(url_for "${TARGET_HOST[$north]}" '/api/v1/kilkana/public/site')" 2>/dev/null | jq -r '.tenant.name // "none"')"
    [[ "$forwarded_name" == "$site_name" ]] && pass 'X-Forwarded-Host cannot select another tenant' \
        || fail "X-Forwarded-Host selected another tenant ($site_name became $forwarded_name)"
    if [[ -n "$demo" ]]; then
        expect_status 401 'Kilkana token is rejected by DemoApp' "${TARGET_HOST[$demo]}" \
            '/api/v1/demo-app/auth/me' "${TARGET_AUTH_FILE[$north]}"
        expect_status 401 'DemoApp token is rejected by Kilkana' "${TARGET_HOST[$north]}" \
            '/api/v1/kilkana/auth/me' "${TARGET_AUTH_FILE[$demo]}"
    else
        warn 'No DemoApp tenant: cross-package token checks skipped'
    fi

    violations="$(dbq -N -e "SELECT COUNT(*) FROM (
        SELECT i.id,GROUP_CONCAT(p.PRIVILEGE_TYPE ORDER BY p.PRIVILEGE_TYPE) privileges
        FROM commander.commander_instances i
        LEFT JOIN information_schema.SCHEMA_PRIVILEGES p
          ON p.GRANTEE=CONCAT(CHAR(39),i.database_username,CHAR(39),'@',CHAR(39),'%',CHAR(39))
         AND p.TABLE_SCHEMA=i.database
        WHERE i.provisioning_status='ready'
        GROUP BY i.id
        HAVING privileges<>'DELETE,INSERT,SELECT,UPDATE' OR privileges IS NULL
    ) violations;" | tr -d '[:space:]')"
    [[ "$violations" == "0" ]] && pass 'Runtime users have exactly CRUD on their own schema' || fail "$violations runtime grant set(s) violate least privilege"

    violations="$(dbq -N -e "SELECT COUNT(*) FROM commander.commander_instances i
        JOIN information_schema.SCHEMA_PRIVILEGES p
          ON p.GRANTEE=CONCAT(CHAR(39),i.database_username,CHAR(39),'@',CHAR(39),'%',CHAR(39))
         AND p.TABLE_SCHEMA<>i.database
        WHERE i.provisioning_status='ready';" | tr -d '[:space:]')"
    [[ "$violations" == "0" ]] && pass 'Runtime users have no cross-schema grants' || fail "$violations cross-schema runtime grant(s) found"

    commander_user="$(dc exec -T app printenv DB_USERNAME | tr -d '\r\n')"
    if [[ "$commander_user" =~ ^[a-zA-Z0-9_]+$ ]]; then
        violations="$(dbq -N -e "SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES
            WHERE GRANTEE=CONCAT(CHAR(39),'$commander_user',CHAR(39),'@',CHAR(39),'%',CHAR(39))
              AND TABLE_SCHEMA<>'commander';" | tr -d '[:space:]')"
        [[ "$violations" == "0" ]] && pass 'HTTP user has no tenant-schema grants' || fail 'HTTP user can access a tenant schema'
        ddl_count="$(dbq -N -e "SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES
            WHERE GRANTEE=CONCAT(CHAR(39),'$commander_user',CHAR(39),'@',CHAR(39),'%',CHAR(39))
              AND TABLE_SCHEMA='commander' AND PRIVILEGE_TYPE IN ('ALTER','CREATE','DROP','INDEX','TRIGGER');" | tr -d '[:space:]')"
        (( ddl_count == 0 )) && pass 'HTTP user has no Commander DDL privileges' || warn 'HTTP and migration startup share a Commander user with DDL privileges'
    else
        fail 'Could not validate the HTTP database username'
    fi

    cors="$(curl -ksS --resolve "${TARGET_HOST[$north]}:$TENANT_PORT:127.0.0.1" -D - -o /dev/null \
        -H 'Origin: https://evil.example' "$(url_for "${TARGET_HOST[$north]}" '/api/v1/kilkana/public/posts')" 2>/dev/null \
        | tr -d '\r' | awk -F': ' 'tolower($1)=="access-control-allow-origin"{print $2}')"
    [[ -z "$cors" ]] && pass 'Untrusted CORS origin is not reflected' || fail "Untrusted CORS origin was allowed: $cors"

    if [[ "$BENCH_SERVICE" == "fpm" ]]; then
        dc exec -T fpm sh -lc "php-fpm -tt 2>&1" | grep -q 'pm.max_requests = 0' \
            && warn 'PHP-FPM pm.max_requests=0 never recycles workers after memory growth' \
            || pass 'PHP-FPM recycles workers'
    else
        docker inspect "$(dc ps -q "$BENCH_SERVICE")" --format '{{join .Config.Cmd " "}}' | grep -q -- '--max-requests=[1-9]' \
            && pass 'Octane recycles workers' \
            || warn 'Octane --max-requests is unset: workers never recycle after memory growth'
    fi
    grep -Rqs 'X-Content-Type-Options' "$ROOT_DIR/docker/nginx/conf.d" \
        && pass 'Nginx declares X-Content-Type-Options' \
        || warn 'Nginx does not declare X-Content-Type-Options'
    grep -Rqs 'Strict-Transport-Security' "$ROOT_DIR/docker/nginx/conf.d" \
        && pass 'Nginx declares HSTS' \
        || warn 'Nginx leaves HSTS to the production TLS ingress'

    (( SECURITY_FAILURES == 0 )) || return 1
}

rate_limit_check() {
    local key="${KILKANA_KEYS[0]}" host email payload status successes=0 limited=0 attempt
    host="${TARGET_HOST[$key]}"
    for attempt in $(seq 1 12); do
        status="$(curl_status "$host" '/api/v1/kilkana/auth/login' '' POST "@${TARGET_LOGIN_FILE[$key]}")"
        [[ "$status" == '200' ]] && successes=$((successes + 1))
    done
    if (( successes != 12 )); then
        echo "FAIL  successful logins shared an IP budget ($successes/12 passed)" | tee "$OUTPUT_DIR/rate-limit.txt"
        return 1
    fi

    email="${KILKANA_ADMIN_EMAIL:-admin@grupo-prensa.test}"
    payload="$(jq -nc --arg email "$email" '{email:$email,password:"benchmark-deliberately-wrong"}')"
    for attempt in $(seq 1 12); do
        status="$(curl_status "$host" '/api/v1/kilkana/auth/login' '' POST "$payload")"
        [[ "$status" == '429' ]] && limited=$((limited + 1))
    done
    if (( limited > 0 )); then
        {
            echo 'PASS  successful logins do not consume the shared IP failure budget (12/12)'
            echo "PASS  failed logins returned 429 ($limited/12 attempts)"
        } | tee "$OUTPUT_DIR/rate-limit.txt"
    else
        echo 'FAIL  login limiter did not return 429 in 12 attempts' | tee "$OUTPUT_DIR/rate-limit.txt"
        return 1
    fi
}

usage() {
    cat <<'EOF'
Usage:
  bash scripts/simulate.sh smoke
  bash scripts/simulate.sh load read|write|mixed [single|same-package|cross-package] [concurrency] [operations]
  bash scripts/simulate.sh matrix
  bash scripts/simulate.sh login-load [concurrency] [operations]
  bash scripts/simulate.sh security
  bash scripts/simulate.sh rate-limit
  bash scripts/simulate.sh all

Useful overrides: REQUESTS, WARMUP_REQUESTS, CONCURRENCIES, OUTPUT_DIR,
KILKANA_ADMIN_EMAIL/PASSWORD and DEMOAPP_ADMIN_EMAIL/PASSWORD.
Point it at another runtime with TENANT_PORT=8444 BENCH_SERVICE=fpm (or 8445/octane-swoole).
EOF
}

main() {
    local command="${1:-all}"
    [[ "$command" == 'help' || "$command" == '--help' || "$command" == '-h' ]] && { usage; return; }
    setup
    case "$command" in
        smoke) smoke ;;
        load) smoke; run_load "${2:-mixed}" "${3:-cross-package}" "${4:-5}" "${5:-$REQUESTS}" ;;
        matrix) smoke; matrix ;;
        login-load) login_load "${2:-5}" "${3:-100}" ;;
        security) smoke; security_audit ;;
        rate-limit) rate_limit_check ;;
        all) smoke; security_audit; matrix; rate_limit_check ;;
        *) usage; return 2 ;;
    esac
    echo "Results: $OUTPUT_DIR"
}

# Ejecutado directamente corre el comando; `source`ado (octane-ab.sh) solo presta las funciones.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi

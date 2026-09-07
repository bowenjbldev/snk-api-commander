#!/usr/bin/env bash
# Controlled local comparison. No provisioning, no publishing, no existing accounts edited.
set -euo pipefail
umask 077
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT_DIR/scripts/simulate.sh"
SECONDS_PER_RUN="${SECONDS_PER_RUN:-45}"
ROUNDS="${ROUNDS:-4}"
[[ "$SECONDS_PER_RUN" =~ ^[1-9][0-9]*$ && "$ROUNDS" =~ ^[1-9][0-9]*$ ]] || exit 2
[[ -z "${KILKANA_ADMIN_EMAIL:-}${KILKANA_ADMIN_PASSWORD:-}${DEMOAPP_ADMIN_EMAIL:-}${DEMOAPP_ADMIN_PASSWORD:-}" ]] || {
    echo 'Only ephemeral benchmark identities are allowed.' >&2; exit 2;
}

restore_realistic() {
    local result=$? database id remaining records_clean=1
    trap - EXIT INT TERM
    set +e
    # Delete only drafts belonging to this run's temporary users; revisions cascade.
    while IFS=$'\t' read -r database id; do
        [[ "$database" =~ ^[a-z0-9_]{1,64}$ && "$id" =~ ^[0-9]+$ ]] || { result=1; continue; }
        if [[ "$database" == *_kilkana ]]; then
            dbq "$database" -e "START TRANSACTION;
                DELETE FROM kilkana_posts WHERE created_by=$id AND status='draft' AND slug LIKE 'bench-$RUN_ID-%';
                DELETE FROM kilkana_activity WHERE user_id=$id;
                COMMIT;" </dev/null || { result=1; records_clean=0; }
            remaining="$(dbq -N "$database" -e "SELECT
                (SELECT COUNT(*) FROM kilkana_posts WHERE created_by=$id AND slug LIKE 'bench-$RUN_ID-%') +
                (SELECT COUNT(*) FROM kilkana_activity WHERE user_id=$id);" </dev/null)"
            [[ "$remaining" == 0 ]] || { result=1; records_clean=0; }
        fi
    done <"$BENCH_USERS_FILE"
    cleanup_records
    logout_tokens
    # Keep ownership and the private recovery ledger if fixture cleanup fails.
    (( records_clean )) && cleanup_benchmark_identities
    while IFS=$'\t' read -r database id; do
        remaining="$(dbq -N "$database" -e "SELECT COUNT(*) FROM users WHERE id=$id;" </dev/null)"
        [[ "$remaining" == 0 ]] || result=1
    done <"$BENCH_USERS_FILE"
    if (( RUNTIME_CHANGED )); then
        dc --profile fpm up -d --no-deps --force-recreate app fpm || result=1
        dc exec -T nginx nginx -s reload || result=1
        wait_app || result=1
    fi
    (( FPM_WAS_RUNNING )) || dc --profile fpm stop fpm
    echo "Cleanup complete (status $result). Raw results: $OUTPUT_DIR"
    # Private fixture contains credentials; remove only the mktemp directory created by setup.
    if (( result == 0 )); then
        [[ "$PRIVATE_DIR" == /tmp/commander-benchmark-private.* ]] && rm -rf "$PRIVATE_DIR"
    else
        echo "Cleanup failed; private recovery files retained at $PRIVATE_DIR" >&2
    fi
    exit "$result"
}

[[ "${BASH_SOURCE[0]}" == "$0" ]] || return 0

cd "$ROOT_DIR"
FPM_WAS_RUNNING=0
[[ -z "$(dc ps --status running -q fpm)" ]] || FPM_WAS_RUNNING=1
RUNTIME_CHANGED=0
setup
trap restore_realistic EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
FRANKEN_PORT="$TENANT_PORT"
FPM_PORT="$(env_value "$ROOT_DIR/.env" FPM_HTTPS_PORT)"
FPM_PORT="${FPM_PORT:-8444}"
# Pin committed PHP code: parallel edits must not change a worker halfway through an A/B.
snapshot_commit="$(git rev-parse HEAD)"
printf '%s\n' "$snapshot_commit" >"$OUTPUT_DIR/commit.txt"
mkdir -p "$OUTPUT_DIR/source"
git archive "$snapshot_commit" src/app src/packages src/config src/routes src/bootstrap/app.php src/bootstrap/providers.php | tar -x -C "$OUTPUT_DIR/source"
# Generated config belongs to this run; original Compose and caches are untouched.
export REALISTIC_SOURCE="$(cd "$OUTPUT_DIR/source/src" && pwd)"
printf 'services:\n  app:\n    command: ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=8", "--max-requests=500"]\n    volumes: &source\n      - ${REALISTIC_SOURCE}/app:/var/www/src/app:ro\n      - ${REALISTIC_SOURCE}/packages:/var/www/src/packages:ro\n      - ${REALISTIC_SOURCE}/config:/var/www/src/config:ro\n      - ${REALISTIC_SOURCE}/routes:/var/www/src/routes:ro\n      - ${REALISTIC_SOURCE}/bootstrap/app.php:/var/www/src/bootstrap/app.php:ro\n      - ${REALISTIC_SOURCE}/bootstrap/providers.php:/var/www/src/bootstrap/providers.php:ro\n    environment: &bench\n      CORE_API_RATE_LIMIT: "1000000"\n      CORE_METERING_BATCH: "1"\n      APP_DEBUG: "false"\n      APP_CONFIG_CACHE: /tmp/realistic-config.php\n      APP_ROUTES_CACHE: /tmp/realistic-routes.php\n  fpm:\n    volumes: *source\n    environment: *bench\n' >"$PRIVATE_DIR/realistic.yml"
RUNTIME_CHANGED=1
docker compose -f docker-compose.yml -f "$PRIVATE_DIR/realistic.yml" --profile fpm up -d --no-deps --no-build --pull never app fpm
dc exec -T nginx nginx -s reload
wait_app
TENANT_PORT="$FPM_PORT" wait_app
for service in app fpm; do
    BENCH_SERVICE="$service" environment_report
    mv "$OUTPUT_DIR/environment.txt" "$OUTPUT_DIR/environment-$service.txt"
    dc exec -T "$service" php -v >"$OUTPUT_DIR/php-$service.txt"
    docker inspect "$(dc ps -q "$service")" --format '{{.Image}} {{json .Config.Cmd}}' >"$OUTPUT_DIR/image-$service.txt"
done
node scripts/realistic-load.mjs prepare "$MANIFEST" "$PRIVATE_DIR/fixture.json" "$RUN_ID" "$FRANKEN_PORT" "$FPM_PORT"
for round in $(seq 1 "$ROUNDS"); do
    order=(app fpm)
    (( round % 2 )) || order=(fpm app)
    for concurrency in 8 24; do
        for service in "${order[@]}"; do
            port="$FRANKEN_PORT"; [[ "$service" != fpm ]] || port="$FPM_PORT"
            echo "Round $round/$ROUNDS, $service, concurrency $concurrency: warming all endpoints"
            node scripts/realistic-load.mjs run "$PRIVATE_DIR/fixture.json" "$port" "$concurrency" 5 "$OUTPUT_DIR/warm-$round-$service-c$concurrency.json"
            stop="$PRIVATE_DIR/sample.stop"
            rm -f "$stop"
            BENCH_SERVICE="$service" sample_resources "$stop" "$OUTPUT_DIR/$round-$service-c$concurrency-resources.csv" &
            sampler=$!
            node scripts/realistic-load.mjs run "$PRIVATE_DIR/fixture.json" "$port" "$concurrency" "$SECONDS_PER_RUN" "$OUTPUT_DIR/$round-$service-c$concurrency.json" || {
                touch "$stop"; wait "$sampler"; exit 1;
            }
            touch "$stop"
            wait "$sampler"
        done
    done
done
node scripts/realistic-load.mjs report "$OUTPUT_DIR"

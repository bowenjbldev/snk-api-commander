#!/usr/bin/env bash
# Nginx local: no necesita seed ni perfiles FPM/Swoole levantados.
# Uso: bash scripts/check-php-exposure.sh [puerto ...]
set -euo pipefail

if (( $# == 0 )); then
    set -- 8443 8444 8445
fi

for port in "$@"; do
    for path in /index.php /index.php/foo /INDEX.PHP /index.php.bak \
        /frankenphp-worker.php /storage/media/test/v/probe.php; do
        status="$(curl --noproxy '*' --connect-to ::127.0.0.1: --insecure --silent --show-error \
            --max-time 10 --output /dev/null --write-out '%{http_code}' \
            "https://demo.commander.test:$port$path")"
        if [[ "$status" != 404 ]]; then
            printf 'FAIL :%s%s: esperado 404, recibido %s\n' "$port" "$path" "$status" >&2
            exit 1
        fi
    done
    printf 'PASS :%s: acceso directo a PHP bloqueado\n' "$port"
done

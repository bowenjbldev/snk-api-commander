#!/usr/bin/env bash
# Genera certificados SSL de confianza local con mkcert:
#   - Certificado para APP_DOMAIN (dominio principal, .env raiz)
#   - Certificado wildcard *.BASE_DOMAIN (suscripciones locales)
#
# Requiere mkcert: https://github.com/FiloSottile/mkcert
#
# Uso (desde la raiz del repo o desde cualquier sitio):
#   bash scripts/setup-ssl.sh [dominio]    # dominio opcional, default APP_DOMAIN

set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CERTS_DIR="$ROOT_DIR/docker/nginx/certs"

# Load APP_DOMAIN and BASE_DOMAIN from .env
if [ -f "$ROOT_DIR/.env" ]; then
    DOMAIN=$(grep -E "^APP_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
    BASE_DOMAIN=$(grep -E "^BASE_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
fi
DOMAIN="${1:-${DOMAIN:-localhost}}"

if ! command -v mkcert &> /dev/null; then
    echo "Error: mkcert is not installed."
    echo "Install it from https://github.com/FiloSottile/mkcert"
    exit 1
fi

echo "Installing local CA (if not already installed)..."
mkcert -install

mkdir -p "$CERTS_DIR"

echo "Generating certificate for $DOMAIN..."
mkcert \
    -cert-file "$CERTS_DIR/$DOMAIN.pem" \
    -key-file  "$CERTS_DIR/$DOMAIN-key.pem" \
    "$DOMAIN"

# Wildcard para suscripciones locales (<cliente>.<BASE_DOMAIN>)
# Nota: cubre un solo nivel (a.snake.test si, a.b.snake.test no) y no el apex.
if [ -n "${BASE_DOMAIN:-}" ]; then
    echo "Generating wildcard certificate for *.$BASE_DOMAIN..."
    mkcert \
        -cert-file "$CERTS_DIR/wildcard.$BASE_DOMAIN.pem" \
        -key-file  "$CERTS_DIR/wildcard.$BASE_DOMAIN-key.pem" \
        "*.$BASE_DOMAIN"
fi

echo ""
echo "Certs saved to $CERTS_DIR/"
echo "Now run: docker compose up -d --force-recreate nginx"
echo ""
echo "Para que el navegador de Windows confie en los certs, importa la CA de mkcert:"
echo "  certutil.exe -addstore -f ROOT \"\$(wslpath -w \"\$(mkcert -CAROOT)/rootCA.pem\")\""
echo "(si tu dominio actual ya sale verde en el navegador, la CA ya esta importada)"

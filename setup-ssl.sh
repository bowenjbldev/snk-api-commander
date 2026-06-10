#!/usr/bin/env bash
# Generates a locally-trusted SSL certificate using APP_DOMAIN from .env
# Requires mkcert: https://github.com/FiloSottile/mkcert
#
# Usage:
#   bash setup-ssl.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CERTS_DIR="$SCRIPT_DIR/docker/nginx/certs"

# Load APP_DOMAIN from .env
if [ -f "$SCRIPT_DIR/.env" ]; then
    DOMAIN=$(grep -E "^APP_DOMAIN=" "$SCRIPT_DIR/.env" | cut -d '=' -f2)
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

echo ""
echo "Certs saved to $CERTS_DIR/"
echo "Now run: docker compose up -d --force-recreate nginx"

#!/usr/bin/env bash
# Genera los certificados SSL locales que nginx necesita para arrancar:
#   - Certificado para APP_DOMAIN (dominio principal, .env raiz)
#   - Certificado wildcard *.BASE_DOMAIN (suscripciones locales)
#
# Con mkcert (recomendado): certificados de confianza local (candado verde).
#   https://github.com/FiloSottile/mkcert
# Sin mkcert: fallback self-signed con openssl — todo funciona igual pero el
#   navegador mostrara aviso de cert no confiable.
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

HAVE_MKCERT=0
if command -v mkcert >/dev/null 2>&1; then
    HAVE_MKCERT=1
    echo "Installing local CA (if not already installed)..."
    mkcert -install
else
    echo "AVISO: mkcert no esta instalado; se generaran certs self-signed con openssl."
    echo "       El navegador avisara de cert no confiable. Para candado verde:"
    echo "       https://github.com/FiloSottile/mkcert"
    command -v openssl >/dev/null 2>&1 || { echo "Error: tampoco hay openssl."; exit 1; }
fi

mkdir -p "$CERTS_DIR"

# gen_cert <prefijo-archivo> <dominio-san>
gen_cert() {
    local prefix="$1" san="$2"
    if [ "$HAVE_MKCERT" -eq 1 ]; then
        mkcert \
            -cert-file "$CERTS_DIR/$prefix.pem" \
            -key-file  "$CERTS_DIR/$prefix-key.pem" \
            "$san"
    else
        openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
            -keyout "$CERTS_DIR/$prefix-key.pem" \
            -out    "$CERTS_DIR/$prefix.pem" \
            -subj   "/CN=$san" \
            -addext "subjectAltName=DNS:$san" 2>/dev/null
    fi
}

echo "Generating certificate for $DOMAIN..."
gen_cert "$DOMAIN" "$DOMAIN"

# Wildcard para suscripciones locales (<cliente>.<BASE_DOMAIN>)
# Nota: cubre un solo nivel (a.snake.test si, a.b.snake.test no) y no el apex.
if [ -n "${BASE_DOMAIN:-}" ]; then
    echo "Generating wildcard certificate for *.$BASE_DOMAIN..."
    gen_cert "wildcard.$BASE_DOMAIN" "*.$BASE_DOMAIN"
fi

echo ""
echo "Certs saved to $CERTS_DIR/"
echo "Now run: docker compose up -d --force-recreate nginx"

if [ "$HAVE_MKCERT" -eq 1 ]; then
    echo ""
    echo "Para que el navegador de Windows confie en los certs, importa la CA de mkcert:"
    echo "  certutil.exe -addstore -f ROOT \"\$(wslpath -w \"\$(mkcert -CAROOT)/rootCA.pem\")\""
    echo "(si tu dominio actual ya sale verde en el navegador, la CA ya esta importada)"
fi

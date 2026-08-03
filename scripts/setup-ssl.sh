#!/usr/bin/env bash
# Genera los certificados SSL locales que nginx necesita para arrancar:
#   - Certificado para APP_DOMAIN (dominio principal, .env raiz)
#   - Certificado con SAN para *.BASE_DOMAIN y *.TENANT_BASE_DOMAIN
#
# Usa mkcert (certificados de confianza local, candado verde). Si no esta
# instalado, lo descarga automaticamente a ~/.local/bin (solo necesita curl).
#   https://github.com/FiloSottile/mkcert
#
# Uso (desde la raiz del repo o desde cualquier sitio):
#   bash scripts/setup-ssl.sh [dominio]    # dominio opcional, default APP_DOMAIN

set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CERTS_DIR="$ROOT_DIR/docker/nginx/certs"

# Load APP_DOMAIN, BASE_DOMAIN and TENANT_BASE_DOMAIN from .env
if [ -f "$ROOT_DIR/.env" ]; then
    DOMAIN=$(grep -E "^APP_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
    BASE_DOMAIN=$(grep -E "^BASE_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
    TENANT_BASE_DOMAIN=$(grep -E "^TENANT_BASE_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
fi
DOMAIN="${1:-${DOMAIN:-localhost}}"
TENANT_BASE_DOMAIN="${TENANT_BASE_DOMAIN:-${BASE_DOMAIN:-snakepc.test}}"

# ------------------------------------------------------------- mkcert (unico)

MKCERT="mkcert"

ensure_mkcert() {
    if command -v mkcert >/dev/null 2>&1; then
        return
    fi

    if [ -x "$HOME/.local/bin/mkcert" ]; then
        MKCERT="$HOME/.local/bin/mkcert"
        return
    fi

    command -v curl >/dev/null 2>&1 || { echo "Error: se necesita curl para descargar mkcert."; exit 1; }

    local os arch
    case "$(uname -s)" in
        Linux)  os=linux ;;
        Darwin) os=darwin ;;
        *) echo "Error: SO no soportado para auto-instalar mkcert: $(uname -s)"; exit 1 ;;
    esac
    case "$(uname -m)" in
        x86_64)          arch=amd64 ;;
        aarch64|arm64)   arch=arm64 ;;
        *) echo "Error: arquitectura no soportada: $(uname -m)"; exit 1 ;;
    esac

    echo "mkcert no encontrado: descargandolo a ~/.local/bin/mkcert..."
    mkdir -p "$HOME/.local/bin"
    curl -fsSL "https://dl.filippo.io/mkcert/latest?for=$os/$arch" -o "$HOME/.local/bin/mkcert"
    chmod +x "$HOME/.local/bin/mkcert"
    MKCERT="$HOME/.local/bin/mkcert"
}

ensure_mkcert

echo "Installing local CA (if not already installed)..."
# Puede pedir sudo para el trust store del sistema; si falla, los certs se
# generan igual (la CA queda en CAROOT y el navegador de Windows se trata abajo)
"$MKCERT" -install || echo "AVISO: no se pudo instalar la CA en el trust store local (continua)."

mkdir -p "$CERTS_DIR"

echo "Generating certificate for $DOMAIN..."
"$MKCERT" \
    -cert-file "$CERTS_DIR/$DOMAIN.pem" \
    -key-file  "$CERTS_DIR/$DOMAIN-key.pem" \
    "$DOMAIN"

# Certificado comun para Commander y tenants. Cada wildcard cubre un solo nivel.
if [ -n "${BASE_DOMAIN:-}" ]; then
    WILDCARD_NAMES=("*.$BASE_DOMAIN")
    if [ "$TENANT_BASE_DOMAIN" != "$BASE_DOMAIN" ]; then
        WILDCARD_NAMES+=("*.$TENANT_BASE_DOMAIN")
    fi
    echo "Generating certificate for ${WILDCARD_NAMES[*]}..."
    "$MKCERT" \
        -cert-file "$CERTS_DIR/wildcard.$BASE_DOMAIN.pem" \
        -key-file  "$CERTS_DIR/wildcard.$BASE_DOMAIN-key.pem" \
        "${WILDCARD_NAMES[@]}"
fi

echo ""
echo "Certs saved to $CERTS_DIR/"
echo "Now run: docker compose up -d --force-recreate nginx"

# --------------------------------------------------- Windows (WSL): confiar la CA
# Importa la CA de mkcert al store de MAQUINA para que el navegador de Windows confie
# (candado verde). Idempotente: si ya esta, no pide nada. (Una sola ventana UAC.)
if command -v powershell.exe >/dev/null 2>&1 && command -v wslpath >/dev/null 2>&1; then
    if ! certutil.exe -verifystore Root 2>/dev/null | grep -qi mkcert; then
        WIN_USER="$(powershell.exe -NoProfile -Command '$env:UserName' 2>/dev/null | tr -d '\r')"

        # Copia la CA a disco C: — un certutil elevado puede no leer \\wsl.localhost
        WIN_TMP="/mnt/c/Users/$WIN_USER/AppData/Local/Temp"
        if [ -d "$WIN_TMP" ]; then
            cp "$("$MKCERT" -CAROOT)/rootCA.pem" "$WIN_TMP/mkcert-rootCA.pem"
            CA_WIN="C:\\Users\\$WIN_USER\\AppData\\Local\\Temp\\mkcert-rootCA.pem"
        else
            CA_WIN="$(wslpath -w "$("$MKCERT" -CAROOT)/rootCA.pem")"
        fi

        echo ""
        echo "Importando la CA de mkcert al store de Windows (una ventana UAC)..."
        PS="certutil -addstore -f ROOT '$CA_WIN'"
        ENC=$(printf '%s' "$PS" | iconv -f UTF-8 -t UTF-16LE | base64 -w0)
        powershell.exe -NoProfile -Command \
            "Start-Process powershell -Verb RunAs -Wait -ArgumentList '-NoProfile','-EncodedCommand','$ENC'" \
            >/dev/null 2>&1 || true
    else
        echo "Windows ya confia en la CA de mkcert."
    fi
fi

# --------------------------------------------------- Resolucion de subdominios
# El navegador necesita resolver los dominios locales a 127.0.0.1. Opciones:
#   - Recomendado (wildcard): dnsmasq/Acrylic para ambos dominios.
#   - Simple: anadir cada subdominio al hosts de Windows (C:\Windows\System32\drivers\etc\hosts):
echo ""
echo "Para abrir los subdominios, resuelve *.$BASE_DOMAIN y *.$TENANT_BASE_DOMAIN a 127.0.0.1."
echo "Hosts minimo para esta demo: 127.0.0.1  ${DOMAIN}  commander.$BASE_DOMAIN  demo.$TENANT_BASE_DOMAIN"

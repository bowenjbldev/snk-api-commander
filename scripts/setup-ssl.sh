#!/usr/bin/env bash
# Genera los certificados SSL locales que nginx necesita para arrancar:
#   - Certificado para APP_DOMAIN (dominio principal, .env raiz)
#   - Certificado wildcard *.BASE_DOMAIN (suscripciones locales)
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

# Load APP_DOMAIN and BASE_DOMAIN from .env
if [ -f "$ROOT_DIR/.env" ]; then
    DOMAIN=$(grep -E "^APP_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
    BASE_DOMAIN=$(grep -E "^BASE_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
fi
DOMAIN="${1:-${DOMAIN:-localhost}}"

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

# Wildcard para suscripciones locales (<cliente>.<BASE_DOMAIN>)
# Nota: cubre un solo nivel (a.snake.test si, a.b.snake.test no) y no el apex.
if [ -n "${BASE_DOMAIN:-}" ]; then
    echo "Generating wildcard certificate for *.$BASE_DOMAIN..."
    "$MKCERT" \
        -cert-file "$CERTS_DIR/wildcard.$BASE_DOMAIN.pem" \
        -key-file  "$CERTS_DIR/wildcard.$BASE_DOMAIN-key.pem" \
        "*.$BASE_DOMAIN"
fi

echo ""
echo "Certs saved to $CERTS_DIR/"
echo "Now run: docker compose up -d --force-recreate nginx"

# --------------------------------------------------- Windows (WSL): UNA elevacion
# Una sola ventana UAC que deja todo listo para siempre:
#   1. Importa la CA de mkcert al store de MAQUINA (sin dialogos posteriores).
#   2. Da a tu usuario permiso de escritura (M) sobre el hosts de Windows,
#      para que subscription.sh edite el hosts directamente sin UAC.
# Idempotente: si ambas cosas ya estan hechas, no pide nada.
WIN_HOSTS="/mnt/c/Windows/System32/drivers/etc/hosts"

if command -v powershell.exe >/dev/null 2>&1 && command -v wslpath >/dev/null 2>&1; then
    NEED_ELEV=0

    # hosts escribible directamente? (append de 0 bytes: no modifica nada)
    ( : >> "$WIN_HOSTS" ) 2>/dev/null || NEED_ELEV=1

    # CA ya en el store de maquina?
    certutil.exe -verifystore Root 2>/dev/null | grep -qi mkcert || NEED_ELEV=1

    if [ "$NEED_ELEV" -eq 1 ]; then
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
        echo "Configurando Windows (UNA sola ventana UAC): CA de confianza + permiso de hosts..."
        PS="certutil -addstore -f ROOT '$CA_WIN'; icacls \$env:SystemRoot\\System32\\drivers\\etc\\hosts /grant '${WIN_USER}:(M)'"
        ENC=$(printf '%s' "$PS" | iconv -f UTF-8 -t UTF-16LE | base64 -w0)
        powershell.exe -NoProfile -Command \
            "Start-Process powershell -Verb RunAs -Wait -ArgumentList '-NoProfile','-EncodedCommand','$ENC'" \
            >/dev/null 2>&1 || true

        if ( : >> "$WIN_HOSTS" ) 2>/dev/null && certutil.exe -verifystore Root 2>/dev/null | grep -qi mkcert; then
            echo "Windows configurado: navegador confia en los certs y el hosts es editable sin UAC."
        else
            echo "AVISO: la elevacion no se completo. Puedes reintentarlo ejecutando este script de nuevo,"
            echo "       o hacerlo a mano en una PowerShell de administrador:"
            echo "         certutil -addstore -f ROOT \"$CA_WIN\""
            echo "         icacls \$env:SystemRoot\\System32\\drivers\\etc\\hosts /grant \"${WIN_USER}:(M)\""
        fi
    else
        echo "Windows ya configurado (CA confiada y hosts editable sin UAC)."
    fi
fi

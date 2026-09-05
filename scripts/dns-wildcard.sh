#!/usr/bin/env bash
# Resuelve los dominios del control plane y tenants a 127.0.0.1.
#
# 1:1 con prod, que usa DNS wildcard. En local:
#   - Linux / WSL con dnsmasq: este script escribe el dropin y recarga.
#   - Windows: imprime la config de Acrylic DNS.
#   - Fallback sin DNS: imprime la linea para el hosts de Windows (pocos subdominios, conocidos).
#
# Uso:  bash scripts/dns-wildcard.sh

set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BASE_DOMAIN="snakepc.test"
TENANT_BASE_DOMAIN="commander.test"
if [ -f "$ROOT_DIR/.env" ]; then
    val=$(grep -E "^BASE_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
    [ -n "$val" ] && BASE_DOMAIN="$val"
    val=$(grep -E "^TENANT_BASE_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
    [ -n "$val" ] && TENANT_BASE_DOMAIN="$val"
fi

DOMAINS=("$BASE_DOMAIN")
if [ "$TENANT_BASE_DOMAIN" != "$BASE_DOMAIN" ]; then
    DOMAINS+=("$TENANT_BASE_DOMAIN")
fi

echo "BASE_DOMAIN = ${BASE_DOMAIN}"
echo "TENANT_BASE_DOMAIN = ${TENANT_BASE_DOMAIN}"
echo ""

if command -v dnsmasq >/dev/null 2>&1; then
    echo "dnsmasq detectado. Escribiendo configuracion (requiere sudo)..."
    for domain in "${DOMAINS[@]}"; do
        echo "address=/${domain}/127.0.0.1" | sudo tee "/etc/dnsmasq.d/${domain}.conf" >/dev/null
    done
    # Recarga: systemd o init clasico
    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet dnsmasq 2>/dev/null; then
        sudo systemctl restart dnsmasq
    else
        sudo service dnsmasq restart 2>/dev/null || echo "Reinicia dnsmasq manualmente para aplicar."
    fi
    echo "Listo: ${DOMAINS[*]} -> 127.0.0.1 via dnsmasq."
    echo "Comprueba: getent hosts demo.${TENANT_BASE_DOMAIN}"
    exit 0
fi

echo "dnsmasq no esta instalado. Opciones:"
echo ""
echo "A) Instalar dnsmasq (Linux/WSL) y reejecutar este script:"
echo "     sudo apt-get install -y dnsmasq"
echo "   (o crea un address=/<dominio>/127.0.0.1 por cada dominio)"
echo ""
echo "B) Windows — Acrylic DNS Proxy (wildcard, recomendado): en AcrylicHosts.txt anade"
echo "     127.0.0.1  *.${BASE_DOMAIN}"
echo "     127.0.0.1  *.${TENANT_BASE_DOMAIN}"
echo "   y reinicia el servicio Acrylic. Apunta el DNS del adaptador a 127.0.0.1."
echo ""
echo "C) Fallback sin DNS — anade al hosts de Windows"
echo "   (C:\\Windows\\System32\\drivers\\etc\\hosts) los subdominios que uses:"
echo "     127.0.0.1  api.${BASE_DOMAIN} commander.${BASE_DOMAIN} demo.${TENANT_BASE_DOMAIN}"
echo ""
echo "Dentro de WSL puedes probar sin DNS: curl --resolve demo.${TENANT_BASE_DOMAIN}:8443:127.0.0.1 https://demo.${TENANT_BASE_DOMAIN}:8443/api/v1/kilkana"

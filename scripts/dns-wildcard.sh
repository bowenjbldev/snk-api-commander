#!/usr/bin/env bash
# Resuelve *.<BASE_DOMAIN> -> 127.0.0.1 en local, para que el navegador abra los
# subdominios del modelo centralizado ({paquete}.<BASE_DOMAIN>, commander., api.).
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
if [ -f "$ROOT_DIR/.env" ]; then
    val=$(grep -E "^BASE_DOMAIN=" "$ROOT_DIR/.env" | cut -d '=' -f2)
    [ -n "$val" ] && BASE_DOMAIN="$val"
fi

DROPIN="/etc/dnsmasq.d/${BASE_DOMAIN}.conf"
LINE="address=/${BASE_DOMAIN}/127.0.0.1"

echo "BASE_DOMAIN = ${BASE_DOMAIN}"
echo ""

if command -v dnsmasq >/dev/null 2>&1; then
    echo "dnsmasq detectado. Escribiendo ${DROPIN} (requiere sudo)..."
    echo "$LINE" | sudo tee "$DROPIN" >/dev/null
    # Recarga: systemd o init clasico
    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet dnsmasq 2>/dev/null; then
        sudo systemctl restart dnsmasq
    else
        sudo service dnsmasq restart 2>/dev/null || echo "Reinicia dnsmasq manualmente para aplicar."
    fi
    echo "Listo: *.${BASE_DOMAIN} -> 127.0.0.1 via dnsmasq."
    echo "Comprueba:  getent hosts shop.${BASE_DOMAIN}"
    exit 0
fi

echo "dnsmasq no esta instalado. Opciones:"
echo ""
echo "A) Instalar dnsmasq (Linux/WSL) y reejecutar este script:"
echo "     sudo apt-get install -y dnsmasq"
echo "   (o crea el dropin a mano:  echo '${LINE}' | sudo tee ${DROPIN})"
echo ""
echo "B) Windows — Acrylic DNS Proxy (wildcard, recomendado): en AcrylicHosts.txt anade"
echo "     127.0.0.1  *.${BASE_DOMAIN}"
echo "   y reinicia el servicio Acrylic. Apunta el DNS del adaptador a 127.0.0.1."
echo ""
echo "C) Fallback sin DNS — anade al hosts de Windows"
echo "   (C:\\Windows\\System32\\drivers\\etc\\hosts) los subdominios que uses:"
echo "     127.0.0.1  api.${BASE_DOMAIN} commander.${BASE_DOMAIN} shop.${BASE_DOMAIN} blog.${BASE_DOMAIN} crm.${BASE_DOMAIN}"
echo ""
echo "Dentro de WSL puedes probar sin DNS:  curl --resolve shop.${BASE_DOMAIN}:8443:127.0.0.1 https://shop.${BASE_DOMAIN}:8443/api/v1"

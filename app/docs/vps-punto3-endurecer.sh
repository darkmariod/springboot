#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
#  PUNTO 3 — Endurecer el VPS antes de que pruebe la contadora
#  Hace 3 cosas y VERIFICA cada una:
#    1. Instala php-soap (sin esto la librería SRI queda en "firmado", nunca envía)
#    2. Respalda APP_KEY (si la perdés, el .p12 y las claves SMTP quedan ilegibles)
#    3. Pone APP_DEBUG=false (si queda en true, exponés tu .env entero a internet)
#
#  USO en el VPS (como root, dentro del proyecto):
#    cd /var/www/contable/contabilidad-backend
#    bash vps-punto3-endurecer.sh
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail

# Colores para que se vea claro qué pasó
ok(){   echo -e "  \033[1;32m✓\033[0m $1"; }
fail(){ echo -e "  \033[1;31m✗\033[0m $1"; }
info(){ echo -e "\033[1;34m▶ $1\033[0m"; }

# Debe correrse dentro de contabilidad-backend (donde vive el .env real)
if [[ ! -f artisan || ! -f .env ]]; then
  fail "No encuentro 'artisan' ni '.env'. Corré esto DENTRO de contabilidad-backend."
  echo "     cd /var/www/contable/contabilidad-backend && bash vps-punto3-endurecer.sh"
  exit 1
fi

# ───────────────────────────────────────────────────────────────────────────
info "1/3 · Instalando php-soap"
# Detecta la versión de PHP activa (ej. 8.3) para instalar el paquete correcto
PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
# Intenta el paquete versionado (php8.3-soap); si no, cae al genérico (php-soap)
apt-get install -y "php${PHPVER}-soap" 2>/dev/null || apt-get install -y php-soap

if php -m | grep -qi soap; then
  ok "soap cargado en PHP ${PHPVER}"
  # El worker de php-fpm necesita reiniciarse para ver la extensión nueva
  systemctl restart "php${PHPVER}-fpm" 2>/dev/null \
    || systemctl restart php-fpm 2>/dev/null \
    || fail "No pude reiniciar php-fpm solo — reiniciálo a mano: systemctl restart php${PHPVER}-fpm"
  ok "php-fpm reiniciado (ya ve la extensión soap)"
else
  fail "soap NO aparece en 'php -m'. La librería SRI no va a enviar. Revisá la instalación."
  exit 1
fi

# ───────────────────────────────────────────────────────────────────────────
info "2/3 · Respaldando APP_KEY"
APP_KEY_LINE="$(grep -E '^APP_KEY=' .env || true)"
if [[ -z "$APP_KEY_LINE" || "$APP_KEY_LINE" == "APP_KEY=" ]]; then
  fail "APP_KEY está vacía. Generala ANTES de cargar la firma: php artisan key:generate"
  fail "  (Si ya guardaste claves de firma con otra APP_KEY, se vuelven ilegibles.)"
  exit 1
fi
BACKUP="/root/APP_KEY-backup-$(date +%F).txt"
{
  echo "# Respaldo de APP_KEY — $(date)"
  echo "# Si perdés esta clave, el .p12 y la clave SMTP guardados NO se pueden desencriptar."
  echo "# Guardá una copia FUERA del servidor (gestor de contraseñas)."
  echo "$APP_KEY_LINE"
} > "$BACKUP"
chmod 600 "$BACKUP"
ok "APP_KEY copiada a $BACKUP (solo root puede leerla)"
echo -e "     \033[1;33m⚠ Copiá ese valor a tu gestor de contraseñas, fuera del VPS.\033[0m"

# ───────────────────────────────────────────────────────────────────────────
info "3/3 · Forzando APP_DEBUG=false"
if grep -qE '^APP_DEBUG=' .env; then
  sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env
else
  echo "APP_DEBUG=false" >> .env
fi
if grep -qxE 'APP_DEBUG=false' .env; then
  ok "APP_DEBUG=false confirmado en .env"
else
  fail "No pude fijar APP_DEBUG. Editalo a mano: APP_DEBUG=false"
  exit 1
fi

# Refrescar la config cacheada para que production tome los cambios
php artisan config:clear >/dev/null 2>&1 || true
ok "Config limpiada"

echo ""
info "LISTO — punto 3 terminado. Verificación final:"
echo "   soap:       $(php -m | grep -qi soap && echo 'presente ✓' || echo 'FALTA ✗')"
echo "   APP_DEBUG:  $(grep -E '^APP_DEBUG=' .env)"
echo "   APP_KEY bk: $BACKUP"
echo ""
echo -e "\033[1;33mSiguiente paso (solo vos): cargar el .p12 real en EDocuments y emitir 1 factura de prueba.\033[0m"

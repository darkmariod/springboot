#!/bin/bash
# ─────────────────────────────────────────────────────────────
# Deploy rápido de la LANDING PAGE (sin rebuild, tarda segundos)
#
# 1. Editá la landing en tu editor:
#    app/contabilidad-backend/resources/views/landing.blade.php
# 2. Guardá.
# 3. Corré este script:  bash deploy-landing.sh
# 4. Recargá http://108.174.152.179:8080/  (Cmd/Ctrl + Shift + R)
# ─────────────────────────────────────────────────────────────
set -e

VPS_PORT=22022
VPS=root@108.174.152.179
BASE="$(dirname "$0")/app/contabilidad-backend"
LOCAL="$BASE/resources/views/landing.blade.php"
RUTA="$BASE/routes/web.php"

echo "→ Subiendo landing al VPS..."
scp -P "$VPS_PORT" "$LOCAL" "$VPS:/tmp/landing.blade.php"
scp -P "$VPS_PORT" "$RUTA" "$VPS:/tmp/web.php"

echo "→ Aplicando dentro del contenedor..."
ssh -p "$VPS_PORT" "$VPS" '
  docker cp /tmp/landing.blade.php contable-backend:/var/www/resources/views/landing.blade.php &&
  docker cp /tmp/web.php contable-backend:/var/www/routes/web.php &&
  docker exec contable-backend php artisan view:clear >/dev/null 2>&1 &&
  docker exec contable-backend php artisan route:clear >/dev/null 2>&1 &&
  rm -f /tmp/landing.blade.php /tmp/web.php
'

echo "✓ Landing actualizada. Recargá http://108.174.152.179:8080/ con Cmd/Ctrl+Shift+R"

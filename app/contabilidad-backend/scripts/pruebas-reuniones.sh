#!/usr/bin/env bash
# Corre TODAS las pruebas de aceptación de las reuniones con un solo comando y imprime un informe en español.
#
#   bash scripts/pruebas-reuniones.sh          (desde app/contabilidad-backend)
#
# 1) Pruebas del servidor (PHPUnit, grupo «reuniones»): R1–R11, R13–R23 y R25–R27.
# 2) Verificación de pantalla (Node): R12 (menú y flechas), R23 (menú por rol) y R24 (atajos).
# 3) Tabla final «Pedido | Reunión | Qué se probó | Resultado» y el veredicto.
# Código de salida: 0 = «LISTO PARA LA REUNIÓN»; 1 = «HAY PEDIDOS QUE FALLAN».
set -u

BACKEND="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VUE="$(cd "$BACKEND/../contabilidad-vue" && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/reuniones.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

cd "$BACKEND" || exit 1
echo "== 1/3 Pruebas del servidor (PHPUnit, grupo «reuniones») =="
php artisan test --group=reuniones --log-junit "$TMP/junit.xml"
SERVIDOR=$?

echo
echo "== 2/3 Verificación de pantalla (Node: menú, roles y atajos) =="
if command -v node >/dev/null 2>&1; then
  (cd "$VUE" && node scripts/verificar-reuniones.mjs --json="$TMP/pantalla.json")
  PANTALLA=$?
else
  echo "No se encontró Node: no se pudo verificar la pantalla (R12, R23 y R24)."
  PANTALLA=1
fi

echo
echo "== 3/3 Informe =="
if [ -f "$TMP/pantalla.json" ]; then
  php scripts/resumen-reuniones.php "$TMP/junit.xml" "$TMP/pantalla.json"
else
  php scripts/resumen-reuniones.php "$TMP/junit.xml" "$TMP/no-hay-pantalla.json"
fi
INFORME=$?

# El veredicto del informe manda; si PHPUnit o Node fallaron por otra razón, también se refleja.
if [ "$INFORME" -ne 0 ] || [ "$SERVIDOR" -ne 0 ] || [ "$PANTALLA" -ne 0 ]; then
  exit 1
fi
exit 0

# PROMPT DE TRABAJO — Todo en uno (seguir en orden, de arriba hacia abajo)

Sistema HasReset · Frontend Vue 3 + PrimeVue + Pinia (`app/contabilidad-vue`) ·
Backend Laravel (`app/contabilidad-backend`). Repo: `/Users/mariopazmino/Desktop/springboot`.
Hacé los pasos en orden. Cada paso tiene su verificación antes de pasar al siguiente.

Objetivos:
1. Que al abrir un módulo NO se fugue el Punto de Venta encima (bug visual).
2. Que el stock entre y salga perfecto en todo el sistema (sin sumar de más, sin negativos).
3. Desplegar al VPS.
4. Respaldo en GitHub ignorando secretos (.p12, .txt del SRI, .env, facturas firmadas).

---

## PASO 1 — Arreglar el POS que se fuga sobre todas las pestañas  (frontend, rápido)

### Qué pasa
Con cualquier pestaña activa, el Punto de Venta sigue visible arriba (incluido el banner
"Cliente encontrado…"). El shell oculta las pestañas con `v-show`, que solo pone `display:none`
en el root; pero el root de `Pos.vue` trae `display:flex` inline y lo pisa.

### Cambio 1.1 — Shell usa `v-if` en vez de `v-show`
Archivo: `src/layouts/MainLayout.vue`, dentro de `.tabcontent`.
```html
<!-- ANTES -->
<template v-for="t in tabs.tabs" :key="t.key">
  <KeepAlive>
    <component :is="componentMap[t.component]" v-show="tabs.activeKey === t.key" />
  </KeepAlive>
</template>

<!-- DESPUÉS -->
<template v-for="t in tabs.tabs" :key="t.key">
  <KeepAlive>
    <component v-if="tabs.activeKey === t.key" :is="componentMap[t.component]" />
  </KeepAlive>
</template>
```
Con `v-if` la pestaña inactiva sale del DOM (no puede fugarse). `KeepAlive` conserva el estado
(cliente e ítems cargados no se pierden al cambiar de pestaña).

### Cambio 1.2 — Sacar el `display` inline del root del POS (defensa extra)
Archivo: `src/views/Pos.vue`.
```html
<!-- root del <template>: ANTES -->
<div style="display:flex; flex-direction:column; height:100%; overflow:hidden;">
<!-- DESPUÉS -->
<div class="pos-root">
```
En su `<style scoped>` agregar:
```css
.pos-root { display: flex; flex-direction: column; height: 100%; overflow: hidden; }
```
> Regla: ningún root de un view usado como pestaña debe llevar `display` en `style` inline.

### Verificar PASO 1
```bash
cd app/contabilidad-vue && npx vite build
```
- Sin errores de build.
- Abrir POS → Proveedores → Importar SRI → Reportes: **solo** se ve la pestaña activa.
- Volver al POS: conserva lo que había cargado.

---

## PASO 2 — Stock que entra y sale PERFECTO  (backend)

### Contexto (ya está casi todo bien)
Existe un servicio central `app/Services/RegisterInventoryMovement.php` con
`handle($producto, $tipo, $cantidad, $costo, $concepto, $fecha, $warehouseId)`. Actualiza
`products.stock`, `costo_promedio`, el kardex (`InventoryMovement`) y el stock por bodega
(`WarehouseStock`). Ya lo usan: ventas/POS, compras, importación SRI, notas de crédito,
ajustes, transferencias, fraccionamiento, anular factura. Falta blindarlo.

### Cambio 2.1 — Bug: nota de crédito SUMA en vez de restar
El servicio solo entiende `'ingreso'` y `'egreso'`. `CreditNoteController` pasa `'salida'`, que
cae en el `else` y **suma** stock (mal).
- En `app/Http/Controllers/CreditNoteController.php`: cambiar `'salida'` → `'egreso'`.
- En `RegisterInventoryMovement::handle`: reemplazar el `else` silencioso por un error:
  ```php
  } else {
      throw new \InvalidArgumentException("Tipo de movimiento inválido: {$tipo}");
  }
  ```

### Cambio 2.2 — Bloquear stock negativo
En `RegisterInventoryMovement::handle`, antes de descontar en un `egreso`:
```php
if ($tipo === 'egreso' && round($cantPrev - $cant, 2) < 0) {
    throw new \RuntimeException(
        "Stock insuficiente de {$p->codigo}: hay {$cantPrev}, se piden {$cant}.");
}
```
El controlador que emite la venta/factura debe atrapar esa excepción y devolver **422** con el
mensaje, para que el usuario vea "Stock insuficiente de …" y no un error genérico.

### Cambio 2.3 — Transacción + bloqueo de fila (evitar carreras)
Envolver el cuerpo de `handle` en una transacción y bloquear la fila del producto:
```php
return \DB::transaction(function () use ($p, $tipo, $cant, $costo, $concepto, $fecha, $warehouseId) {
    $p = \App\Models\Product::whereKey($p->id)->lockForUpdate()->firstOrFail();
    // … todo el cálculo y los updates actuales …
});
```

### Cambio 2.4 — Series (productos con `maneja_series`)
Al hacer `egreso` de un producto serializado, exigir la(s) serie(s) y marcarlas vendidas; al
`ingreso`/anular, crearlas/liberarlas. No permitir vender una serie inexistente o ya vendida.

### Regla de oro
**Nadie toca `products.stock` ni `costo_promedio` directamente.** Todo pasa por
`RegisterInventoryMovement::handle`. Buscar y migrar cualquier `->update(['stock' => …])` o
`increment/decrement('stock')` fuera del servicio. (Leer `stock` en reportes está bien; el objetivo
son las ESCRITURAS.)

### Verificar PASO 2
```bash
php artisan test          # agregar los tests de abajo
php artisan contable:chequeo
```
Tests a agregar:
- Compra 10 → stock 10, costo promedio ponderado correcto.
- Venta 3 (POS) → stock 7; kardex y `WarehouseStock` cuadran.
- Nota de crédito 1 → stock 8 (devuelve, no resta de más ni suma).
- Venta que deja negativo → lanza error y **no** escribe nada (stock queda igual).
- Transferencia entre bodegas → una baja y otra sube lo mismo; total sin cambios.
- Producto con series → no se puede vender serie inexistente/ya vendida.
- `contable:chequeo` → sin stock negativo, kardex consistente.

---

## PASO 3 — Desplegar al VPS y probar en el navegador
```bash
# build frontend
cd app/contabilidad-vue && npx vite build
# subir al VPS (sin git)
cd /Users/mariopazmino/Desktop/springboot/app
rsync -az -e "ssh -p 22022" \
  --exclude node_modules --exclude vendor --exclude .git --exclude .env \
  --exclude .env.docker --exclude database/database.sqlite --exclude storage/logs --exclude .atl \
  ./ root@108.174.152.179:/opt/springboot/app/
ssh -p 22022 root@108.174.152.179 'cd /opt/springboot/app && docker compose up -d --build backend frontend'
```
Probar en `http://108.174.152.179:8080`:
1. Cambiar entre pestañas → no se fuga el POS.
2. Comprar 10 de un producto → vender 3 → queda 7.
3. Intentar vender 100 → "Stock insuficiente…".
4. Emitir nota de crédito de 1 → stock vuelve a 8.

---

## PASO 4 — Respaldo en GitHub (ignorar secretos + primer commit de la copia)

> El repo `springboot/` **ya existe** con `.git` y un `.gitignore` que cubre `.env`, `.env.docker`,
> `*.p12`, `*.pem`. **Falta** ignorar los `.txt` de recibidos del SRI, la carpeta `certificados/`
> (contiene el `.p12` de Javier + `.txt`), la base sqlite y las facturas firmadas en HTML.
> Commits de lunes a viernes (no fin de semana).

### 4.1 — Reforzar `.gitignore`
Agregar al final de `.gitignore` (NO duplicar lo que ya está):
```gitignore
# Certificados y comprobantes recibidos del SRI (NUNCA al repo)
certificados/
*.pfx
*.key

# Base de datos local
**/database.sqlite

# Facturas / RIDE firmados generados
COMPROBANTE-*.html
**/storage/app/comprobantes/
**/storage/app/sri/
**/storage/app/xml/
**/storage/app/pdf/

# Dependencias y build
**/node_modules/
**/vendor/
**/dist/
**/storage/logs/*.log
```
> Se ignora la carpeta `certificados/` entera (no `*.txt` global), así `public/robots.txt` y demás
> `.txt` legítimos siguen versionados.

### 4.2 — Quitar del control de versiones lo que ya estuviera trackeado
Si algún secreto se comiteó antes, agregarlo a `.gitignore` no lo saca: hay que destrackearlo.
```bash
cd /Users/mariopazmino/Desktop/springboot
git rm -r --cached --ignore-unmatch \
  certificados \
  app/contabilidad-backend/.env \
  app/contabilidad-backend/database/database.sqlite \
  "Lib_Firmador_Xml_Facturacion _SRI/.env"
```

### 4.3 — VERIFICACIÓN DE SEGURIDAD (obligatoria antes de commitear)
Esta lista **tiene que salir vacía**. Si aparece algo, NO commitear y revisar el `.gitignore`.
```bash
git ls-files | grep -iE '\.(p12|pfx|key|env|sqlite)$|(^|/)certificados/' || echo "OK: sin secretos trackeados"
git status
```
Revisar a ojo que en "Changes to be committed" no haya ningún `.p12`, `.txt` de recibidos, `.env`
ni la base `database.sqlite`.

### 4.4 — Primer commit = respaldo del sistema con los cambios de mañana
```bash
git add .
# volver a verificar que no se coló nada sensible:
git ls-files | grep -iE '\.(p12|pfx|key|env|sqlite)$|(^|/)certificados/' || echo "OK, listo para commit"
git commit -m "chore: respaldo del sistema con dashboard, sidebar global, fix POS e inventario"
```

### 4.5 — Subir a GitHub (tu decisión)
Solo si la verificación 4.3 salió limpia. El push lo hacés vos (es tu actividad de lunes a viernes):
```bash
git push
```
> Regla tuya: no se pushea sin que vos lo decidas. Este paso queda a tu criterio, después de
> confirmar que ningún secreto quedó trackeado.

---

## Orden recomendado
1. **PASO 1** (una línea, arregla lo visible enseguida).
2. **PASO 2** con calma (el bug de la nota de crédito conviene probarlo bien).
3. **PASO 3** (deploy al VPS).
4. **PASO 4** (respaldo en GitHub — la verificación de seguridad 4.3 es obligatoria antes de commitear).

No mezcles los pasos: terminá y verificá uno antes de empezar el siguiente.

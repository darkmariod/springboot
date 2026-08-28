# PROMPT DE TRABAJO — Todo en uno (seguir en orden, de arriba hacia abajo)

Sistema HasReset · Frontend Vue 3 + PrimeVue + Pinia (`app/contabilidad-vue`) ·
Backend Laravel (`app/contabilidad-backend`).
**No tocar git.** Hacé los pasos en orden. Cada paso tiene su verificación antes de pasar al siguiente.

Son 2 objetivos:
1. Que al abrir un módulo NO se fugue el Punto de Venta encima (bug visual).
2. Que el stock entre y salga perfecto en todo el sistema (sin sumar de más, sin negativos).

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

## Orden recomendado
Hacé **PASO 1 primero** (es una línea y arregla lo visible enseguida). Después **PASO 2** con calma
(el bug de la nota de crédito conviene probarlo bien). Recién ahí **PASO 3** (deploy).
No mezcles los pasos: terminá y verificá uno antes de empezar el siguiente.

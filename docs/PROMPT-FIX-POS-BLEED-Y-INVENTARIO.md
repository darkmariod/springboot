# PROMPT CORREGIDO — (A) POS que se fuga sobre todas las pestañas · (B) Integridad de inventario

> Reemplaza el enfoque anterior de la parte POS (quitar `display` inline era frágil y no bastó).
> Frontend Vue 3 + PrimeVue + Pinia (`app/contabilidad-vue`) · Backend Laravel (`app/contabilidad-backend`).
> **No tocar git.** Dos tareas independientes.

---

# A) El Punto de Venta se muestra encima de TODAS las pestañas

## Síntoma (confirmado)
Con cualquier pestaña activa (Importar del SRI, Proveedores, Reportes…), el contenido del
**Punto de Venta** sigue visible arriba (incluido el banner verde "Cliente encontrado: PRUEBA TEST").
El POS nunca se oculta.

## Causa raíz
El shell (`layouts/MainLayout.vue`) oculta pestañas inactivas con **`v-show`**, que solo aplica
`display:none` al **root** del componente. El root de `Pos.vue` trae `display:flex` en su estilo,
y eso pisa al `display:none`. Cualquier view con `display` inline en el root rompe `v-show`.

## Fix robusto (cambiar el shell a `v-if`, no `v-show`)
`v-if` **saca del DOM** la pestaña inactiva, así que ningún estilo inline puede hacerla reaparecer.
Con `<KeepAlive>` el estado (formularios, scroll) se conserva igual.

En `layouts/MainLayout.vue`, dentro de `.tabcontent`, cambiar:
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
Único cambio: `v-show="…"` → `v-if="…"` (y moverlo antes de `:is` por prolijidad). KeepAlive
mantiene viva la instancia cacheada aunque `v-if` la quite del DOM.

## Defensa adicional (opcional, recomendada)
Que ningún root de un view usado como pestaña lleve `display` en `style="..."` inline; pasarlo a
una clase. En `Pos.vue`:
```html
<div class="pos-root">            <!-- antes: style="display:flex; flex-direction:column; height:100%; overflow:hidden;" -->
```
```css
.pos-root { display: flex; flex-direction: column; height: 100%; overflow: hidden; }
```

## Verificación (A)
1. `cd app/contabilidad-vue && npx vite build` → sin errores.
2. Abrir POS, luego Proveedores / Importar SRI / Reportes → **solo** se ve la pestaña activa.
3. Volver al POS → conserva el cliente/ítems que había cargado (KeepAlive funciona).

---

# B) Que el stock entre y salga PERFECTO en todo el sistema (CRUD de acciones)

## Estado actual (auditoría)
Ya existe un servicio central `app/Services/RegisterInventoryMovement.php` con
`handle($producto, $tipo, $cantidad, $costo, $concepto, $fecha, $warehouseId)` que:
actualiza `products.stock` y `costo_promedio`, escribe el kardex (`InventoryMovement`) y el stock
por bodega (`WarehouseStock`). **Casi todos los flujos ya lo usan** (bien):
- Ventas / POS → `InvoiceEmitter` (egreso)
- Compras (manual, update, importación XML/lote) → `PurchaseController` / `StorePurchaseFromXml` (ingreso)
- Notas de crédito → `CreditNoteController` (ingreso / **salida** ← ver bug)
- Ajustes y transferencias → `InventoryTransactionController` (ingreso / egreso)
- Fraccionamiento, conversión de artículos → (ingreso/egreso)
- Anular factura → `InvoiceController@anular` (ingreso, devuelve stock)

## Bugs a corregir en `RegisterInventoryMovement::handle`
1. **Tipo inconsistente que SUMA cuando debería restar.**
   El servicio solo entiende `'ingreso'` y `'egreso'`. `CreditNoteController` pasa `'salida'`,
   que cae en el `else` final y hace `stock + cant` → **incrementa** stock en lugar de restarlo
   (y lo mismo en la bodega). Corregir:
   - En `CreditNoteController`, cambiar `'salida'` → `'egreso'`.
   - En el servicio, **eliminar el `else` silencioso** y lanzar excepción ante un tipo no válido:
     ```php
     } else {
         throw new \InvalidArgumentException("Tipo de movimiento inválido: {$tipo}");
     }
     ```
     (así un tipo mal escrito falla ruidoso en vez de corromper el stock).

2. **Sin bloqueo de stock negativo.**
   Un `egreso` puede dejar `stock < 0` sin avisar. Antes de descontar, validar y bloquear:
   ```php
   if ($tipo === 'egreso' && round($cantPrev - $cant, 2) < 0) {
       throw new \App\Exceptions\StockInsuficienteException(
           "Stock insuficiente de {$p->codigo}: hay {$cantPrev}, se piden {$cant}.");
   }
   ```
   El controlador que llama (POS/factura) debe atrapar esa excepción y devolver 422 con el mensaje,
   para que el usuario vea "Stock insuficiente de …" en vez de un error genérico.

3. **Sin transacción ni bloqueo de fila (condición de carrera).**
   `handle` hace leer→calcular→escribir sobre `stock`. Dos ventas simultáneas pueden pisarse.
   Envolver en transacción y bloquear la fila del producto:
   ```php
   return \DB::transaction(function () use (...) {
       $p = Product::whereKey($p->id)->lockForUpdate()->firstOrFail();
       // … cálculo y updates …
   });
   ```

4. **Series (productos con `maneja_series`).**
   Verificar que al hacer egreso de un producto serializado se exija y marque la serie vendida,
   y que al ingresar/anular se creen/liberen las series. Si hoy no está centralizado, dejar la
   validación de series junto al egreso para que no se pueda vender una serie inexistente o ya vendida.

## Regla de oro
**Ninguna parte del código debe tocar `products.stock` ni `costo_promedio` directamente.** Todo
cambio de existencias pasa por `RegisterInventoryMovement::handle`. Buscar y migrar cualquier
`->update(['stock' => …])` o `increment/decrement('stock')` que esté fuera del servicio
(los reportes que solo LEEN `stock` están bien; el objetivo son las ESCRITURAS).

## Tests a agregar (`php artisan test`)
- Ingreso por compra: sube stock y recalcula costo promedio ponderado.
- Egreso por venta: baja stock al costo promedio; kardex y `WarehouseStock` cuadran.
- Nota de crédito: **devuelve** stock (no lo suma dos veces).
- Egreso que deja negativo: lanza `StockInsuficienteException` y **no** escribe nada.
- Transferencia entre bodegas: una baja y otra sube la misma cantidad; total sin cambios.
- Producto con series: no se puede vender una serie inexistente/ya vendida.
- `php artisan contable:chequeo` → sin stock negativo, kardex consistente.

## Verificación (B)
1. Comprar 10 unidades → stock 10, costo promedio correcto.
2. Vender 3 desde el POS → stock 7; kardex muestra el egreso; bodega en 7.
3. Intentar vender 100 → error claro "Stock insuficiente…", stock sigue en 7.
4. Nota de crédito de 1 → stock 8 (devuelve), no 6.
5. Anular una factura → devuelve su stock; `contable:chequeo` TODO OK.

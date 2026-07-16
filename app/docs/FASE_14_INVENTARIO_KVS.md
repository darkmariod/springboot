# FASE 14 — Inventario igual al video del creador (código para pegar)

> Lo que él mostró de la ficha de artículo y todavía nos falta EN PANTALLA
> (las tablas ya existen en tu base — esto es UI + rutas):
>
> | Del video | Estado |
> |---|---|
> | *"un producto puede tener **varias listas de precios**"* | Tabla ✓ — falta UI y rutas |
> | *"usted arma aquí un **componente**... y le descarga todos los componentes"* | Tabla ✓ — falta UI y rutas |
> | *"puede poner acá **códigos alternos** de producto"* | Tabla ✓ — falta UI y rutas |
> | *"el propio sistema le diga cuáles necesita **reponer**"* | Endpoint ✓ — falta el reporte |
> | *"de los 21 teléfonos, puedo sacar **cuál es la serie** de los que tengo"* | Falta el reporte detallado |
>
> Backend: `contabilidad-backend` · Frontend: `contabilidad-vue`

---

## A. BACKEND

### 14.1 Controlador — `app/Http/Controllers/ProductExtrasController.php`
```php
<?php
namespace App\Http\Controllers;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductComponent;
use Illuminate\Http\Request;

class ProductExtrasController extends Controller
{
    // ── Listas de precios ─────────────────────────────────────────
    public function prices(Product $product)
    {
        return $product->priceLists()->orderBy('nombre')->get();
    }

    public function storePrice(Request $r, Product $product)
    {
        $d = $r->validate([
            'nombre' => ['required', 'string'],
            'precio' => ['required', 'numeric', 'min:0'],
        ]);
        return response()->json($product->priceLists()->create($d), 201);
    }

    public function destroyPrice(PriceList $price)
    {
        $price->delete();
        return response()->noContent();
    }

    // ── Componentes del combo ─────────────────────────────────────
    public function components(Product $product)
    {
        return $product->components()->with('component:id,codigo,descripcion,stock')->get();
    }

    public function storeComponent(Request $r, Product $product)
    {
        $d = $r->validate([
            'component_id' => ['required', 'exists:products,id'],
            'cantidad' => ['required', 'numeric', 'min:0.01'],
        ]);
        if ((int) $d['component_id'] === $product->id) {
            abort(422, 'Un combo no puede contenerse a si mismo.');
        }
        $c = $product->components()->create($d);
        return response()->json($c->load('component:id,codigo,descripcion,stock'), 201);
    }

    public function destroyComponent(ProductComponent $component)
    {
        $component->delete();
        return response()->noContent();
    }

    // ── Códigos alternos ──────────────────────────────────────────
    public function codes(Product $product)
    {
        return $product->codes()->orderBy('codigo')->get();
    }

    public function storeCode(Request $r, Product $product)
    {
        $d = $r->validate(['codigo' => ['required', 'string']]);
        return response()->json($product->codes()->firstOrCreate($d), 201);
    }

    public function destroyCode(ProductCode $code)
    {
        $code->delete();
        return response()->noContent();
    }
}
```

> Si tu `app/Models/Product.php` todavía no tiene las relaciones, agregalas:
> ```php
>     public function priceLists() { return $this->hasMany(PriceList::class); }
>     public function components() { return $this->hasMany(ProductComponent::class); }
>     public function codes() { return $this->hasMany(ProductCode::class); }
> ```

### 14.2 Rutas — en `routes/api.php` (dentro del grupo `auth:sanctum`)
```php
    // Fase 14 — Ficha de artículo estilo KVS (precios, componentes, códigos alternos)
    Route::get("products/{product}/prices", [\App\Http\Controllers\ProductExtrasController::class, "prices"]);
    Route::post("products/{product}/prices", [\App\Http\Controllers\ProductExtrasController::class, "storePrice"]);
    Route::delete("product-prices/{price}", [\App\Http\Controllers\ProductExtrasController::class, "destroyPrice"]);
    Route::get("products/{product}/components", [\App\Http\Controllers\ProductExtrasController::class, "components"]);
    Route::post("products/{product}/components", [\App\Http\Controllers\ProductExtrasController::class, "storeComponent"]);
    Route::delete("product-components/{component}", [\App\Http\Controllers\ProductExtrasController::class, "destroyComponent"]);
    Route::get("products/{product}/codes", [\App\Http\Controllers\ProductExtrasController::class, "codes"]);
    Route::post("products/{product}/codes", [\App\Http\Controllers\ProductExtrasController::class, "storeCode"]);
    Route::delete("product-codes/{code}", [\App\Http\Controllers\ProductExtrasController::class, "destroyCode"]);
```
No hay migraciones: las tablas ya existen.

---

## B. FRONTEND

### 14.3 Ficha de artículo — agregar a `src/views/Products.vue`

**En el `<script setup>`, agregá** (después de `function editar(...)`):
```ts
// Extras de la ficha (solo al editar un producto ya guardado)
const precios = ref<any[]>([])
const componentes = ref<any[]>([])
const codigos = ref<any[]>([])
const nuevoPrecio = ref<any>({ nombre: '', precio: 0 })
const nuevoComponente = ref<any>({ component_id: null, cantidad: 1 })
const nuevoCodigo = ref('')

async function cargarExtras() {
  if (!form.value.id) { precios.value = []; componentes.value = []; codigos.value = []; return }
  const id = form.value.id
  precios.value = (await api.get('/products/' + id + '/prices')).data
  componentes.value = (await api.get('/products/' + id + '/components')).data
  codigos.value = (await api.get('/products/' + id + '/codes')).data
}
async function agregarPrecio() {
  if (!nuevoPrecio.value.nombre) return
  await api.post('/products/' + form.value.id + '/prices', nuevoPrecio.value)
  nuevoPrecio.value = { nombre: '', precio: 0 }; cargarExtras()
}
async function quitarPrecio(p: any) { await api.delete('/product-prices/' + p.id); cargarExtras() }
async function agregarComponente() {
  if (!nuevoComponente.value.component_id) return
  await api.post('/products/' + form.value.id + '/components', nuevoComponente.value)
  nuevoComponente.value = { component_id: null, cantidad: 1 }; cargarExtras()
}
async function quitarComponente(c: any) { await api.delete('/product-components/' + c.id); cargarExtras() }
async function agregarCodigo() {
  if (!nuevoCodigo.value.trim()) return
  await api.post('/products/' + form.value.id + '/codes', { codigo: nuevoCodigo.value.trim() })
  nuevoCodigo.value = ''; cargarExtras()
}
async function quitarCodigo(c: any) { await api.delete('/product-codes/' + c.id); cargarExtras() }
```

**Y al final de `function editar(r)`, antes de `dialog.value = true`, agregá:**
```ts
  cargarExtras()
```

**En el template, DENTRO del Dialog, después del fieldset "Stock y ubicación", agregá:**
```html
        <!-- Solo al editar: la ficha completa estilo KVS -->
        <template v-if="form.id">
          <fieldset class="kvs-fieldset">
            <legend>Listas de precios</legend>
            <div v-for="p in precios" :key="p.id"
                 style="display:flex; justify-content:space-between; align-items:center; padding:4px 0; font-size:13px;">
              <span>{{ p.nombre }}</span>
              <span style="display:flex; align-items:center; gap:8px;">
                <b>${{ Number(p.precio).toFixed(2) }}</b>
                <Button icon="pi pi-times" text size="small" severity="danger" @click="quitarPrecio(p)" />
              </span>
            </div>
            <div style="display:flex; gap:8px; margin-top:6px;">
              <InputText v-model="nuevoPrecio.nombre" placeholder="Mayorista / Distribuidor…" style="flex:1" />
              <InputNumber v-model="nuevoPrecio.precio" mode="currency" currency="USD" style="width:130px" />
              <Button icon="pi pi-plus" size="small" @click="agregarPrecio" />
            </div>
          </fieldset>

          <fieldset v-if="form.es_combo" class="kvs-fieldset">
            <legend>Componentes del combo</legend>
            <p style="margin:0 0 8px; font-size:12px; color:#64748b;">
              Al vender este combo, el sistema descarga del stock cada componente.
            </p>
            <div v-for="c in componentes" :key="c.id"
                 style="display:flex; justify-content:space-between; align-items:center; padding:4px 0; font-size:13px;">
              <span>{{ c.component?.codigo }} — {{ c.component?.descripcion }}</span>
              <span style="display:flex; align-items:center; gap:8px;">
                <b>× {{ Number(c.cantidad) }}</b>
                <Button icon="pi pi-times" text size="small" severity="danger" @click="quitarComponente(c)" />
              </span>
            </div>
            <div style="display:flex; gap:8px; margin-top:6px;">
              <Select v-model="nuevoComponente.component_id" :options="rows.filter(r => r.id !== form.id && !r.es_combo)"
                      optionValue="id" :optionLabel="(r) => r.codigo + ' — ' + r.descripcion"
                      placeholder="Elegir componente" filter style="flex:1" />
              <InputNumber v-model="nuevoComponente.cantidad" :useGrouping="false" style="width:90px" />
              <Button icon="pi pi-plus" size="small" @click="agregarComponente" />
            </div>
          </fieldset>

          <fieldset class="kvs-fieldset">
            <legend>Códigos alternos</legend>
            <p style="margin:0 0 8px; font-size:12px; color:#64748b;">
              El código del proveedor u otros códigos de barras. El POS también busca por estos.
            </p>
            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;">
              <Tag v-for="c in codigos" :key="c.id" severity="secondary"
                   style="display:inline-flex; align-items:center; gap:6px;">
                {{ c.codigo }}
                <i class="pi pi-times" style="cursor:pointer; font-size:10px;" @click="quitarCodigo(c)" />
              </Tag>
            </div>
            <div style="display:flex; gap:8px;">
              <InputText v-model="nuevoCodigo" placeholder="Escaneá o escribí el código alterno"
                         style="flex:1" @keydown.enter.prevent="agregarCodigo" />
              <Button icon="pi pi-plus" size="small" @click="agregarCodigo" />
            </div>
          </fieldset>
        </template>
        <p v-else style="margin:0; font-size:12px; color:#94a3b8;">
          Guardá el artículo primero para agregarle listas de precios, componentes y códigos alternos.
        </p>
```

### 14.4 POS: que el escaneo también encuentre códigos alternos — en `src/views/Pos.vue`

En `escanear()`, reemplazá el paso 2 (búsqueda por código) por:
```ts
  // 2) ¿es un código de producto (propio o alterno)?
  try {
    const res = await api.get('/products/lookup?company_id=' + company.activeId + '&codigo=' + encodeURIComponent(v))
    add(res.data)
    return
  } catch { /* tampoco */ }
  alert('No se encontró serie ni código: ' + v)
```

### 14.5 Dos reportes nuevos — en `src/views/InventoryReports.vue`

**Radios nuevos** (en el panel de parámetros, debajo de los 3 que hay):
```html
        <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
          <RadioButton v-model="tipo" value="series-detallado" /> Existencia - Detallado Series</label>
        <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
          <RadioButton v-model="tipo" value="reponer" /> Qué reponer (bajo mínimo)</label>
```

**En el script:** agregá los títulos:
```ts
  'series-detallado': 'DETALLE DE EXISTENCIAS POR SERIES',
  reponer: 'ARTÍCULOS BAJO STOCK MÍNIMO — SUGERIDO DE COMPRA',
```

**Y en `generar()`, ampliá el if:**
```ts
    if (tipo.value === 'series') {
      // ...lo que ya tenés
    } else if (tipo.value === 'series-detallado') {
      const series = (await api.get('/series?company_id=' + company.activeId)).data
      filas.value = series.map((s: any) => ({
        codigo: s.product?.codigo, nombre: s.product?.descripcion,
        serie: s.serie, estado: s.estado,
        factura: s.invoice?.numero ?? '—',
      }))
      totales.value = { articulos: filas.value.length }
    } else if (tipo.value === 'reponer') {
      const data = (await api.get('/inventory/reorder?company_id=' + company.activeId)).data
      filas.value = data
      totales.value = { articulos: data.length,
        sugerido: data.reduce((s: number, i: any) => s + Number(i.sugerido), 0) }
    } else {
      // ...existencias/valorado, lo que ya tenés
    }
```

**Columnas del documento:** dentro de la tabla del reporte, agregá los bloques por tipo
(mismo patrón `<template v-if>` que ya usás):
```html
              <template v-else-if="tipo === 'series-detallado'">
                <th style="text-align:left; padding:5px 6px;">Serie</th>
                <th style="text-align:left; padding:5px 6px;">Estado</th>
                <th style="text-align:left; padding:5px 6px;">Factura venta</th>
              </template>
              <template v-else-if="tipo === 'reponer'">
                <th style="text-align:right; padding:5px 6px;">Stock</th>
                <th style="text-align:right; padding:5px 6px;">Mínimo</th>
                <th style="text-align:right; padding:5px 6px;">Comprar</th>
              </template>
```
```html
              <template v-else-if="tipo === 'series-detallado'">
                <td style="padding:4px 6px; font-family:monospace;">{{ f.serie }}</td>
                <td style="padding:4px 6px;">{{ f.estado }}</td>
                <td style="padding:4px 6px;">{{ f.factura }}</td>
              </template>
              <template v-else-if="tipo === 'reponer'">
                <td style="text-align:right; padding:4px 6px;">{{ Number(f.stock).toFixed(2) }}</td>
                <td style="text-align:right; padding:4px 6px;">{{ Number(f.minimo).toFixed(2) }}</td>
                <td style="text-align:right; padding:4px 6px;"><b>{{ Number(f.sugerido).toFixed(2) }}</b></td>
              </template>
```
> El `tfoot` de totales usa campos que estos tipos no tienen — envolvelo en
> `v-if="tipo === 'existencias' || tipo === 'valorado' || tipo === 'series'"` para que no
> muestre columnas vacías en los nuevos.

---

## Probar (como el video)
1. Editá el iPhone 17 Pro Max → agregale lista "Mayorista $950" y el código alterno `IP17PM`.
2. En el POS escaneá `IP17PM` → debe agregar el iPhone.
3. Creá "COMPUTADORA ARMADA GAMER" con `es_combo` → agregale componentes → vendela →
   **el stock que baja es el de los componentes.**
4. Reportes de inventario → "Detallado Series" → salen las 5 series con su estado y factura.
5. Poné mínimo 6 al iPhone (tenés 4) → "Qué reponer" → sugiere comprar.

---

## 📸 Y las capturas de KVS
Cuando saques las capturas (lista en `FASES_PENDIENTES_ACTUAL.md`), mandámelas y te replico
los formularios de los demás módulos igual que hicimos con inventario.

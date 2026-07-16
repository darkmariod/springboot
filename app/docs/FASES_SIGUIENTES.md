> # ⛔ ARCHIVO SUPERADO — NO PEGUES DESDE ACÁ
> Todo esto está actualizado y corregido en **[FASES_COMPLETAS.md](FASES_COMPLETAS.md)**.
> Este archivo quedó con numeración vieja y datos sin corregir. Podés borrarlo.

# 🚀 Fases siguientes — código listo para pegar (según video del creador KVS)

> Flujo: abrís tu editor, pegás cada archivo donde se indica, corrés los comandos, probás.
> Backend: `contabilidad-backend` · Frontend: `contabilidad-vue`
> Orden: FASE 7 (series, prioridad para venta de computadoras) → 8 → 9.

---

# FASE 7 — SERIES por producto (garantías)

## A. Backend

### 1. Migración — `database/migrations/2026_07_21_000001_create_product_series_table.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('products', fn(Blueprint $t) => $t->boolean('maneja_series')->default(false));
        Schema::create('product_series', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('serie');                         // IMEI / número de serie
            $t->string('estado')->default('disponible'); // disponible | vendida
            // Trazabilidad de garantías: de quién vino y a quién se fue
            $t->foreignId('purchase_id')->nullable()->constrained();
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->timestamps();
            $t->unique(['company_id', 'serie']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('product_series');
        Schema::table('products', fn(Blueprint $t) => $t->dropColumn('maneja_series'));
    }
};
```

### 2. Modelo — `app/Models/ProductSerie.php`
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductSerie extends Model {
    protected $table = 'product_series';
    protected $fillable = ['company_id','product_id','serie','estado','purchase_id','invoice_id'];
    public function product() { return $this->belongsTo(Product::class); }
    public function purchase() { return $this->belongsTo(Purchase::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
```
Y en `app/Models/Product.php` agregá `'maneja_series'` al `$fillable` y la relación:
```php
public function series() { return $this->hasMany(ProductSerie::class); }
```

### 3. Controlador — `app/Http/Controllers/SerieController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\ProductSerie;
use Illuminate\Http\Request;

class SerieController extends Controller {
    // Series de un producto (disponibles o todas). ?estado=disponible
    public function index(Request $r) {
        return ProductSerie::with('product:id,codigo,descripcion','purchase:id,numero','invoice:id,numero')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->product_id, fn($q,$id)=>$q->where('product_id',$id))
            ->when($r->estado, fn($q,$e)=>$q->where('estado',$e))
            ->orderBy('serie')->get();
    }
    // Registrar series al COMPRAR (una por unidad). Body: {company_id, product_id, purchase_id, series: ["ABC1","ABC2"]}
    public function store(Request $r) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'],
            'product_id'=>['required','exists:products,id'],
            'purchase_id'=>['nullable','exists:purchases,id'],
            'series'=>['required','array','min:1'],'series.*'=>['required','string']]);
        $creadas = [];
        foreach ($d['series'] as $s) {
            $creadas[] = ProductSerie::firstOrCreate(
                ['company_id'=>$d['company_id'],'serie'=>trim($s)],
                ['product_id'=>$d['product_id'],'purchase_id'=>$d['purchase_id'] ?? null,'estado'=>'disponible']);
        }
        return response()->json($creadas, 201);
    }
    // Buscar UNA serie (para vender escaneando con la pistola)
    public function lookup(Request $r) {
        $s = ProductSerie::with('product')
            ->where('company_id',$r->company_id)->where('serie',trim($r->serie ?? ''))
            ->where('estado','disponible')->first();
        return $s ?: response()->json(['message'=>'Serie no encontrada o ya vendida'], 404);
    }
    // Garantía: historia completa de una serie (a quién compré, a quién vendí)
    public function trace(Request $r) {
        $s = ProductSerie::with('product:id,codigo,descripcion',
                'purchase.contact:id,razon_social','invoice.contact:id,razon_social')
            ->where('company_id',$r->company_id)->where('serie',trim($r->serie ?? ''))->first();
        return $s ?: response()->json(['message'=>'Serie no encontrada'], 404);
    }
}
```

### 4. Marcar series como VENDIDAS al facturar — en `app/Services/InvoiceEmitter.php`
Dentro de `emit()`, justo después del bloque del inventario (`foreach ($items as $item) {...}`),
agregá:
```php
        // Series: si el item trae series, marcarlas vendidas y ligarlas a esta factura
        foreach ($items as $item) {
            foreach (($item['series'] ?? []) as $serie) {
                \App\Models\ProductSerie::where('company_id',$company->id)
                    ->where('serie',trim($serie))->where('estado','disponible')
                    ->update(['estado'=>'vendida','invoice_id'=>$invoice->id]);
            }
        }
```
Y en `app/Http/Controllers/InvoiceController.php`, en el validate de `store`, agregá:
```php
            'items.*.series'=>['sometimes','array'],
```

### 5. Rutas — en `routes/api.php` (dentro del grupo auth:sanctum)
```php
    // Fase 7 — Series (garantías)
    Route::get("series", [\App\Http\Controllers\SerieController::class, "index"]);
    Route::post("series", [\App\Http\Controllers\SerieController::class, "store"]);
    Route::get("series/lookup", [\App\Http\Controllers\SerieController::class, "lookup"]);
    Route::get("series/trace", [\App\Http\Controllers\SerieController::class, "trace"]);
```

### 6. Migrar y probar
```bash
cd contabilidad-backend && php artisan migrate
# Probar: crear series de una compra, buscar una, vender con series en el POS.
```

## B. Frontend

### 1. Productos: switch "maneja series" — en `src/views/Products.vue`
- En `EMPTY` y `openEdit` agregá `maneja_series: false` / `maneja_series: !!product.maneja_series`.
- En el form (antes de Precio) agregá:
```html
<label style="display:flex; align-items:center; gap:8px;">
  <Checkbox v-model="form.maneja_series" :binary="true" /> Maneja series (IMEI / n° de serie)
</label>
```
(importá `Checkbox from 'primevue/checkbox'`). En el backend `ProductController`, agregá
`'maneja_series'=>['boolean'],` en store y update.

### 2. POS: vender escaneando la serie — en `src/views/Pos.vue`
Agregá arriba de la grilla de productos un input de escaneo:
```html
<input
  placeholder="Escanear serie o código… (Enter agrega)"
  style="width:100%; padding:10px 12px; border:1px solid #e2e5ea; border-radius:8px; margin-bottom:12px;"
  @keydown.enter.prevent="escanear(($event.target as HTMLInputElement).value); ($event.target as HTMLInputElement).value=''"
/>
```
Y en el script:
```ts
async function escanear(valor: string) {
  if (!valor.trim()) return
  // 1) ¿es una serie disponible?
  try {
    const res = await api.get('/series/lookup?company_id=' + company.activeId + '&serie=' + encodeURIComponent(valor))
    const p = res.data.product
    const item = cart.value.find((i) => i.id === p.id)
    if (item) { item.qty++; (item.series ??= []).push(res.data.serie) }
    else cart.value.push({ ...p, qty: 1, series: [res.data.serie] })
    return
  } catch { /* no es serie, sigo */ }
  // 2) ¿es un código de producto?
  const p = products.value.find((x) => x.codigo === valor.trim())
  if (p) add(p)
  else alert('No se encontró serie ni código: ' + valor)
}
```
Y en `emitir()`, al mapear items agregá `series: i.series ?? []`.

### 3. Compras: pedir series al importar (opcional en esta fase)
Tras importar una compra con producto `maneja_series`, abrí un Dialog que pida las N series
(una por unidad) y haga `POST /series` con `{product_id, purchase_id, series:[...]}`.

### 4. Kardex + garantía
En `Inventory.vue` podés agregar un buscador de serie que llame `GET /series/trace?serie=X`
y muestre: producto, compra (proveedor) y factura (cliente). Eso ES la garantía.

---

# FASE 8 — Importar compras del SRI por TXT (en lote)

> Como lo mostró el creador: bajás el TXT del portal SRI (todas las facturas del mes),
> lo subís, y el sistema trae los XML del SRI usando la clave de acceso de cada una.
> ⚠️ El SRI solo entrega el XML durante ~1 mes desde la emisión.

## A. Backend

### 1. Migración — `database/migrations/2026_07_22_000001_create_pending_imports_table.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('pending_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('clave_acceso', 49)->unique();
            $t->string('ruc_emisor')->nullable();
            $t->string('razon_social')->nullable();
            $t->string('serie_comprobante')->nullable();
            $t->date('fecha')->nullable();
            $t->string('estado')->default('pendiente'); // pendiente|descargada|procesada|error
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('pending_imports'); }
};
```

### 2. Modelo — `app/Models/PendingImport.php`
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PendingImport extends Model {
    protected $fillable = ['company_id','clave_acceso','ruc_emisor','razon_social',
        'serie_comprobante','fecha','estado','error'];
}
```

### 3. Servicio: descargar el XML del SRI por clave de acceso
`app/Services/SriXmlDownloader.php` — usa el WS público de autorización del SRI
(el mismo que consulta la librería). La respuesta de autorización INCLUYE el comprobante XML.
```php
<?php
namespace App\Services;
use SoapClient;

class SriXmlDownloader {
    // ambiente 1 = pruebas (celcer), 2 = producción (cel)
    private const WSDL = [
        1 => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
        2 => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
    ];
    /** Devuelve el XML del comprobante autorizado, o null si el SRI ya no lo entrega. */
    public function download(string $claveAcceso, int $ambiente = 2): ?string {
        $client = new SoapClient(self::WSDL[$ambiente] ?? self::WSDL[2], ['exceptions' => true]);
        $res = $client->autorizacionComprobante(['claveAccesoComprobante' => $claveAcceso]);
        $aut = $res->RespuestaAutorizacionComprobante->autorizaciones->autorizacion ?? null;
        if (is_array($aut)) $aut = $aut[0] ?? null;
        if (!$aut || ($aut->estado ?? '') !== 'AUTORIZADO') return null;
        return (string) $aut->comprobante; // el XML de la factura del proveedor
    }
}
```

### 4. Controlador — `app/Http/Controllers/PendingImportController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\PendingImport;
use App\Services\SriXmlDownloader;
use App\Services\ParseSriPurchaseXml;
use App\Services\RegisterInventoryMovement;
use App\Services\GeneratePurchaseJournalEntry;
use Illuminate\Http\Request;

class PendingImportController extends Controller {
    public function index(Request $r) {
        return PendingImport::where('company_id',$r->company_id)->latest()->get();
    }
    // Subir el TXT del portal SRI (separado por tabs; la clave de acceso tiene 49 dígitos)
    public function uploadTxt(Request $r) {
        $r->validate(['company_id'=>['required','exists:companies,id'],'txt'=>['required','file','max:4096']]);
        $lineas = file($r->file('txt')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $insertadas = 0;
        foreach ($lineas as $linea) {
            // La clave de acceso son 49 dígitos seguidos, en cualquier columna
            if (!preg_match('/\b(\d{49})\b/', $linea, $m)) continue;
            $cols = preg_split('/\t|,;?/', $linea);
            PendingImport::firstOrCreate(
                ['clave_acceso'=>$m[1]],
                ['company_id'=>$r->company_id,
                 'ruc_emisor'=>substr($m[1],10,13),
                 'razon_social'=>trim($cols[1] ?? '') ?: null,
                 'fecha'=>self::fechaDeClave($m[1]),'estado'=>'pendiente']);
            $insertadas++;
        }
        return ['insertadas'=>$insertadas];
    }
    // Traer los XML del SRI y procesarlos como compras (todo en uno)
    public function process(Request $r, SriXmlDownloader $dl, ParseSriPurchaseXml $parser,
                            RegisterInventoryMovement $inv, GeneratePurchaseJournalEntry $asiento) {
        $r->validate(['company_id'=>['required','exists:companies,id']]);
        $company = Company::findOrFail($r->company_id);
        $ok=0; $errores=0;
        foreach (PendingImport::where('company_id',$company->id)->where('estado','pendiente')->get() as $p) {
            try {
                $xml = $dl->download($p->clave_acceso, (int)$company->ambiente);
                if (!$xml) { $p->update(['estado'=>'error','error'=>'El SRI ya no entrega este XML (pasó el mes) o no está autorizado.']); $errores++; continue; }
                // Reusar el mismo flujo del import individual
                $req = new Request(['company_id'=>$company->id]);
                $d = $parser->parse($xml);
                app(\App\Http\Controllers\PurchaseController::class); // referencia
                // Inserta compra reutilizando la lógica: llamamos al parser + creación directa
                \Illuminate\Support\Facades\DB::transaction(function() use ($company,$d,$inv,$asiento) {
                    $prov = \App\Models\Contact::firstOrCreate(
                        ['company_id'=>$company->id,'identificacion'=>$d['proveedor']['identificacion']],
                        $d['proveedor'] + ['company_id'=>$company->id,'es_proveedor'=>true,'es_cliente'=>false]);
                    $purchase = \App\Models\Purchase::firstOrCreate(
                        ['company_id'=>$company->id,'clave_acceso'=>$d['comprobante']['clave_acceso']],
                        ['contact_id'=>$prov->id,'numero'=>$d['comprobante']['numero'],
                         'fecha_emision'=>$d['comprobante']['fecha_emision'],'items'=>$d['items'],
                         'total_sin_impuestos'=>$d['totales']['total_sin_impuestos'],
                         'total_impuesto'=>$d['totales']['total_impuesto'],
                         'importe_total'=>$d['totales']['importe_total'],
                         'saldo_pendiente'=>$d['totales']['importe_total'],'xml'=>$d['xml']]);
                    if ($purchase->wasRecentlyCreated) {
                        foreach ($d['items'] as $item) {
                            $codigo = trim((string)($item['codigo_principal'] ?? ''));
                            $cant = (float)($item['cantidad'] ?? 0);
                            if ($codigo==='' || $cant<=0) continue;
                            $prod = \App\Models\Product::firstOrCreate(
                                ['company_id'=>$company->id,'codigo'=>$codigo],
                                ['descripcion'=>$item['descripcion'] ?? $codigo,'tipo'=>'bien',
                                 'precio'=>$item['precio_unitario'] ?? 0,'tarifa_iva'=>$item['tarifa'] ?? 15]);
                            if ($prod->tipo !== 'servicio')
                                $inv->handle($prod,'ingreso',$cant,(float)($item['precio_unitario'] ?? 0),
                                    'Compra '.$purchase->numero,$purchase->fecha_emision->toDateString());
                        }
                        $asiento->handle($purchase);
                    }
                });
                $p->update(['estado'=>'procesada']); $ok++;
            } catch (\Throwable $e) {
                $p->update(['estado'=>'error','error'=>substr($e->getMessage(),0,250)]); $errores++;
            }
        }
        return ['procesadas'=>$ok,'errores'=>$errores];
    }
    private static function fechaDeClave(string $clave): ?string {
        // La clave empieza ddmmyyyy
        $d=substr($clave,0,2); $m=substr($clave,2,2); $y=substr($clave,4,4);
        return checkdate((int)$m,(int)$d,(int)$y) ? "$y-$m-$d" : null;
    }
}
```

### 5. Rutas — en `routes/api.php`
```php
    // Fase 8 — Importación en lote desde TXT del SRI
    Route::get("pending-imports", [\App\Http\Controllers\PendingImportController::class, "index"]);
    Route::post("pending-imports/upload-txt", [\App\Http\Controllers\PendingImportController::class, "uploadTxt"]);
    Route::post("pending-imports/process", [\App\Http\Controllers\PendingImportController::class, "process"]);
```
```bash
php artisan migrate
```

## B. Frontend — agregar a `src/views/Purchases.vue`
Un segundo botón "Importar TXT del SRI (lote)" con su input file `.txt`, que haga
`POST /pending-imports/upload-txt`, luego muestre la lista `GET /pending-imports` en una
tabla (razón social, fecha, estado), y un botón "Traer XML y procesar" →
`POST /pending-imports/process` (mostrar procesadas/errores). Mismo patrón del botón XML
que ya existe.

---

# FASE 9 — Extras del video (después de 7 y 8)

1. **Combos/componentes**: tabla `product_components (product_id, component_id, cantidad)`.
   Al vender un combo, descargar del stock cada componente (loop en InvoiceEmitter).
2. **Listas de precios**: tabla `price_lists (product_id, nombre, precio)`. En el POS,
   selector de lista.
3. **Min/Max de stock**: columnas `stock_minimo`/`stock_maximo` en products + reporte
   "qué reponer" (stock < mínimo).
4. **Códigos alternos**: tabla `product_codes (product_id, codigo)` y que el escaneo del
   POS también busque ahí.
5. **Ubicación física**: columna `ubicacion` en products (texto: "Fila 3, Columna B").
6. **Conciliación auto-match**: al subir el archivo del banco (CSV), por cada fila buscar
   `bank_movements` con mismo monto ±0.01 y fecha ±2 días → marcar `conciliado=true`
   automáticamente (las "coincidencias en azul" de KVS).
7. **Multi-bodega**: tabla `warehouses` + columna `warehouse_id` en inventory_movements.
   Solo si el cliente lo pide.

---

## Recordatorios
- Probá cada fase antes de pasar a la siguiente.
- Con el .p12 cargado (pantalla Empresas), la emisión firma y envía de verdad.
- El SRI solo entrega XMLs de ~1 mes hacia atrás: avisale al cliente que importe mensual.

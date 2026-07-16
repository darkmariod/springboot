# 🚀 TODAS LAS FASES — código completo para pegar

> **Un solo archivo, en orden, nada opcional.** Todo lo que explicaron la contadora y el creador de KVS.
> Backend: `contabilidad-backend` · Frontend: `contabilidad-vue`
>
> **Cómo trabajar:** hacé las fases EN ORDEN. Cada una tiene su sección "Probar" al final.
> Si después de una fase los estados financieros dejan de cuadrar, el asiento quedó mal.
>
> ## Orden
> | # | Fase | Por qué |
> |---|------|---------|
> | 0 | Arreglar el `.env` | Sin esto el backend no arranca |
> | 1 | Firma electrónica (completar) | Sin firma no facturás de verdad |
> | 2 | Series / IMEI | **Tu negocio**: garantías de computadoras |
> | 3 | Usuarios, roles y auditoría | Rápido y la contadora lo pidió fuerte |
> | 4 | Notas de crédito y anticipos | Cartera completa |
> | 5 | Importar TXT del SRI en lote | Como te mostró el creador |
> | 6 | Extras del video (combos, pistola, auto-match) | Diferenciadores |
> | 7 | Nómina | La que menos te bloquea para vender |
> | 8 | **Planes y features** | Así vende KVS: un código, vendés desbloqueando |
>
> ## 🖥️ La interfaz — decidido y cerrado (no la toques)
> Igual a KVS: **menú lateral + pestañas de trabajo**. Nada más.
> - **NO hay pantalla de inicio ni lanzador de tarjetas** — el cliente no lo quiere. Al entrar,
>   el área de trabajo arranca vacía y se abre lo que hagas clic en el menú.
> - Las pestañas **no se cierran solas** — podés tener varias a la vez (lo que la contadora
>   valoró de MicroPlus y odia de Contífico).
> - **Botón ⛶ maximizar** a la derecha de las pestañas: esconde menú y topbar para ganar
>   pantalla (Esc restaura). Es el *"hacerla más grande"* del video.
> - **Atajos:** `Ctrl+P` imprimir · `Ctrl+F` buscar en tablas · `Ctrl+W` cerrar pestaña
>   (Ctrl+W solo funciona de verdad si la instalás como PWA).
>
> **Ya está construido y compilando.** Estas fases NO tocan el layout, solo agregan pantallas
> nuevas y las cuelgan del menú vía `src/modules.ts`.

---
---

# FASE 0 — Arreglar el `.env` (OBLIGATORIO, 2 minutos)

Al mover el proyecto a `springboot/app/`, el `.env` quedó apuntando a la carpeta **vieja**.
Por eso el backend no arranca.

Abrí `contabilidad-backend/.env` y dejá esta línea con la ruta NUEVA:

```
DB_DATABASE=/Users/mariopazmino/Desktop/springboot/app/contabilidad-backend/database/database.sqlite
```

```bash
cd /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend
php artisan migrate
php artisan serve          # dejalo corriendo
```

> Tu `database/database.sqlite` YA está en la ruta nueva con todos los datos.

---
---

# FASE 1 — Firma electrónica completa

> ## ⚠️ NO CONFUNDIR — son DOS módulos distintos
>
> | Módulo | Qué se sube | Cuándo | Para qué |
> |--------|-------------|--------|----------|
> | **Configuración de firma** (esta fase) | `.p12` + clave + correo | **UNA SOLA VEZ** | **EMITIR** tus facturas |
> | **Importar del SRI** (Fase 5) | XML o TXT del portal | **CADA MES** | Registrar tus **compras** |
>
> **En el módulo de configuración NO se sube ningún XML ni TXT.** Solo la firma.
>
> ### El "facturador" del SRI — qué es en realidad
> El creador te lo aclaró textual en el video:
> > *"El **facturador** que tiene la SRI **es un programa que tiene la SRI para nosotros facturar**.
> > (...) **En vez de ocupar el programa del SRI**, nosotros aquí ingresamos la configuración de
> > su firma electrónica. Aquí ponemos su firma, la clave de la firma, un correo con el que
> > queremos que se envíen las facturas. Y lo de acá son los websites del SRI."*
>
> O sea: **el facturador del SRI es un programa de escritorio para EMITIR** (el SRI lo regala).
> Este módulo lo **reemplaza**. No tiene NADA que ver con importar.
>
> ### ¿Y "traer los datos del cliente/proveedor"?
> **Ya lo hacemos.** La razón social viene **dentro del XML** — no hay que ir a buscarla a ningún
> lado. Al importar una compra, el proveedor se crea solo con los datos del XML.
>
> ### ¿En qué plan va?
> **En TODOS**, incluido el básico. El creador dijo que el básico de $289 **sí incluye facturación
> electrónica**. Lo que el básico **no** incluye es que **ellos te regalen el certificado**:
> > *"Pero este plan **no incluye la firma electrónica**. Este [PRO] ya incluye la firma por un año...
> > nosotros le ponemos la firma y cuando se le vaya a terminar, le volvemos a renovar."*
>
> 👉 **La diferencia entre planes NO es la pantalla — es quién pone el certificado.**
> Básico: el cliente trae su `.p12`. PRO: se lo das vos (y se lo renovás). Eso es **comercial,
> no software**. Por eso esta pantalla usa `feature: 'facturacion_sri'`, que el básico tiene.
>
> ---
>
> Se configura **una sola vez**; solo se renueva cuando vence el certificado.
> La pantalla ya está hecha — falta que el backend guarde el correo y lea el vencimiento.

## 1.1 Migración
`database/migrations/2026_07_23_000001_add_signature_config.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('email_envio')->nullable();
            $t->string('cert_sujeto')->nullable();
            $t->date('cert_valido_hasta')->nullable();
        });
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t) => $t->dropColumn(['email_envio','cert_sujeto','cert_valido_hasta']));
    }
};
```
> Este archivo ya existe en tu proyecto. Solo corré `php artisan migrate`.

## 1.2 En `app/Models/Company.php` — agregá al `$fillable`:
```php
'email_envio', 'cert_sujeto', 'cert_valido_hasta',
```

## 1.3 En `app/Http/Controllers/CompanyController.php` — reemplazá `uploadCertificate`:
```php
    public function uploadCertificate(\Illuminate\Http\Request $request, Company $company)
    {
        $request->validate([
            "certificado" => ["required", "file", "max:5120"],
            "clave" => ["required", "string"],
            "email_envio" => ["nullable", "email"],
            "ambiente" => ["nullable", "in:1,2"],
        ]);
        $contenido = file_get_contents($request->file("certificado")->getRealPath());
        if (! @openssl_pkcs12_read($contenido, $info, $request->input("clave"))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                "clave" => ["El certificado no abre con esa clave, o el archivo no es un .p12 válido."],
            ]);
        }
        // Datos del certificado para mostrar titular y vencimiento
        $cert = openssl_x509_parse($info["cert"] ?? "");
        $company->update([
            "certificado_p12" => $contenido,
            "certificado_clave" => $request->input("clave"),
            "email_envio" => $request->input("email_envio") ?: $company->email_envio,
            "ambiente" => $request->input("ambiente") ?: $company->ambiente,
            "cert_sujeto" => $cert["subject"]["CN"] ?? null,
            "cert_valido_hasta" => isset($cert["validTo_time_t"]) ? date("Y-m-d", $cert["validTo_time_t"]) : null,
        ]);
        return ["ok" => true, "mensaje" => "Firma cargada y validada. La facturación ya firma y envía al SRI."];
    }
```

## 1.4 Probar
Administración → Firma electrónica → cargá tu `.p12` + clave → tiene que decir
**"Firma cargada · vence 2027-XX-XX"**. Desde ahí, cada factura se firma y se envía al SRI de verdad.

---
---

# FASE 2 — Series / IMEI (garantías) 🔥

> **La feature de tu negocio.** Sin series, si tenés 2 proveedores del mismo producto no sabés
> a cuál devolver una garantía, y el cliente te puede traer una unidad que no es tuya.

## A. BACKEND

### 2.1 Migración — `database/migrations/2026_07_21_000001_create_product_series_table.php`
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
            $t->string('serie');                          // IMEI / número de serie
            $t->string('estado')->default('disponible');  // disponible | vendida
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

### 2.2 Modelo — `app/Models/ProductSerie.php`
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

### 2.3 En `app/Models/Product.php`
Agregá `'maneja_series'` al `$fillable` y esta relación:
```php
    public function series() { return $this->hasMany(ProductSerie::class); }
```

### 2.4 En `app/Http/Controllers/ProductController.php`
Agregá a las reglas de `store` y `update`:
```php
            'maneja_series' => ['boolean'],
```

### 2.5 Controlador — `app/Http/Controllers/SerieController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\ProductSerie;
use Illuminate\Http\Request;

class SerieController extends Controller {
    // ?product_id=1&estado=disponible
    public function index(Request $r) {
        return ProductSerie::with('product:id,codigo,descripcion','purchase:id,numero','invoice:id,numero')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->product_id, fn($q,$id)=>$q->where('product_id',$id))
            ->when($r->estado, fn($q,$e)=>$q->where('estado',$e))
            ->orderBy('serie')->get();
    }
    // Registrar series al COMPRAR (una por unidad)
    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'product_id'=>['required','exists:products,id'],
            'purchase_id'=>['nullable','exists:purchases,id'],
            'series'=>['required','array','min:1'],
            'series.*'=>['required','string'],
        ]);
        $creadas = [];
        foreach ($d['series'] as $s) {
            $serie = trim($s);
            if ($serie === '') continue;
            $creadas[] = ProductSerie::firstOrCreate(
                ['company_id'=>$d['company_id'],'serie'=>$serie],
                ['product_id'=>$d['product_id'],'purchase_id'=>$d['purchase_id'] ?? null,'estado'=>'disponible']);
        }
        return response()->json($creadas, 201);
    }
    // Buscar UNA serie disponible (para vender escaneando con la pistola)
    public function lookup(Request $r) {
        $s = ProductSerie::with('product')
            ->where('company_id',$r->company_id)->where('serie',trim($r->serie ?? ''))
            ->where('estado','disponible')->first();
        return $s ?: response()->json(['message'=>'Serie no encontrada o ya vendida'], 404);
    }
    // GARANTÍA: historia completa de una serie (a quién compré, a quién vendí)
    public function trace(Request $r) {
        $s = ProductSerie::with('product:id,codigo,descripcion',
                'purchase.contact:id,razon_social','invoice.contact:id,razon_social')
            ->where('company_id',$r->company_id)->where('serie',trim($r->serie ?? ''))->first();
        return $s ?: response()->json(['message'=>'Serie no encontrada'], 404);
    }
}
```

### 2.6 Marcar series vendidas al facturar — en `app/Services/InvoiceEmitter.php`
Dentro de `emit()`, DESPUÉS del bloque `foreach ($items as $item) {...}` del inventario:
```php
        // Series: marcarlas vendidas y ligarlas a esta factura (trazabilidad de garantía)
        foreach ($items as $item) {
            foreach (($item['series'] ?? []) as $serie) {
                \App\Models\ProductSerie::where('company_id', $company->id)
                    ->where('serie', trim($serie))->where('estado', 'disponible')
                    ->update(['estado' => 'vendida', 'invoice_id' => $invoice->id]);
            }
        }
```

### 2.7 En `app/Http/Controllers/InvoiceController.php`
Agregá al validate de `store`:
```php
            'items.*.series' => ['sometimes','array'],
```

### 2.8 Rutas — en `routes/api.php` (dentro del grupo `auth:sanctum`)
```php
    // Fase 2 — Series (garantías)
    Route::get("series", [\App\Http\Controllers\SerieController::class, "index"]);
    Route::post("series", [\App\Http\Controllers\SerieController::class, "store"]);
    Route::get("series/lookup", [\App\Http\Controllers\SerieController::class, "lookup"]);
    Route::get("series/trace", [\App\Http\Controllers\SerieController::class, "trace"]);
```
```bash
php artisan migrate
```

## B. FRONTEND

### 2.9 `src/views/Products.vue` — switch "maneja series"
Importá el Checkbox:
```ts
import Checkbox from 'primevue/checkbox'
```
En el objeto vacío del form y al editar, agregá `maneja_series`. En el formulario (antes de Precio):
```html
<label style="display:flex; align-items:center; gap:8px;">
  <Checkbox v-model="form.maneja_series" :binary="true" /> Maneja series (IMEI / n° de serie)
</label>
```

### 2.10 `src/views/Pos.vue` — vender escaneando con la pistola
En el `<script setup>`:
```ts
async function escanear(valor: string) {
  const v = valor.trim()
  if (!v) return
  // 1) ¿es una serie disponible?
  try {
    const res = await api.get('/series/lookup?company_id=' + company.activeId + '&serie=' + encodeURIComponent(v))
    const p = res.data.product
    const item = cart.value.find((i) => i.id === p.id)
    if (item) { item.qty++; (item.series ??= []).push(res.data.serie) }
    else cart.value.push({ ...p, qty: 1, series: [res.data.serie] })
    return
  } catch { /* no es serie, sigo */ }
  // 2) ¿es un código de producto?
  const p = products.value.find((x) => x.codigo === v)
  if (p) add(p)
  else alert('No se encontró serie ni código: ' + v)
}
```
En `emitir()`, al mapear los items agregá `series`:
```ts
      items: cart.value.map((i) => ({ codigo_principal: i.codigo, descripcion: i.descripcion,
        cantidad: i.qty, precio_unitario: Number(i.precio), tarifa: Number(i.tarifa_iva),
        series: i.series ?? [] })),
```
En el template, arriba de la grilla de productos:
```html
<input
  placeholder="Escanear serie o código… (Enter agrega)"
  style="width:100%; padding:10px 12px; border:1px solid #e2e5ea; border-radius:8px; margin-bottom:12px;"
  @keydown.enter.prevent="escanear(($event.target as HTMLInputElement).value); ($event.target as HTMLInputElement).value=''"
/>
```

### 2.11 `src/views/Series.vue` — consulta de garantía (NUEVO)
```vue
<script setup lang="ts">
import { ref } from 'vue'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const serie = ref('')
const resultado = ref<any>(null)
const error = ref('')
const buscando = ref(false)

async function buscar() {
  if (!serie.value.trim()) return
  buscando.value = true; error.value = ''; resultado.value = null
  try {
    resultado.value = (await api.get('/series/trace?company_id=' + company.activeId +
      '&serie=' + encodeURIComponent(serie.value))).data
  } catch {
    error.value = 'No se encontró la serie ' + serie.value
  } finally { buscando.value = false }
}
</script>

<template>
  <div style="padding:24px; max-width:640px;">
    <h2 style="margin:0 0 4px;">Consulta de garantía por serie</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 18px;">
      Escaneá o escribí la serie: te dice a qué proveedor le compraste esa unidad y a qué cliente
      se la vendiste.
    </p>

    <div style="display:flex; gap:8px; margin-bottom:18px;">
      <InputText v-model="serie" placeholder="Serie / IMEI" style="flex:1"
                 @keydown.enter="buscar" />
      <Button label="Buscar" icon="pi pi-search" :loading="buscando" @click="buscar" />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <div v-if="resultado" style="background:#fff; border:1px solid #e2e5ea; border-radius:12px; padding:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <div>
          <div style="font-size:12px; color:#94a3b8;">PRODUCTO</div>
          <b style="font-size:16px;">{{ resultado.product?.descripcion }}</b>
          <div style="font-family:monospace; color:#64748b;">{{ resultado.serie }}</div>
        </div>
        <Tag :value="resultado.estado" :severity="resultado.estado === 'vendida' ? 'info' : 'success'" />
      </div>

      <div style="border-top:1px solid #f1f3f6; padding-top:14px; margin-bottom:14px;">
        <div style="font-size:12px; color:#94a3b8;">SE LA COMPRASTE A</div>
        <b>{{ resultado.purchase?.contact?.razon_social ?? '— sin compra registrada —' }}</b>
        <div v-if="resultado.purchase" style="color:#64748b; font-size:13px;">
          Factura {{ resultado.purchase.numero }}
        </div>
      </div>

      <div style="border-top:1px solid #f1f3f6; padding-top:14px;">
        <div style="font-size:12px; color:#94a3b8;">SE LA VENDISTE A</div>
        <b>{{ resultado.invoice?.contact?.razon_social ?? '— todavía en stock —' }}</b>
        <div v-if="resultado.invoice" style="color:#64748b; font-size:13px;">
          Factura {{ resultado.invoice.numero }}
        </div>
      </div>
    </div>
  </div>
</template>
```

### 2.12 Ingresar series al importar una compra — en `src/views/Purchases.vue`
En el `<script setup>`:
```ts
const seriesDialog = ref<any>(null)

// Tras importar, si algún producto maneja series, pedirlas
async function pedirSeries(purchase: any) {
  const productos = (await api.get('/products?company_id=' + company.activeId)).data
  const pendientes = (purchase.items ?? [])
    .map((it: any) => {
      const p = productos.find((x: any) => x.codigo === it.codigo_principal)
      return p?.maneja_series ? { product: p, cantidad: Number(it.cantidad), series: [] as string[] } : null
    })
    .filter(Boolean)
  if (pendientes.length) seriesDialog.value = { purchase, pendientes, idx: 0 }
}
async function guardarSeries() {
  for (const p of seriesDialog.value.pendientes) {
    const limpias = p.series.filter((s: string) => s.trim())
    if (limpias.length) {
      await api.post('/series', {
        company_id: company.activeId, product_id: p.product.id,
        purchase_id: seriesDialog.value.purchase.id, series: limpias,
      })
    }
  }
  seriesDialog.value = null
}
```
En `importar()`, después de `load()`, agregá: `pedirSeries(res.data)`.

Y el diálogo al final del template:
```html
<Dialog :visible="!!seriesDialog" modal header="Ingresar series de los productos" style="width:520px"
        @update:visible="seriesDialog=null">
  <div v-if="seriesDialog" style="display:flex; flex-direction:column; gap:18px;">
    <Message severity="info" :closable="false">
      Podés usar el lector de código de barras: escaneá y presioná Enter en cada campo.
    </Message>
    <div v-for="p in seriesDialog.pendientes" :key="p.product.id">
      <b>{{ p.product.descripcion }}</b>
      <span style="color:#94a3b8;"> — {{ p.cantidad }} unidades</span>
      <div style="display:flex; flex-direction:column; gap:6px; margin-top:8px;">
        <InputText v-for="n in p.cantidad" :key="n" v-model="p.series[n-1]"
                   :placeholder="'Serie ' + n" />
      </div>
    </div>
  </div>
  <template #footer>
    <Button label="Después" text @click="seriesDialog=null" />
    <Button label="Guardar series" @click="guardarSeries" />
  </template>
</Dialog>
```
> Importá en Purchases.vue: `Dialog`, `InputText`.

### 2.13 Conectar en `src/modules.ts` (grupo Inventario)
```ts
{ key: 'series', label: 'Garantías por serie', icon: 'pi pi-qrcode', component: 'Series' },
```
Y en `src/layouts/MainLayout.vue`: importá `Series` y agregalo al `componentMap`.

## Probar
1. Marcá un producto con "maneja series".
2. Importá una compra de 2 unidades → te pide las 2 series → guardalas.
3. En el POS, escaneá una serie → se agrega al carrito → emití la factura.
4. Inventario → Garantías por serie → buscá esa serie: **te dice a quién se la compraste y a quién se la vendiste.**

---
---

# FASE 3 — Usuarios, roles, punto de emisión y auditoría

> La contadora insistió: **cada usuario factura SOLO con su punto de emisión** (si el de la
> farmacia usa el punto de caja, rompe la numeración y el SRI no perdona). Y **quién hizo cada factura**.

## A. BACKEND

### 3.1 Migración — `database/migrations/2026_07_26_000001_create_roles_and_audit.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->string('rol')->default('admin');   // admin | contador | cajero
            $t->foreignId('emission_point_id')->nullable()->constrained();
            $t->boolean('activo')->default(true);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('accion');   // creo | actualizo | elimino
            $t->string('modelo');
            $t->unsignedBigInteger('modelo_id')->nullable();
            $t->string('descripcion')->nullable();
            $t->json('cambios')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['modelo', 'modelo_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('emission_point_id');
            $t->dropColumn(['rol', 'activo']);
        });
    }
};
```

### 3.2 En `app/Models/User.php`
Agregá al `$fillable`: `'rol', 'emission_point_id', 'activo',` y estos métodos:
```php
    public function emissionPoint() { return $this->belongsTo(EmissionPoint::class); }
    public function esAdmin(): bool { return $this->rol === 'admin'; }
    public function puedeUsarPunto(?int $emissionPointId): bool {
        // Sin punto asignado = puede usar cualquiera (admin / contador)
        if (! $this->emission_point_id) return true;
        return $this->emission_point_id === $emissionPointId;
    }
```

### 3.3 Modelo — `app/Models/AuditLog.php`
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model {
    protected $fillable = ['company_id','user_id','accion','modelo','modelo_id',
        'descripcion','cambios','ip'];
    protected $casts = ['cambios'=>'array'];
    public function user() { return $this->belongsTo(User::class); }
}
```

### 3.4 Trait — `app/Models/Concerns/Auditable.php`
```php
<?php
namespace App\Models\Concerns;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable {
    public static function bootAuditable(): void {
        static::created(fn($m) => $m->registrarAuditoria('creo'));
        static::updated(fn($m) => $m->registrarAuditoria('actualizo', $m->getChanges()));
        static::deleted(fn($m) => $m->registrarAuditoria('elimino'));
    }
    public function registrarAuditoria(string $accion, array $cambios = []): void {
        if (! Auth::check()) return; // seeders / consola no auditan
        unset($cambios['updated_at']);
        AuditLog::create([
            'company_id'  => $this->company_id ?? null,
            'user_id'     => Auth::id(),
            'accion'      => $accion,
            'modelo'      => class_basename($this),
            'modelo_id'   => $this->getKey(),
            'descripcion' => $this->numero ?? $this->razon_social ?? $this->descripcion ?? null,
            'cambios'     => $cambios ?: null,
            'ip'          => Request::ip(),
        ]);
    }
}
```

### 3.5 Activar en los modelos
En `Invoice`, `Purchase`, `JournalEntry`, `Product`, `Contact`:
```php
use App\Models\Concerns\Auditable;

class Invoice extends Model {
    use Auditable;    // ← agregá esta línea
```

### 3.6 Restringir el punto de emisión — en `app/Http/Controllers/InvoiceController.php`
Al inicio de `store()`:
```php
        // La contadora insistió: cada usuario factura SOLO con su punto de emisión
        $user = $request->user();
        if (! $user->puedeUsarPunto($request->input('emission_point_id'))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'emission_point_id' => ['No podés facturar con un punto de emisión que no es el tuyo.'],
            ]);
        }
```

### 3.7 Middleware — `app/Http/Middleware/EnsureRole.php`
```php
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class EnsureRole {
    public function handle(Request $request, Closure $next, string ...$roles) {
        $user = $request->user();
        if (! $user || ! $user->activo) abort(403, 'Usuario inactivo.');
        if ($roles && ! in_array($user->rol, $roles, true)) {
            abort(403, 'Tu rol no tiene permiso para esta acción.');
        }
        return $next($request);
    }
}
```
En `bootstrap/app.php`, dentro de `->withMiddleware(function (Middleware $middleware) {`:
```php
    $middleware->alias(['rol' => \App\Http\Middleware\EnsureRole::class]);
```

### 3.8 Controlador — `app/Http/Controllers/UserController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller {
    public function index(Request $r) {
        return User::with('emissionPoint:id,estab,punto,nombre')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderBy('name')->get(['id','name','email','rol','emission_point_id','activo','company_id']);
    }
    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'name'=>['required','string'],
            'email'=>['required','email','unique:users,email'],
            'password'=>['required','string','min:8'],
            'rol'=>['required','in:admin,contador,cajero'],
            'emission_point_id'=>['nullable','exists:emission_points,id'],
        ]);
        $d['password'] = Hash::make($d['password']);
        return response()->json(User::create($d), 201);
    }
    public function update(Request $r, User $user) {
        $d = $r->validate([
            'name'=>['sometimes','string'],
            'email'=>['sometimes','email', Rule::unique('users','email')->ignore($user->id)],
            'password'=>['nullable','string','min:8'],
            'rol'=>['sometimes','in:admin,contador,cajero'],
            'emission_point_id'=>['nullable','exists:emission_points,id'],
            'activo'=>['sometimes','boolean'],
        ]);
        if (! empty($d['password'])) $d['password'] = Hash::make($d['password']);
        else unset($d['password']);
        $user->update($d);
        return $user->load('emissionPoint:id,estab,punto,nombre');
    }
}
```

### 3.9 Controlador — `app/Http/Controllers/AuditController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller {
    public function index(Request $r) {
        return AuditLog::with('user:id,name')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->modelo, fn($q,$m)=>$q->where('modelo',$m))
            ->latest()->limit(300)->get();
    }
}
```

### 3.10 Rutas — en `routes/api.php`
```php
    // Fase 3 — Usuarios y auditoría (solo admin)
    Route::middleware('rol:admin')->group(function () {
        Route::get("users", [\App\Http\Controllers\UserController::class, "index"]);
        Route::post("users", [\App\Http\Controllers\UserController::class, "store"]);
        Route::put("users/{user}", [\App\Http\Controllers\UserController::class, "update"]);
        Route::get("audit", [\App\Http\Controllers\AuditController::class, "index"]);
    });
```
```bash
php artisan migrate
```

## B. FRONTEND

### 3.11 `src/views/Users.vue`
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const puntos = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ rol: 'cajero', activo: true })
const roles = [
  { label: 'Administrador (todo)', value: 'admin' },
  { label: 'Contador (contabilidad y reportes)', value: 'contador' },
  { label: 'Cajero (solo vende en su punto)', value: 'cajero' },
]
const sevRol: Record<string, string> = { admin: 'danger', contador: 'info', cajero: 'secondary' }

async function load() {
  loading.value = true
  rows.value = (await api.get('/users?company_id=' + company.activeId)).data
  puntos.value = (await api.get('/emission-points?company_id=' + company.activeId)).data
  loading.value = false
}
function nuevo() { form.value = { rol: 'cajero', activo: true }; dialog.value = true }
function editar(r: any) { form.value = { ...r, password: '' }; dialog.value = true }
async function guardar() {
  const payload = { ...form.value, company_id: company.activeId }
  if (form.value.id) await api.put('/users/' + form.value.id, payload)
  else await api.post('/users', payload)
  dialog.value = false; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Usuarios y roles</h2>
      <Button label="Nuevo usuario" icon="pi pi-plus" @click="nuevo" />
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Si asignás un <b>punto de emisión</b> a un usuario, solo va a poder facturar con ese punto.
      Así nadie rompe la numeración de otra caja.
    </Message>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="name" header="Nombre" />
      <Column field="email" header="Email" />
      <Column header="Rol"><template #body="{ data }">
        <Tag :value="data.rol" :severity="sevRol[data.rol]" /></template></Column>
      <Column header="Punto de emisión"><template #body="{ data }">
        <span v-if="data.emission_point">{{ data.emission_point.estab }}-{{ data.emission_point.punto }}
          ({{ data.emission_point.nombre }})</span>
        <span v-else style="color:#94a3b8;">Todos</span></template></Column>
      <Column header="Estado"><template #body="{ data }">
        <Tag :value="data.activo ? 'Activo' : 'Inactivo'" :severity="data.activo ? 'success':'secondary'" /></template></Column>
      <Column header=""><template #body="{ data }">
        <Button icon="pi pi-pencil" text @click="editar(data)" /></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Usuario" style="width:460px">
      <div style="display:flex; flex-direction:column; gap:12px;">
        <label style="display:flex; flex-direction:column; gap:4px;">Nombre *<InputText v-model="form.name" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Email *<InputText v-model="form.email" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">
          {{ form.id ? 'Contraseña (vacía = no cambiar)' : 'Contraseña *' }}
          <Password v-model="form.password" :feedback="false" toggleMask fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Rol *
          <Select v-model="form.rol" :options="roles" optionLabel="label" optionValue="value" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Punto de emisión (vacío = todos)
          <Select v-model="form.emission_point_id" :options="puntos" optionValue="id" showClear
                  :optionLabel="(p) => p.estab + '-' + p.punto + ' (' + p.nombre + ')'" fluid /></label>
        <label style="display:flex; align-items:center; gap:8px;">
          <Checkbox v-model="form.activo" :binary="true" /> Usuario activo
        </label>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog=false" />
        <Button label="Guardar" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
```

### 3.12 `src/views/Audit.vue`
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const sev: Record<string, string> = { creo: 'success', actualizo: 'warn', elimino: 'danger' }

onMounted(async () => {
  rows.value = (await api.get('/audit?company_id=' + company.activeId)).data
  loading.value = false
})
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Auditoría</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">Quién hizo cada operación en el sistema</p>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows paginator :rows="20">
      <Column header="Cuándo"><template #body="{ data }">
        {{ new Date(data.created_at).toLocaleString() }}</template></Column>
      <Column header="Quién"><template #body="{ data }">{{ data.user?.name ?? '—' }}</template></Column>
      <Column header="Acción"><template #body="{ data }">
        <Tag :value="data.accion" :severity="sev[data.accion]" /></template></Column>
      <Column field="modelo" header="Módulo" />
      <Column field="descripcion" header="Documento" />
      <Column field="ip" header="IP" />
    </DataTable>
  </div>
</template>
```

### 3.13 Conectar en `src/modules.ts` (grupo Administración)
```ts
{ key: 'users', label: 'Usuarios y roles', icon: 'pi pi-shield', component: 'Users' },
{ key: 'audit', label: 'Auditoría', icon: 'pi pi-history', component: 'Audit' },
```
Y en `MainLayout.vue`: importá `Users` y `Audit`, agregalos al `componentMap`.

## Probar
1. Creá 2 puntos: `001-901 Caja` y `001-902 Farmacia`.
2. Creá un cajero asignado a `001-901`.
3. Con ese usuario, intentá facturar con `001-902` → **debe rechazarlo**.
4. Emití una factura → Auditoría muestra quién, cuándo y desde qué IP.

---
---

# FASE 4 — Notas de crédito, anticipos y uso de saldos

> **Conceptos** (esto es lo que confunde):
> - **Nota de crédito SRI**: comprobante electrónico (devolución de mercadería). Va al SRI.
> - **Nota INTERNA**: NO va al SRI. Da de baja una deuda por cruce de cuentas.
> - **Anticipo**: plata que te dan ANTES de facturar → nace un pasivo (le debés mercadería).
> - **Abono**: pago aplicado a UNA factura que ya existe (ya lo tenés en Cuentas por cobrar).
> - **Uso de saldos**: cruzar un anticipo o nota CONTRA una factura.

## A. BACKEND

### 4.1 Migración — `database/migrations/2026_07_24_000001_create_credit_notes_and_advances.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('credit_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->enum('tipo', ['sri', 'interna'])->default('interna');
            $t->string('numero')->nullable();
            $t->date('fecha');
            $t->string('motivo');
            $t->json('items')->nullable();
            $t->decimal('total_sin_impuestos', 12, 2)->default(0);
            $t->decimal('total_impuesto', 12, 2)->default(0);
            $t->decimal('importe_total', 12, 2);
            $t->decimal('saldo_disponible', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->date('fecha');
            $t->decimal('monto', 12, 2);
            $t->decimal('saldo', 12, 2);
            $t->string('forma_pago', 20);
            $t->foreignId('bank_id')->nullable()->constrained();
            $t->string('nota')->nullable();
            $t->timestamps();
        });
        Schema::create('credit_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->nullableMorphs('origen'); // Advance o CreditNote
            $t->decimal('monto', 12, 2);
            $t->date('fecha');
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('credit_applications');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('credit_notes');
    }
};
```

### 4.2 Modelos
`app/Models/CreditNote.php`
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CreditNote extends Model {
    protected $fillable = ['company_id','contact_id','invoice_id','tipo','numero','fecha',
        'motivo','items','total_sin_impuestos','total_impuesto','importe_total','saldo_disponible'];
    protected $casts = ['items'=>'array','fecha'=>'date'];
    public function contact() { return $this->belongsTo(Contact::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
}
```
`app/Models/Advance.php`
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Advance extends Model {
    protected $fillable = ['company_id','contact_id','fecha','monto','saldo','forma_pago','bank_id','nota'];
    protected $casts = ['fecha'=>'date'];
    public function contact() { return $this->belongsTo(Contact::class); }
}
```
`app/Models/CreditApplication.php`
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CreditApplication extends Model {
    protected $fillable = ['invoice_id','origen_type','origen_id','monto','fecha'];
}
```

### 4.3 `app/Http/Controllers/AdvanceController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\Advance;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvanceController extends Controller {
    public function index(Request $r) {
        return Advance::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->con_saldo, fn($q)=>$q->where('saldo','>',0))
            ->latest('fecha')->get();
    }
    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'contact_id'=>['required','exists:contacts,id'],
            'monto'=>['required','numeric','min:0.01'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque'],
            'bank_id'=>['nullable','exists:banks,id'],
            'nota'=>['nullable','string'],
        ]);
        return DB::transaction(function() use ($d) {
            $a = Advance::create($d + ['fecha'=>now()->toDateString(), 'saldo'=>$d['monto']]);
            // Entra el dinero, nace un pasivo (le debés la mercadería al cliente)
            $destino = $d['forma_pago']==='efectivo'
                ? ['codigo'=>'1.1.01','nombre'=>'Caja','tipo'=>'activo']
                : ['codigo'=>'1.1.02','nombre'=>'Bancos','tipo'=>'activo'];
            SimpleEntry::make($d['company_id'], 'Anticipo de cliente', [
                $destino + ['debe'=>$d['monto'],'haber'=>0,'ref'=>'ANT-'.$a->id],
                ['codigo'=>'2.1.03','nombre'=>'Anticipos de clientes','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>$d['monto'],'ref'=>'ANT-'.$a->id],
            ], $a);
            return response()->json($a->load('contact'), 201);
        });
    }
}
```

### 4.4 `app/Http/Controllers/CreditNoteController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\CreditNote;
use App\Services\DocumentCalculator;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditNoteController extends Controller {
    public function index(Request $r) {
        return CreditNote::with('contact:id,razon_social','invoice:id,numero')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest('fecha')->get();
    }
    public function store(Request $r, DocumentCalculator $calc) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'contact_id'=>['required','exists:contacts,id'],
            'invoice_id'=>['nullable','exists:invoices,id'],
            'tipo'=>['required','in:sri,interna'],
            'motivo'=>['required','string'],
            'items'=>['sometimes','array'],
            'importe_total'=>['required_without:items','numeric','min:0.01'],
        ]);
        return DB::transaction(function() use ($d, $calc) {
            if (!empty($d['items'])) {
                $t = $calc->fromItems($d['items']);
                $d['total_sin_impuestos'] = $t['total_sin_impuestos'];
                $d['total_impuesto'] = $t['total_impuesto'];
                $d['importe_total'] = $t['importe_total'];
            }
            $n = CreditNote::create($d + [
                'fecha'=>now()->toDateString(),
                'saldo_disponible'=>$d['importe_total'],
            ]);
            SimpleEntry::make($d['company_id'], 'Nota de crédito '.$d['tipo'].' — '.$d['motivo'], [
                ['codigo'=>'4.1.02','nombre'=>'Devoluciones y descuentos en ventas','tipo'=>'gasto',
                 'debe'=>$d['importe_total'],'haber'=>0,'ref'=>'NC-'.$n->id],
                ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo',
                 'debe'=>0,'haber'=>$d['importe_total'],'ref'=>'NC-'.$n->id],
            ], $n);
            return response()->json($n->load('contact'), 201);
        });
    }
}
```

### 4.5 USO DE SALDOS — `app/Http/Controllers/CreditApplicationController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\Advance;
use App\Models\CreditApplication;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditApplicationController extends Controller {
    // Saldos a favor de un cliente (anticipos + notas sin usar)
    public function available(Request $r) {
        $anticipos = Advance::where('company_id',$r->company_id)
            ->where('contact_id',$r->contact_id)->where('saldo','>',0)->get()
            ->map(fn($a)=>['tipo'=>'anticipo','id'=>$a->id,'fecha'=>$a->fecha->format('Y-m-d'),
                'disponible'=>(float)$a->saldo,'detalle'=>$a->nota ?? 'Anticipo']);
        $notas = CreditNote::where('company_id',$r->company_id)
            ->where('contact_id',$r->contact_id)->where('saldo_disponible','>',0)->get()
            ->map(fn($n)=>['tipo'=>'nota','id'=>$n->id,'fecha'=>$n->fecha->format('Y-m-d'),
                'disponible'=>(float)$n->saldo_disponible,'detalle'=>$n->motivo]);
        $todos = $anticipos->concat($notas);
        return ['saldos'=>$todos->values(),'total'=>round($todos->sum('disponible'),2)];
    }
    // Cruzar anticipo/nota CONTRA una factura
    public function apply(Request $r, Invoice $invoice) {
        $d = $r->validate([
            'tipo'=>['required','in:anticipo,nota'],
            'id'=>['required','integer'],
            'monto'=>['required','numeric','min:0.01'],
        ]);
        $origen = $d['tipo']==='anticipo' ? Advance::findOrFail($d['id']) : CreditNote::findOrFail($d['id']);
        $campoSaldo = $d['tipo']==='anticipo' ? 'saldo' : 'saldo_disponible';

        if ($d['monto'] > (float)$origen->$campoSaldo + 0.001)
            throw ValidationException::withMessages(['monto'=>['El monto supera el saldo disponible.']]);
        if ($d['monto'] > (float)$invoice->saldo_pendiente + 0.001)
            throw ValidationException::withMessages(['monto'=>['El monto supera el saldo de la factura.']]);

        return DB::transaction(function() use ($invoice,$origen,$d,$campoSaldo) {
            CreditApplication::create([
                'invoice_id'=>$invoice->id,
                'origen_type'=>$origen->getMorphClass(),
                'origen_id'=>$origen->getKey(),
                'monto'=>$d['monto'],'fecha'=>now()->toDateString(),
            ]);
            $origen->decrement($campoSaldo, $d['monto']);
            $invoice->decrement('saldo_pendiente', $d['monto']);
            $debe = $d['tipo']==='anticipo'
                ? ['codigo'=>'2.1.03','nombre'=>'Anticipos de clientes','tipo'=>'pasivo']
                : ['codigo'=>'4.1.02','nombre'=>'Devoluciones y descuentos en ventas','tipo'=>'gasto'];
            SimpleEntry::make($invoice->company_id, 'Uso de saldo en factura '.$invoice->numero, [
                $debe + ['debe'=>$d['monto'],'haber'=>0,'ref'=>$invoice->numero],
                ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo',
                 'debe'=>0,'haber'=>$d['monto'],'ref'=>$invoice->numero],
            ], $invoice);
            return ['ok'=>true,'saldo_factura'=>(float)$invoice->fresh()->saldo_pendiente];
        });
    }
}
```

### 4.6 PAGO MÚLTIPLE — agregá a `app/Http/Controllers/PayableController.php`
```php
    // Pagar varias compras de una (reposición de caja chica)
    public function payMultiple(\Illuminate\Http\Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque,cruce'],
            'bank_id'=>['nullable','exists:banks,id'],
            'pagos'=>['required','array','min:1'],
            'pagos.*.purchase_id'=>['required','exists:purchases,id'],
            'pagos.*.monto'=>['required','numeric','min:0.01'],
        ]);
        return \Illuminate\Support\Facades\DB::transaction(function() use ($d) {
            $total = 0;
            foreach ($d['pagos'] as $p) {
                $purchase = \App\Models\Purchase::findOrFail($p['purchase_id']);
                if ($p['monto'] > (float)$purchase->saldo_pendiente + 0.001)
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'pagos'=>["El pago a {$purchase->numero} supera su saldo."]]);
                \App\Models\PurchasePayment::create([
                    'purchase_id'=>$purchase->id,'fecha'=>now()->toDateString(),
                    'monto'=>$p['monto'],'forma_pago'=>$d['forma_pago'],'bank_id'=>$d['bank_id'] ?? null,
                ]);
                $purchase->decrement('saldo_pendiente', $p['monto']);
                $total += $p['monto'];
            }
            $origen = match($d['forma_pago']) {
                'efectivo' => ['codigo'=>'1.1.01','nombre'=>'Caja','tipo'=>'activo'],
                'cruce'    => ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo'],
                default    => ['codigo'=>'1.1.02','nombre'=>'Bancos','tipo'=>'activo'],
            };
            // UN solo asiento por todo el lote
            \App\Services\SimpleEntry::make($d['company_id'],
                'Pago múltiple a proveedores ('.count($d['pagos']).' facturas)', [
                ['codigo'=>'2.1.01','nombre'=>'Cuentas por pagar proveedores','tipo'=>'pasivo',
                 'debe'=>round($total,2),'haber'=>0,'ref'=>'PAGO-MULT'],
                $origen + ['debe'=>0,'haber'=>round($total,2),'ref'=>'PAGO-MULT'],
            ]);
            return ['ok'=>true,'pagado'=>round($total,2),'facturas'=>count($d['pagos'])];
        });
    }
```

### 4.7 En `app/Http/Controllers/ReceivableController.php`
En el `map()` de `index()`, agregá `contact_id` para que el frontend sepa de qué cliente es cada factura:
```php
                    'contact_id'=>$i->contact_id,
```

### 4.8 Rutas — en `routes/api.php`
```php
    // Fase 4 — Notas de crédito, anticipos y uso de saldos
    Route::get("advances", [\App\Http\Controllers\AdvanceController::class, "index"]);
    Route::post("advances", [\App\Http\Controllers\AdvanceController::class, "store"]);
    Route::get("credit-notes", [\App\Http\Controllers\CreditNoteController::class, "index"]);
    Route::post("credit-notes", [\App\Http\Controllers\CreditNoteController::class, "store"]);
    Route::get("credits/available", [\App\Http\Controllers\CreditApplicationController::class, "available"]);
    Route::post("credits/apply/{invoice}", [\App\Http\Controllers\CreditApplicationController::class, "apply"]);
    Route::post("payables/pay-multiple", [\App\Http\Controllers\PayableController::class, "payMultiple"]);
```
```bash
php artisan migrate
```

## B. FRONTEND

### 4.9 `src/views/Advances.vue`
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const contacts = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ forma_pago: 'efectivo', monto: 0 })
const formas = [
  { label: 'Efectivo', value: 'efectivo' },
  { label: 'Transferencia', value: 'transferencia' },
  { label: 'Cheque', value: 'cheque' },
]
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/advances?company_id=' + company.activeId)).data
  contacts.value = (await api.get('/contacts?company_id=' + company.activeId)).data
  loading.value = false
}
async function guardar() {
  await api.post('/advances', { ...form.value, company_id: company.activeId })
  dialog.value = false; form.value = { forma_pago: 'efectivo', monto: 0 }; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div>
        <h2 style="margin:0;">Anticipos de clientes</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">
          Plata recibida antes de facturar. Después se cruza contra la factura desde Cuentas por cobrar.
        </p>
      </div>
      <Button label="Nuevo anticipo" icon="pi pi-plus" @click="dialog = true" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column field="forma_pago" header="Forma de pago" />
      <Column header="Monto"><template #body="{ data }">{{ money(data.monto) }}</template></Column>
      <Column header="Saldo sin usar"><template #body="{ data }"><b>{{ money(data.saldo) }}</b></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Nuevo anticipo" style="width:420px">
      <div style="display:flex; flex-direction:column; gap:12px;">
        <label style="display:flex; flex-direction:column; gap:4px;">Cliente
          <Select v-model="form.contact_id" :options="contacts" optionLabel="razon_social" optionValue="id" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Monto
          <InputNumber v-model="form.monto" mode="currency" currency="USD" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Forma de pago
          <Select v-model="form.forma_pago" :options="formas" optionLabel="label" optionValue="value" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Nota
          <InputText v-model="form.nota" fluid /></label>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog=false" />
        <Button label="Guardar" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
```

### 4.10 `src/views/CreditNotes.vue`
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const contacts = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ tipo: 'interna', importe_total: 0 })
const tipos = [
  { label: 'Interna (no va al SRI)', value: 'interna' },
  { label: 'SRI (comprobante electrónico)', value: 'sri' },
]
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/credit-notes?company_id=' + company.activeId)).data
  contacts.value = (await api.get('/contacts?company_id=' + company.activeId)).data
  loading.value = false
}
async function guardar() {
  await api.post('/credit-notes', { ...form.value, company_id: company.activeId })
  dialog.value = false; form.value = { tipo: 'interna', importe_total: 0 }; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Notas de crédito</h2>
      <Button label="Nueva nota" icon="pi pi-plus" @click="dialog = true" />
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      La nota <b>interna</b> da de baja una deuda por cruce de cuentas y no se envía al SRI.
      La nota <b>SRI</b> es un comprobante electrónico (devolución de mercadería).
    </Message>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
      <Column header="Tipo"><template #body="{ data }">
        <Tag :value="data.tipo" :severity="data.tipo==='sri' ? 'info' : 'secondary'" /></template></Column>
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column field="motivo" header="Motivo" />
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
      <Column header="Sin usar"><template #body="{ data }"><b>{{ money(data.saldo_disponible) }}</b></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Nueva nota de crédito" style="width:440px">
      <div style="display:flex; flex-direction:column; gap:12px;">
        <label style="display:flex; flex-direction:column; gap:4px;">Tipo
          <Select v-model="form.tipo" :options="tipos" optionLabel="label" optionValue="value" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Cliente
          <Select v-model="form.contact_id" :options="contacts" optionLabel="razon_social" optionValue="id" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Motivo
          <InputText v-model="form.motivo" placeholder="Devolución de mercadería / cruce" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Importe total
          <InputNumber v-model="form.importe_total" mode="currency" currency="USD" fluid /></label>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog=false" />
        <Button label="Guardar" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
```

### 4.11 "Usar saldo" en `src/views/Receivables.vue`
En el `<script setup>` agregá:
```ts
const saldos = ref<any>({ saldos: [], total: 0 })
const usarDialog = ref<any>(null)

async function abrirUsarSaldo(r: any) {
  const res = await api.get('/credits/available?company_id=' + company.activeId +
    '&contact_id=' + r.contact_id)
  saldos.value = res.data
  usarDialog.value = { invoice: r, seleccion: null, monto: 0 }
}
async function aplicarSaldo() {
  const s = usarDialog.value.seleccion
  await api.post('/credits/apply/' + usarDialog.value.invoice.id, {
    tipo: s.tipo, id: s.id, monto: usarDialog.value.monto,
  })
  usarDialog.value = null; load()
}
```
En la columna de acciones, junto a "Registrar cobro":
```html
<Button label="Usar saldo" size="small" outlined @click="abrirUsarSaldo(r)" />
```
Y el diálogo al final del template:
```html
<Dialog :visible="!!usarDialog" modal header="Usar saldo a favor" style="width:460px" @update:visible="usarDialog=null">
  <div v-if="usarDialog" style="display:flex; flex-direction:column; gap:12px;">
    <div style="background:#f8fafc; padding:10px; border-radius:8px;">
      Factura <b>{{ usarDialog.invoice.numero }}</b> — saldo {{ money(usarDialog.invoice.saldo) }}
    </div>
    <p v-if="!saldos.saldos.length" style="color:#94a3b8;">Este cliente no tiene saldos a favor.</p>
    <DataTable v-else :value="saldos.saldos" size="small" selectionMode="single"
               v-model:selection="usarDialog.seleccion" dataKey="id">
      <Column field="tipo" header="Tipo" />
      <Column field="fecha" header="Fecha" />
      <Column field="detalle" header="Detalle" />
      <Column header="Disponible"><template #body="{ data: s }">{{ money(s.disponible) }}</template></Column>
    </DataTable>
    <label v-if="usarDialog.seleccion" style="display:flex; flex-direction:column; gap:4px;">
      Monto a cruzar<InputNumber v-model="usarDialog.monto" mode="currency" currency="USD" fluid /></label>
  </div>
  <template #footer>
    <Button label="Cancelar" text @click="usarDialog=null" />
    <Button label="Aplicar" :disabled="!usarDialog?.seleccion || !usarDialog?.monto" @click="aplicarSaldo" />
  </template>
</Dialog>
```

### 4.12 Conectar en `src/modules.ts` (grupo Ventas)
```ts
{ key: 'advances', label: 'Anticipos', icon: 'pi pi-arrow-down-left', component: 'Advances' },
{ key: 'credit-notes', label: 'Notas de crédito', icon: 'pi pi-file-excel', component: 'CreditNotes' },
```
Y en `MainLayout.vue`: importá `Advances` y `CreditNotes`, agregalos al `componentMap`.

## Probar
1. Anticipo de $200 a Juan Pérez.
2. Factura a Juan por $500 a crédito.
3. Cuentas por cobrar → "Usar saldo" → aplicá $200 → **saldo baja a $300**.
4. Libro diario: deben estar el asiento del anticipo y el del cruce.
5. Estados financieros: **debe seguir cuadrando**.

---
---

# FASE 5 — Importar TXT del SRI en lote

> Como te mostró el creador: bajás del portal del SRI el **TXT con todas las facturas del mes**,
> lo subís, y el sistema **va al SRI y trae los XML solo**.
> ⚠️ El SRI solo entrega el XML durante ~1 mes desde la emisión → hay que importar mensual.

## A. BACKEND

### 5.1 Migración — `database/migrations/2026_07_22_000001_create_pending_imports_table.php`
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
            $t->date('fecha')->nullable();
            $t->string('estado')->default('pendiente'); // pendiente|procesada|error
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('pending_imports'); }
};
```

### 5.2 Modelo — `app/Models/PendingImport.php`
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PendingImport extends Model {
    protected $fillable = ['company_id','clave_acceso','ruc_emisor','razon_social',
        'fecha','estado','error'];
}
```

### 5.3 Descargar el XML del SRI — `app/Services/SriXmlDownloader.php`
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
        if (! $aut || ($aut->estado ?? '') !== 'AUTORIZADO') return null;
        return (string) $aut->comprobante; // el XML de la factura del proveedor
    }
}
```

### 5.4 Reutilizar el alta de compra — `app/Services/StorePurchaseFromXml.php`
```php
<?php
namespace App\Services;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

class StorePurchaseFromXml {
    public function __construct(
        private ParseSriPurchaseXml $parser,
        private RegisterInventoryMovement $inventario,
        private GeneratePurchaseJournalEntry $asiento,
    ) {}

    /** Crea la compra (proveedor + inventario + asiento) desde el XML. Idempotente por clave de acceso. */
    public function handle(Company $company, string $xml): Purchase {
        $d = $this->parser->parse($xml);
        return DB::transaction(function () use ($company, $d) {
            $prov = Contact::firstOrCreate(
                ['company_id'=>$company->id, 'identificacion'=>$d['proveedor']['identificacion']],
                $d['proveedor'] + ['company_id'=>$company->id, 'es_proveedor'=>true, 'es_cliente'=>false]);

            $purchase = Purchase::firstOrCreate(
                ['company_id'=>$company->id, 'clave_acceso'=>$d['comprobante']['clave_acceso']],
                ['contact_id'=>$prov->id, 'numero'=>$d['comprobante']['numero'],
                 'fecha_emision'=>$d['comprobante']['fecha_emision'], 'items'=>$d['items'],
                 'total_sin_impuestos'=>$d['totales']['total_sin_impuestos'],
                 'total_impuesto'=>$d['totales']['total_impuesto'],
                 'importe_total'=>$d['totales']['importe_total'],
                 'saldo_pendiente'=>$d['totales']['importe_total'], 'xml'=>$d['xml']]);

            if (! $purchase->wasRecentlyCreated) return $purchase; // ya estaba importada

            foreach ($d['items'] as $item) {
                $codigo = trim((string)($item['codigo_principal'] ?? ''));
                $cant = (float)($item['cantidad'] ?? 0);
                if ($codigo === '' || $cant <= 0) continue;
                $prod = Product::firstOrCreate(
                    ['company_id'=>$company->id, 'codigo'=>$codigo],
                    ['descripcion'=>$item['descripcion'] ?? $codigo, 'tipo'=>'bien',
                     'precio'=>$item['precio_unitario'] ?? 0, 'tarifa_iva'=>$item['tarifa'] ?? 15]);
                if ($prod->tipo !== 'servicio')
                    $this->inventario->handle($prod, 'ingreso', $cant, (float)($item['precio_unitario'] ?? 0),
                        'Compra '.$purchase->numero, $purchase->fecha_emision->toDateString());
            }
            $this->asiento->handle($purchase);
            return $purchase;
        });
    }
}
```

### 5.5 Controlador — `app/Http/Controllers/PendingImportController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\PendingImport;
use App\Services\SriXmlDownloader;
use App\Services\StorePurchaseFromXml;
use Illuminate\Http\Request;

class PendingImportController extends Controller {
    public function index(Request $r) {
        return PendingImport::where('company_id', $r->company_id)->latest()->get();
    }
    // Subir el TXT del portal SRI (la clave de acceso son 49 dígitos seguidos)
    public function uploadTxt(Request $r) {
        $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'txt'=>['required','file','max:8192'],
        ]);
        $lineas = file($r->file('txt')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $insertadas = 0; $repetidas = 0;
        foreach ($lineas as $linea) {
            if (! preg_match('/\b(\d{49})\b/', $linea, $m)) continue;
            $cols = preg_split('/\t|;/', $linea);
            $p = PendingImport::firstOrCreate(
                ['clave_acceso'=>$m[1]],
                ['company_id'=>$r->company_id,
                 'ruc_emisor'=>substr($m[1], 10, 13),
                 'razon_social'=>trim($cols[1] ?? '') ?: null,
                 'fecha'=>self::fechaDeClave($m[1]),
                 'estado'=>'pendiente']);
            $p->wasRecentlyCreated ? $insertadas++ : $repetidas++;
        }
        return ['insertadas'=>$insertadas, 'repetidas'=>$repetidas];
    }
    // Traer los XML del SRI y registrarlos como compras
    public function process(Request $r, SriXmlDownloader $dl, StorePurchaseFromXml $store) {
        $r->validate(['company_id'=>['required','exists:companies,id']]);
        $company = Company::findOrFail($r->company_id);
        $ok = 0; $errores = 0;
        foreach (PendingImport::where('company_id', $company->id)->where('estado', 'pendiente')->get() as $p) {
            try {
                $xml = $dl->download($p->clave_acceso, (int) $company->ambiente);
                if (! $xml) {
                    $p->update(['estado'=>'error',
                        'error'=>'El SRI ya no entrega este XML (pasó el mes) o no está autorizado.']);
                    $errores++; continue;
                }
                $store->handle($company, $xml);
                $p->update(['estado'=>'procesada', 'error'=>null]);
                $ok++;
            } catch (\Throwable $e) {
                $p->update(['estado'=>'error', 'error'=>substr($e->getMessage(), 0, 250)]);
                $errores++;
            }
        }
        return ['procesadas'=>$ok, 'errores'=>$errores];
    }
    private static function fechaDeClave(string $clave): ?string {
        // La clave de acceso empieza con ddmmyyyy
        $d = substr($clave,0,2); $m = substr($clave,2,2); $y = substr($clave,4,4);
        return checkdate((int)$m, (int)$d, (int)$y) ? "$y-$m-$d" : null;
    }
}
```

### 5.6 Rutas — en `routes/api.php`
```php
    // Fase 5 — Importación en lote desde el TXT del SRI
    Route::get("pending-imports", [\App\Http\Controllers\PendingImportController::class, "index"]);
    Route::post("pending-imports/upload-txt", [\App\Http\Controllers\PendingImportController::class, "uploadTxt"]);
    Route::post("pending-imports/process", [\App\Http\Controllers\PendingImportController::class, "process"]);
```
```bash
php artisan migrate
```

## B. FRONTEND

### 5.7 `src/views/BatchImport.vue`
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const trabajando = ref(false)
const msg = ref<any>(null)
const fileRef = ref<HTMLInputElement>()
const sev: Record<string, string> = { pendiente: 'warn', procesada: 'success', error: 'danger' }

async function load() {
  loading.value = true
  rows.value = (await api.get('/pending-imports?company_id=' + company.activeId)).data
  loading.value = false
}
async function subirTxt(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  trabajando.value = true; msg.value = null
  const form = new FormData()
  form.append('company_id', String(company.activeId)); form.append('txt', file)
  try {
    const res = await api.post('/pending-imports/upload-txt', form)
    msg.value = { type: 'success',
      text: `${res.data.insertadas} comprobantes nuevos · ${res.data.repetidas} ya estaban` }
    load()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? 'No se pudo leer el TXT.' }
  } finally { trabajando.value = false; if (fileRef.value) fileRef.value.value = '' }
}
async function procesar() {
  trabajando.value = true; msg.value = null
  const res = await api.post('/pending-imports/process', { company_id: company.activeId })
  msg.value = { type: res.data.errores ? 'warn' : 'success',
    text: `${res.data.procesadas} compras registradas · ${res.data.errores} con error` }
  trabajando.value = false; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div>
        <h2 style="margin:0;">Importar compras del SRI (lote)</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">
          Subí el TXT que bajás del portal del SRI con todas las facturas del mes.
        </p>
      </div>
      <div style="display:flex; gap:8px;">
        <input ref="fileRef" type="file" accept=".txt" style="display:none" @change="subirTxt" />
        <Button label="Subir TXT del SRI" icon="pi pi-upload" :loading="trabajando" @click="fileRef?.click()" />
        <Button label="Traer XML y procesar" icon="pi pi-cloud-download" severity="secondary"
                :loading="trabajando" :disabled="!rows.some(r => r.estado === 'pendiente')" @click="procesar" />
      </div>
    </div>

    <Message severity="warn" :closable="false" style="margin-bottom:14px;">
      El SRI solo entrega el XML durante <b>~1 mes</b> desde la emisión. Importá todos los meses
      o vas a perder esos comprobantes.
    </Message>
    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>

    <DataTable :value="rows" :loading="loading" size="small" stripedRows paginator :rows="15">
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha ?? '').slice(0,10) }}</template></Column>
      <Column field="ruc_emisor" header="RUC emisor" />
      <Column field="razon_social" header="Proveedor" />
      <Column header="Clave de acceso"><template #body="{ data }">
        <span style="font-family:monospace; font-size:11px;">{{ data.clave_acceso }}</span></template></Column>
      <Column header="Estado"><template #body="{ data }">
        <Tag :value="data.estado" :severity="sev[data.estado]" /></template></Column>
      <Column header="Detalle"><template #body="{ data }">
        <span style="font-size:12px; color:#d93025;">{{ data.error }}</span></template></Column>
    </DataTable>
  </div>
</template>
```

### 5.8 Conectar en `src/modules.ts` (grupo Compras)
```ts
{ key: 'batch-import', label: 'Importar del SRI (lote)', icon: 'pi pi-cloud-download', component: 'BatchImport' },
```
Y en `MainLayout.vue`: importá `BatchImport`, agregalo al `componentMap`.

## Probar
1. Bajá del portal del SRI el TXT de comprobantes recibidos del mes.
2. Compras → Importar del SRI (lote) → subí el TXT → aparecen como "pendiente".
3. "Traer XML y procesar" → se registran las compras con proveedor, inventario y asiento.
4. Los que digan "error: pasó el mes" son normales si el comprobante es viejo.

---
---

# FASE 6 — Extras del video (combos, listas de precios, min/max, auto-match)

## 6.1 Migración — `database/migrations/2026_07_27_000001_add_product_extras.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('stock_minimo', 12, 2)->default(0);
            $t->decimal('stock_maximo', 12, 2)->default(0);
            $t->string('ubicacion')->nullable();   // "Fila 3, Columna B"
            $t->boolean('es_combo')->default(false);
        });
        // Combos: un computador armado con partes
        Schema::create('product_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();   // el combo
            $t->foreignId('component_id')->constrained('products');          // la parte
            $t->decimal('cantidad', 12, 2)->default(1);
            $t->timestamps();
        });
        // Varias listas de precios por producto
        Schema::create('price_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('nombre');           // Mayorista, Distribuidor...
            $t->decimal('precio', 12, 2);
            $t->timestamps();
        });
        // Códigos alternos (el código del proveedor, por ejemplo)
        Schema::create('product_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('codigo');
            $t->timestamps();
            $t->index('codigo');
        });
    }
    public function down(): void {
        Schema::dropIfExists('product_codes');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_components');
        Schema::table('products', fn(Blueprint $t) =>
            $t->dropColumn(['stock_minimo','stock_maximo','ubicacion','es_combo']));
    }
};
```
> Agregá al `$fillable` de `Product`: `'stock_minimo','stock_maximo','ubicacion','es_combo',`
> y estas relaciones:
> ```php
>     public function components() { return $this->hasMany(ProductComponent::class); }
>     public function priceLists() { return $this->hasMany(PriceList::class); }
>     public function codes() { return $this->hasMany(ProductCode::class); }
> ```

## 6.2 Modelos
```php
// app/Models/ProductComponent.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductComponent extends Model {
    protected $fillable = ['product_id','component_id','cantidad'];
    public function component() { return $this->belongsTo(Product::class, 'component_id'); }
}
```
```php
// app/Models/PriceList.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PriceList extends Model {
    protected $fillable = ['product_id','nombre','precio'];
}
```
```php
// app/Models/ProductCode.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductCode extends Model {
    protected $fillable = ['product_id','codigo'];
}
```

## 6.3 Descargar los componentes al vender un combo — en `app/Services/InvoiceEmitter.php`
Dentro del `foreach ($items as $item)` del inventario, reemplazá el bloque del egreso por:
```php
            $product = Product::where('company_id',$company->id)->where('codigo',$codigo)->first();
            if (! $product) continue;
            if ($product->es_combo) {
                // Un combo no lleva stock propio: descarga cada componente
                foreach ($product->components as $c) {
                    $parte = $c->component;
                    if ($parte && $parte->tipo !== 'servicio')
                        $this->inventario->handle($parte, 'egreso', $cant * (float)$c->cantidad,
                            (float)$parte->costo_promedio, 'Venta combo '.$invoice->numero, $invoice->fecha_emision->toDateString());
                }
            } elseif ($product->tipo !== 'servicio') {
                $this->inventario->handle($product, 'egreso', $cant, (float)$product->costo_promedio,
                    'Venta '.$invoice->numero, $invoice->fecha_emision->toDateString());
            }
```

## 6.4 Reporte "qué reponer" + búsqueda por código alterno
En `app/Http/Controllers/InventoryController.php`:
```php
    // Productos bajo el stock mínimo (el sistema te dice qué comprar)
    public function reorder(Request $r) {
        return \App\Models\Product::where('company_id', $r->company_id)
            ->where('tipo', '!=', 'servicio')
            ->whereColumn('stock', '<', 'stock_minimo')
            ->orderBy('descripcion')->get()
            ->map(fn($p) => ['id'=>$p->id, 'codigo'=>$p->codigo, 'descripcion'=>$p->descripcion,
                'stock'=>(float)$p->stock, 'minimo'=>(float)$p->stock_minimo,
                'maximo'=>(float)$p->stock_maximo,
                'sugerido'=>max(0, (float)$p->stock_maximo - (float)$p->stock)]);
    }
```
Rutas:
```php
    Route::get("inventory/reorder", [\App\Http\Controllers\InventoryController::class, "reorder"]);
```

En `SerieController@lookup` ya buscás por serie. Para que el POS también encuentre por código
alterno, agregá a `ProductController`:
```php
    // Busca por código propio o alterno (para la pistola)
    public function lookup(Request $r) {
        $codigo = trim($r->codigo ?? '');
        $p = Product::where('company_id', $r->company_id)
            ->where(fn($q) => $q->where('codigo', $codigo)
                ->orWhereHas('codes', fn($c) => $c->where('codigo', $codigo)))
            ->first();
        return $p ?: response()->json(['message' => 'Producto no encontrado'], 404);
    }
```
```php
    Route::get("products/lookup", [\App\Http\Controllers\ProductController::class, "lookup"]);
```

## 6.5 CONCILIACIÓN AUTO-MATCH (las "coincidencias en azul" de KVS)
En `app/Http/Controllers/BankMovementController.php`:
```php
    // Sube el CSV del banco y marca automáticamente las coincidencias
    // CSV esperado: fecha,concepto,monto  (monto negativo = débito)
    public function autoMatch(Request $r) {
        $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'bank_id'=>['required','exists:banks,id'],
            'csv'=>['required','file','max:4096'],
        ]);
        $filas = array_map('str_getcsv', file($r->file('csv')->getRealPath(), FILE_SKIP_EMPTY_LINES));
        $conciliados = 0; $sinMatch = [];
        foreach ($filas as $i => $f) {
            if ($i === 0 && ! is_numeric(trim($f[2] ?? ''))) continue; // encabezado
            $fecha = trim($f[0] ?? ''); $monto = abs((float) ($f[2] ?? 0));
            if (! $monto) continue;
            // Coincide si el monto es igual (±1 centavo) y la fecha está a ±2 días
            $mov = \App\Models\BankMovement::where('company_id', $r->company_id)
                ->where('bank_id', $r->bank_id)->where('conciliado', false)
                ->whereBetween('monto', [$monto - 0.01, $monto + 0.01])
                ->whereBetween('fecha', [
                    \Carbon\Carbon::parse($fecha)->subDays(2)->toDateString(),
                    \Carbon\Carbon::parse($fecha)->addDays(2)->toDateString(),
                ])->first();
            if ($mov) { $mov->update(['conciliado' => true]); $conciliados++; }
            else $sinMatch[] = ['fecha'=>$fecha, 'concepto'=>trim($f[1] ?? ''), 'monto'=>$monto];
        }
        return ['conciliados'=>$conciliados, 'sin_match'=>$sinMatch];
    }
```
```php
    Route::post("bank-movements/auto-match", [\App\Http\Controllers\BankMovementController::class, "autoMatch"]);
```
En `src/views/Reconciliation.vue`, agregá un botón "Subir estado de cuenta (CSV)" que haga
`POST /bank-movements/auto-match` con el archivo y muestre
`X conciliados automáticamente · Y sin coincidencia` (listá los `sin_match` para revisarlos a mano).

```bash
php artisan migrate
```

## Probar
1. Creá un producto "Computador armado" con `es_combo` y 2 componentes.
2. Vendelo → el stock de **los componentes** baja, no el del combo.
3. Poné stock mínimo 5 a un producto con stock 2 → aparece en el reporte de reposición.
4. Subí un CSV del banco → debe marcar las coincidencias solo.

---
---

# FASE 7 — Nómina

> **Concepto clave** (acá se equivoca todo el mundo):
> - El **9.45%** de IESS se le **descuenta al empleado** → va en el rol.
> - El **11.15% patronal + décimos + fondos + vacaciones** los paga la **empresa** →
>   son **provisiones**, NO van en el rol.

## A. BACKEND

### 7.1 Migración — `database/migrations/2026_07_25_000001_create_payroll_tables.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('cedula');
            $t->string('nombres');
            $t->string('cargo')->nullable();
            $t->date('fecha_ingreso');
            $t->date('fecha_salida')->nullable();
            $t->decimal('sueldo', 12, 2);
            $t->boolean('fondos_reserva')->default(false);
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'cedula']);
        });
        Schema::create('payrolls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->decimal('total_ingresos', 12, 2)->default(0);
            $t->decimal('total_egresos', 12, 2)->default(0);
            $t->decimal('total_neto', 12, 2)->default(0);
            $t->decimal('total_provisiones', 12, 2)->default(0);
            $t->enum('estado', ['abierto', 'cerrado'])->default('abierto');
            $t->timestamps();
            $t->unique(['company_id', 'anio', 'mes']);
        });
        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained();
            $t->decimal('sueldo', 12, 2)->default(0);
            $t->decimal('horas_extra', 12, 2)->default(0);
            $t->decimal('comisiones', 12, 2)->default(0);
            $t->decimal('aporte_personal', 12, 2)->default(0);
            $t->decimal('prestamos', 12, 2)->default(0);
            $t->decimal('anticipos', 12, 2)->default(0);
            $t->decimal('neto', 12, 2)->default(0);
            $t->decimal('aporte_patronal', 12, 2)->default(0);
            $t->decimal('decimo_tercero', 12, 2)->default(0);
            $t->decimal('decimo_cuarto', 12, 2)->default(0);
            $t->decimal('fondos_reserva', 12, 2)->default(0);
            $t->decimal('vacaciones', 12, 2)->default(0);
            $t->timestamps();
        });
        // El SBU cambia cada año → configurable
        Schema::table('companies', fn(Blueprint $t) => $t->decimal('sbu', 10, 2)->default(470));
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t) => $t->dropColumn('sbu'));
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('employees');
    }
};
```
> Agregá `'sbu'` al `$fillable` de `Company`.

### 7.2 Modelos
```php
// app/Models/Employee.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Employee extends Model {
    protected $fillable = ['company_id','cedula','nombres','cargo','fecha_ingreso',
        'fecha_salida','sueldo','fondos_reserva','activo'];
    protected $casts = ['fecha_ingreso'=>'date','fecha_salida'=>'date',
        'fondos_reserva'=>'boolean','activo'=>'boolean'];
}
```
```php
// app/Models/Payroll.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payroll extends Model {
    protected $fillable = ['company_id','anio','mes','total_ingresos','total_egresos',
        'total_neto','total_provisiones','estado'];
    public function lines() { return $this->hasMany(PayrollLine::class); }
}
```
```php
// app/Models/PayrollLine.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PayrollLine extends Model {
    protected $fillable = ['payroll_id','employee_id','sueldo','horas_extra','comisiones',
        'aporte_personal','prestamos','anticipos','neto','aporte_patronal',
        'decimo_tercero','decimo_cuarto','fondos_reserva','vacaciones'];
    public function employee() { return $this->belongsTo(Employee::class); }
}
```

### 7.3 `app/Services/PayrollCalculator.php`
```php
<?php
namespace App\Services;
use App\Models\Employee;

class PayrollCalculator {
    // Porcentajes de ley (Ecuador)
    public const APORTE_PERSONAL = 0.0945;  // 9.45% lo paga el empleado
    public const APORTE_PATRONAL = 0.1115;  // 11.15% lo paga la empresa
    public const DECIMO_TERCERO  = 0.0833;  // sueldo / 12
    public const FONDOS_RESERVA  = 0.0833;  // solo después del primer año
    public const VACACIONES      = 0.0417;  // sueldo / 24

    /** @param array $extras horas_extra, comisiones, prestamos, anticipos */
    public function forEmployee(Employee $e, float $sbu, array $extras = []): array {
        $horasExtra = (float)($extras['horas_extra'] ?? 0);
        $comisiones = (float)($extras['comisiones'] ?? 0);
        $prestamos  = (float)($extras['prestamos'] ?? 0);
        $anticipos  = (float)($extras['anticipos'] ?? 0);

        $sueldo = (float)$e->sueldo;
        // El IESS se calcula sobre TODA la materia gravada, no solo el sueldo
        $materiaGravada = $sueldo + $horasExtra + $comisiones;
        $aportePersonal = round($materiaGravada * self::APORTE_PERSONAL, 2);
        $ingresos = round($materiaGravada, 2);
        $egresos  = round($aportePersonal + $prestamos + $anticipos, 2);

        return [
            'employee_id'      => $e->id,
            'sueldo'           => $sueldo,
            'horas_extra'      => $horasExtra,
            'comisiones'       => $comisiones,
            'aporte_personal'  => $aportePersonal,
            'prestamos'        => $prestamos,
            'anticipos'        => $anticipos,
            'neto'             => round($ingresos - $egresos, 2),
            // Provisiones: costo empresa, NO se le descuentan al empleado
            'aporte_patronal'  => round($materiaGravada * self::APORTE_PATRONAL, 2),
            'decimo_tercero'   => round($materiaGravada * self::DECIMO_TERCERO, 2),
            'decimo_cuarto'    => round($sbu / 12, 2),
            'fondos_reserva'   => $e->fondos_reserva ? round($materiaGravada * self::FONDOS_RESERVA, 2) : 0,
            'vacaciones'       => round($materiaGravada * self::VACACIONES, 2),
        ];
    }

    /** Liquidación / finiquito por salida */
    public function liquidacion(Employee $e, float $sbu, string $fechaSalida): array {
        $salida = \Carbon\Carbon::parse($fechaSalida);
        $ingreso = $e->fecha_ingreso;
        $sueldo = (float)$e->sueldo;

        // Décimo tercero: proporcional desde el 1-dic
        $inicioD3 = \Carbon\Carbon::create($salida->month >= 12 ? $salida->year : $salida->year - 1, 12, 1);
        $mesesD3 = max(0, $inicioD3->diffInMonths($salida));
        // Décimo cuarto: proporcional desde el 1-ago
        $inicioD4 = \Carbon\Carbon::create($salida->month >= 8 ? $salida->year : $salida->year - 1, 8, 1);
        $mesesD4 = max(0, $inicioD4->diffInMonths($salida));
        // Vacaciones no gozadas del último año
        $mesesVac = max(0, min(12, $ingreso->diffInMonths($salida) % 12));

        return [
            'dias_trabajados'     => (int) $ingreso->diffInDays($salida),
            'decimo_tercero_prop' => round($sueldo / 12 * $mesesD3, 2),
            'decimo_cuarto_prop'  => round($sbu / 12 * $mesesD4, 2),
            'vacaciones_prop'     => round($sueldo / 24 * $mesesVac, 2),
            'total' => round($sueldo / 12 * $mesesD3 + $sbu / 12 * $mesesD4 + $sueldo / 24 * $mesesVac, 2),
        ];
    }
}
```

### 7.4 `app/Http/Controllers/EmployeeController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller {
    public function index(Request $r) {
        return Employee::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderBy('nombres')->get();
    }
    public function store(Request $r) {
        return response()->json(Employee::create($this->validated($r)), 201);
    }
    public function update(Request $r, Employee $employee) {
        $employee->update($this->validated($r));
        return $employee;
    }
    public function destroy(Employee $employee) { $employee->delete(); return response()->noContent(); }

    private function validated(Request $r): array {
        return $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'cedula'=>['required','string','max:13'],
            'nombres'=>['required','string'],
            'cargo'=>['nullable','string'],
            'fecha_ingreso'=>['required','date'],
            'sueldo'=>['required','numeric','min:0'],
            'fondos_reserva'=>['boolean'],
            'activo'=>['boolean'],
        ]);
    }
}
```

### 7.5 `app/Http/Controllers/PayrollController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Services\PayrollCalculator;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller {
    public function index(Request $r) {
        return Payroll::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderByDesc('anio')->orderByDesc('mes')->get();
    }
    public function show(Payroll $payroll) {
        return $payroll->load('lines.employee:id,nombres,cedula,cargo');
    }
    // Genera el rol del mes para todos los empleados activos
    public function generate(Request $r, PayrollCalculator $calc) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'anio'=>['required','integer','min:2020'],
            'mes'=>['required','integer','min:1','max:12'],
            'extras'=>['sometimes','array'],
        ]);
        $company = Company::findOrFail($d['company_id']);
        $sbu = (float)($company->sbu ?? 470);

        return DB::transaction(function() use ($d, $company, $sbu, $calc) {
            $payroll = Payroll::updateOrCreate(
                ['company_id'=>$company->id, 'anio'=>$d['anio'], 'mes'=>$d['mes']],
                ['estado'=>'abierto']);
            $payroll->lines()->delete(); // regenerar limpio

            $ingresos = $egresos = $neto = $provisiones = 0;
            foreach (Employee::where('company_id',$company->id)->where('activo',true)->get() as $e) {
                $linea = $calc->forEmployee($e, $sbu, $d['extras'][$e->id] ?? []);
                $payroll->lines()->create($linea);
                $ingresos += $linea['sueldo'] + $linea['horas_extra'] + $linea['comisiones'];
                $egresos  += $linea['aporte_personal'] + $linea['prestamos'] + $linea['anticipos'];
                $neto     += $linea['neto'];
                $provisiones += $linea['aporte_patronal'] + $linea['decimo_tercero']
                    + $linea['decimo_cuarto'] + $linea['fondos_reserva'] + $linea['vacaciones'];
            }
            $payroll->update([
                'total_ingresos'=>round($ingresos,2), 'total_egresos'=>round($egresos,2),
                'total_neto'=>round($neto,2), 'total_provisiones'=>round($provisiones,2),
            ]);
            return $payroll->load('lines.employee:id,nombres,cedula,cargo');
        });
    }
    // Cierra el rol y lo contabiliza
    public function close(Payroll $payroll) {
        return DB::transaction(function() use ($payroll) {
            $l = $payroll->lines;
            $sueldos = round($l->sum('sueldo') + $l->sum('horas_extra') + $l->sum('comisiones'), 2);
            $aportePersonal = round($l->sum('aporte_personal'), 2);
            $descuentos = round($l->sum('prestamos') + $l->sum('anticipos'), 2);
            $patronal = round($l->sum('aporte_patronal'), 2);
            $provisiones = round($l->sum('decimo_tercero') + $l->sum('decimo_cuarto')
                + $l->sum('fondos_reserva') + $l->sum('vacaciones'), 2);
            $neto = round($l->sum('neto'), 2);

            SimpleEntry::make($payroll->company_id, "Rol de pagos {$payroll->mes}/{$payroll->anio}", [
                ['codigo'=>'5.2.01','nombre'=>'Sueldos y salarios','tipo'=>'gasto',
                 'debe'=>$sueldos,'haber'=>0,'ref'=>'ROL'],
                ['codigo'=>'5.2.02','nombre'=>'Aporte patronal IESS','tipo'=>'gasto',
                 'debe'=>$patronal,'haber'=>0,'ref'=>'ROL'],
                ['codigo'=>'5.2.03','nombre'=>'Beneficios sociales','tipo'=>'gasto',
                 'debe'=>$provisiones,'haber'=>0,'ref'=>'ROL'],
                ['codigo'=>'2.1.04','nombre'=>'IESS por pagar','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>round($aportePersonal + $patronal, 2),'ref'=>'ROL'],
                ['codigo'=>'2.1.05','nombre'=>'Beneficios sociales por pagar','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>$provisiones,'ref'=>'ROL'],
                ['codigo'=>'2.1.06','nombre'=>'Descuentos a empleados','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>$descuentos,'ref'=>'ROL'],
                ['codigo'=>'2.1.07','nombre'=>'Sueldos por pagar','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>$neto,'ref'=>'ROL'],
            ], $payroll);

            $payroll->update(['estado'=>'cerrado']);
            return ['ok'=>true, 'mensaje'=>'Rol cerrado y contabilizado.'];
        });
    }
    public function liquidacion(Request $r, Employee $employee, PayrollCalculator $calc) {
        $d = $r->validate(['fecha_salida'=>['required','date']]);
        $company = Company::findOrFail($employee->company_id);
        return $calc->liquidacion($employee, (float)($company->sbu ?? 470), $d['fecha_salida']);
    }
}
```

### 7.6 Rutas — en `routes/api.php`
```php
    // Fase 7 — Nómina
    Route::apiResource("employees", \App\Http\Controllers\EmployeeController::class)
        ->only(['index','store','update','destroy']);
    Route::get("payrolls", [\App\Http\Controllers\PayrollController::class, "index"]);
    Route::get("payrolls/{payroll}", [\App\Http\Controllers\PayrollController::class, "show"]);
    Route::post("payrolls/generate", [\App\Http\Controllers\PayrollController::class, "generate"]);
    Route::post("payrolls/{payroll}/close", [\App\Http\Controllers\PayrollController::class, "close"]);
    Route::post("employees/{employee}/liquidacion", [\App\Http\Controllers\PayrollController::class, "liquidacion"]);
```
```bash
php artisan migrate
```

## B. FRONTEND

### 7.7 `src/views/Employees.vue`
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ activo: true, fondos_reserva: false, sueldo: 470 })
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/employees?company_id=' + company.activeId)).data
  loading.value = false
}
function nuevo() { form.value = { activo: true, fondos_reserva: false, sueldo: 470 }; dialog.value = true }
function editar(r: any) {
  form.value = { ...r, fecha_ingreso: r.fecha_ingreso ? new Date(r.fecha_ingreso) : null }
  dialog.value = true
}
async function guardar() {
  const payload = {
    ...form.value,
    company_id: company.activeId,
    fecha_ingreso: form.value.fecha_ingreso instanceof Date
      ? form.value.fecha_ingreso.toISOString().slice(0, 10)
      : form.value.fecha_ingreso,
  }
  if (form.value.id) await api.put('/employees/' + form.value.id, payload)
  else await api.post('/employees', payload)
  dialog.value = false; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Empleados</h2>
      <Button label="Nuevo empleado" icon="pi pi-plus" @click="nuevo" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="cedula" header="Cédula" />
      <Column field="nombres" header="Nombres" />
      <Column field="cargo" header="Cargo" />
      <Column header="Ingreso"><template #body="{ data }">{{ String(data.fecha_ingreso).slice(0,10) }}</template></Column>
      <Column header="Sueldo"><template #body="{ data }">{{ money(data.sueldo) }}</template></Column>
      <Column header="F. reserva"><template #body="{ data }">
        <Tag :value="data.fondos_reserva ? 'Sí' : 'No'" :severity="data.fondos_reserva ? 'success':'secondary'" /></template></Column>
      <Column header=""><template #body="{ data }">
        <Button icon="pi pi-pencil" text @click="editar(data)" /></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Empleado" style="width:460px">
      <div style="display:flex; flex-direction:column; gap:12px;">
        <label style="display:flex; flex-direction:column; gap:4px;">Cédula *<InputText v-model="form.cedula" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Nombres *<InputText v-model="form.nombres" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Cargo<InputText v-model="form.cargo" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Fecha de ingreso *
          <DatePicker v-model="form.fecha_ingreso" dateFormat="yy-mm-dd" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Sueldo *
          <InputNumber v-model="form.sueldo" mode="currency" currency="USD" fluid /></label>
        <label style="display:flex; align-items:center; gap:8px;">
          <Checkbox v-model="form.fondos_reserva" :binary="true" /> Recibe fondos de reserva (más de 1 año)
        </label>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog=false" />
        <Button label="Guardar" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
```

### 7.8 `src/views/Payroll.vue`
```vue
<script setup lang="ts">
import { ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rol = ref<any>(null)
const anio = ref(new Date().getFullYear())
const mes = ref(new Date().getMonth() + 1)
const cargando = ref(false)
const msg = ref<any>(null)
const meses = [
  { label: 'Enero', value: 1 }, { label: 'Febrero', value: 2 }, { label: 'Marzo', value: 3 },
  { label: 'Abril', value: 4 }, { label: 'Mayo', value: 5 }, { label: 'Junio', value: 6 },
  { label: 'Julio', value: 7 }, { label: 'Agosto', value: 8 }, { label: 'Septiembre', value: 9 },
  { label: 'Octubre', value: 10 }, { label: 'Noviembre', value: 11 }, { label: 'Diciembre', value: 12 },
]
const money = (n: any) => '$' + Number(n).toFixed(2)

async function generar() {
  cargando.value = true; msg.value = null
  try {
    rol.value = (await api.post('/payrolls/generate', {
      company_id: company.activeId, anio: anio.value, mes: mes.value,
    })).data
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.message ?? 'No se pudo generar.' }
  } finally { cargando.value = false }
}
async function cerrar() {
  const res = await api.post('/payrolls/' + rol.value.id + '/close')
  msg.value = { type: 'success', text: res.data.mensaje }
  generar()
}
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Rol de pagos</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">
      El rol descuenta el 9.45% de IESS al empleado. Las provisiones (patronal 11.15%, décimos,
      fondos, vacaciones) son costo de la empresa y no se le descuentan.
    </p>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>

    <div style="display:flex; gap:10px; align-items:flex-end; margin-bottom:16px; background:#fff; border:1px solid #e2e5ea; border-radius:10px; padding:14px;">
      <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">Mes
        <Select v-model="mes" :options="meses" optionLabel="label" optionValue="value" /></label>
      <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">Año
        <InputNumber v-model="anio" :useGrouping="false" /></label>
      <Button label="Generar rol" icon="pi pi-cog" :loading="cargando" @click="generar" />
      <Button v-if="rol && rol.estado === 'abierto'" label="Cerrar y contabilizar"
              icon="pi pi-check" severity="secondary" @click="cerrar" />
      <Tag v-if="rol" :value="rol.estado" :severity="rol.estado === 'cerrado' ? 'success' : 'warn'" />
    </div>

    <div v-if="rol" style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#94a3b8;">INGRESOS</div><b>{{ money(rol.total_ingresos) }}</b></div>
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#d93025;">EGRESOS</div><b>{{ money(rol.total_egresos) }}</b></div>
      <div style="border:2px solid #2c3e50; border-radius:8px; padding:10px; background:#f8fafc;">
        <div style="font-size:11px; color:#94a3b8;">NETO A PAGAR</div><b>{{ money(rol.total_neto) }}</b></div>
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#94a3b8;">PROVISIONES</div><b>{{ money(rol.total_provisiones) }}</b></div>
    </div>

    <DataTable v-if="rol" :value="rol.lines" size="small" stripedRows scrollable>
      <Column header="Empleado"><template #body="{ data }">{{ data.employee?.nombres }}</template></Column>
      <Column header="Sueldo"><template #body="{ data }">{{ money(data.sueldo) }}</template></Column>
      <Column header="H. extra"><template #body="{ data }">{{ money(data.horas_extra) }}</template></Column>
      <Column header="IESS 9.45%"><template #body="{ data }">
        <span style="color:#d93025;">-{{ money(data.aporte_personal) }}</span></template></Column>
      <Column header="Neto"><template #body="{ data }"><b>{{ money(data.neto) }}</b></template></Column>
      <Column header="Patronal"><template #body="{ data }">{{ money(data.aporte_patronal) }}</template></Column>
      <Column header="Déc. 13"><template #body="{ data }">{{ money(data.decimo_tercero) }}</template></Column>
      <Column header="Déc. 14"><template #body="{ data }">{{ money(data.decimo_cuarto) }}</template></Column>
      <Column header="F. reserva"><template #body="{ data }">{{ money(data.fondos_reserva) }}</template></Column>
      <Column header="Vacac."><template #body="{ data }">{{ money(data.vacaciones) }}</template></Column>
    </DataTable>
  </div>
</template>
```

### 7.9 Conectar en `src/modules.ts` (grupo nuevo, antes de Administración)
```ts
{
  label: 'Nómina',
  items: [
    { key: 'employees', label: 'Empleados', icon: 'pi pi-id-card', component: 'Employees' },
    { key: 'payroll', label: 'Rol de pagos', icon: 'pi pi-wallet', component: 'Payroll' },
  ],
},
```
Y en `MainLayout.vue`: importá `Employees` y `Payroll`, agregalos al `componentMap`.

## Probar
Empleado con sueldo **$600**, sin fondos de reserva:
- IESS personal: **$56.70** (600 × 9.45%) → neto **$543.30**
- Patronal: **$66.90** · Décimo 13: **$50.00** · Décimo 14: **$39.17** (SBU 470/12)
- Vacaciones: **$25.02**

Cerrá el rol → mirá el asiento en el Libro diario → **los estados financieros deben seguir cuadrando**.

---
---

# FASE 8 — Planes y control de features (así vende KVS)

> **La jugada de negocio del creador.** En el video lo dijo textual:
> *"Tenemos todos los módulos desarrollados, sin embargo es un tema de saber si usted es
> obligado a llevar contabilidad o no... para decirle cuáles son los módulos que necesitaría adquirir."*
>
> **Un solo código. Todos los módulos construidos. Vendés desbloqueando.**
> No mantenés 3 sistemas — mantenés uno con flags por empresa.
>
> Sus planes (del video):
> | Plan | Precio | Incluye |
> |------|--------|---------|
> | Básico | $289/año | Inventario, ventas, compras, reportes, facturación SRI. **Sin firma.** |
> | PRO | más caro | + **series/lotes** + conciliación + **firma electrónica incluida 1 año** |
> | Corporativo | más caro | + contabilidad + nómina |
>
> 👉 **La firma incluida es su gancho de venta.** El cliente se olvida de renovarla.

## A. BACKEND

### 8.1 Definición de planes — `config/planes.php`
```php
<?php
return [
    // Básico: lo que él vende a $289. Incluye facturación electrónica e importar del SRI
    // (él lo demostró como parte de "hacer compras"). NO incluye que vos pongas el certificado.
    'basico' => [
        'nombre' => 'Básico',
        'precio_anual' => 289,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote',
        ],
    ],
    // PRO: los upsells que él nombró — series/lotes y conciliación. Más la firma gestionada.
    'pro' => [
        'nombre' => 'PRO',
        'precio_anual' => 489,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote', 'series', 'bancos', 'conciliacion', 'cartera', 'firma_incluida',
        ],
    ],
    // Corporativo: obligado a llevar contabilidad y/o hace nómina.
    'corporativo' => [
        'nombre' => 'Corporativo',
        'precio_anual' => 890,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote', 'series', 'bancos', 'conciliacion', 'cartera', 'firma_incluida',
            'contabilidad', 'nomina', 'usuarios', 'auditoria',
        ],
    ],
];
```

> **Ojo con `firma_incluida`:** NO es una feature de software — es **comercial**. Significa
> "nosotros le ponemos el certificado y se lo renovamos". La **pantalla** de configuración de
> firma está en TODOS los planes (usa `facturacion_sri`); en el básico el cliente sube su
> propio `.p12`. Usá esta bandera solo para saber a quién le tenés que gestionar el certificado.

```php
// (fin de config/planes.php)
```

### 8.2 Migración — `database/migrations/2026_07_28_000001_add_plan_to_companies.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('plan')->default('corporativo');   // tus demos arrancan con todo
            $t->date('plan_vence')->nullable();
        });
    }
    public function down(): void {
        Schema::table('companies', fn(Blueprint $t) => $t->dropColumn(['plan', 'plan_vence']));
    }
};
```
> Agregá `'plan', 'plan_vence',` al `$fillable` de `Company`.

### 8.3 En `app/Models/Company.php` — agregá estos métodos:
```php
    /** Features del plan contratado. */
    public function features(): array {
        return config('planes.'.$this->plan.'.features', []);
    }
    public function tieneFeature(string $feature): bool {
        if ($this->planVencido()) return false;
        return in_array($feature, $this->features(), true);
    }
    public function planVencido(): bool {
        return $this->plan_vence !== null && $this->plan_vence->isPast();
    }
```
Y al `$casts`: `'plan_vence' => 'date',`

### 8.4 Middleware — `app/Http/Middleware/EnsureFeature.php`
```php
<?php
namespace App\Http\Middleware;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

class EnsureFeature {
    public function handle(Request $request, Closure $next, string $feature) {
        $companyId = $request->input('company_id') ?? $request->query('company_id');
        $company = $companyId ? Company::find($companyId) : null;
        if (! $company) return $next($request); // sin empresa, deja pasar (lo valida el controlador)

        if ($company->planVencido()) {
            abort(402, 'El plan de esta empresa venció. Renová para seguir usando el sistema.');
        }
        if (! $company->tieneFeature($feature)) {
            abort(402, "Tu plan {$company->plan} no incluye este módulo. Actualizá el plan para activarlo.");
        }
        return $next($request);
    }
}
```
> El **402 Payment Required** es el código correcto acá: no es que no tenga permiso (403),
> es que no lo pagó.

En `bootstrap/app.php`, junto al alias de `rol`:
```php
    $middleware->alias([
        'rol' => \App\Http\Middleware\EnsureRole::class,
        'feature' => \App\Http\Middleware\EnsureFeature::class,
    ]);
```

### 8.5 Endpoint del plan — agregá a `app/Http/Controllers/CompanyController.php`
```php
    // El frontend pregunta qué módulos mostrar
    public function plan(Company $company) {
        return [
            'plan' => $company->plan,
            'nombre' => config('planes.'.$company->plan.'.nombre'),
            'precio_anual' => config('planes.'.$company->plan.'.precio_anual'),
            'vence' => optional($company->plan_vence)->format('Y-m-d'),
            'vencido' => $company->planVencido(),
            'features' => $company->features(),
        ];
    }
    // Cambiar de plan (para cuando le vendés el upgrade)
    public function cambiarPlan(\Illuminate\Http\Request $r, Company $company) {
        $d = $r->validate([
            'plan' => ['required', 'in:basico,pro,corporativo'],
            'plan_vence' => ['nullable', 'date'],
        ]);
        $company->update($d);
        return $this->plan($company);
    }
```

### 8.6 Proteger las rutas — en `routes/api.php`
Envolvé cada grupo con su feature:
```php
    // Series: solo PRO y Corporativo
    Route::middleware('feature:series')->group(function () {
        Route::get("series", [\App\Http\Controllers\SerieController::class, "index"]);
        Route::post("series", [\App\Http\Controllers\SerieController::class, "store"]);
        Route::get("series/lookup", [\App\Http\Controllers\SerieController::class, "lookup"]);
        Route::get("series/trace", [\App\Http\Controllers\SerieController::class, "trace"]);
    });

    // Conciliación: solo PRO y Corporativo
    Route::middleware('feature:conciliacion')->group(function () {
        Route::get("bank-movements", [\App\Http\Controllers\BankMovementController::class, "index"]);
        Route::post("bank-movements", [\App\Http\Controllers\BankMovementController::class, "store"]);
        Route::post("bank-movements/{movement}/toggle", [\App\Http\Controllers\BankMovementController::class, "toggle"]);
        Route::post("bank-movements/auto-match", [\App\Http\Controllers\BankMovementController::class, "autoMatch"]);
    });

    // Contabilidad: solo Corporativo
    Route::middleware('feature:contabilidad')->group(function () {
        Route::get("journal", [\App\Http\Controllers\AccountingController::class, "journal"]);
        Route::post("journal/mayorizar", [\App\Http\Controllers\AccountingController::class, "mayorizar"]);
        Route::post("journal/{entry}/desmayorizar", [\App\Http\Controllers\AccountingController::class, "desmayorizar"]);
        Route::get("ledger", [\App\Http\Controllers\AccountingController::class, "ledger"]);
        Route::get("income-statement", [\App\Http\Controllers\AccountingController::class, "incomeStatement"]);
        Route::get("balance-sheet", [\App\Http\Controllers\AccountingController::class, "balanceSheet"]);
    });

    // Nómina: solo Corporativo
    Route::middleware('feature:nomina')->group(function () {
        Route::apiResource("employees", \App\Http\Controllers\EmployeeController::class)
            ->only(['index','store','update','destroy']);
        Route::get("payrolls", [\App\Http\Controllers\PayrollController::class, "index"]);
        Route::get("payrolls/{payroll}", [\App\Http\Controllers\PayrollController::class, "show"]);
        Route::post("payrolls/generate", [\App\Http\Controllers\PayrollController::class, "generate"]);
        Route::post("payrolls/{payroll}/close", [\App\Http\Controllers\PayrollController::class, "close"]);
    });

    // Importación en lote: PRO y Corporativo
    Route::middleware('feature:import_lote')->group(function () {
        Route::get("pending-imports", [\App\Http\Controllers\PendingImportController::class, "index"]);
        Route::post("pending-imports/upload-txt", [\App\Http\Controllers\PendingImportController::class, "uploadTxt"]);
        Route::post("pending-imports/process", [\App\Http\Controllers\PendingImportController::class, "process"]);
    });

    // El plan siempre se puede consultar
    Route::get("companies/{company}/plan", [\App\Http\Controllers\CompanyController::class, "plan"]);
    Route::post("companies/{company}/plan", [\App\Http\Controllers\CompanyController::class, "cambiarPlan"]);
```

> ⚠️ **El asiento contable NO se bloquea nunca.** Aunque el cliente no tenga el módulo de
> contabilidad, los asientos se siguen generando en silencio. El día que te compra el upgrade,
> abre Contabilidad y **ya tiene toda su historia ahí**. Ese es el mejor argumento de venta
> que vas a tener: no le vendés un módulo vacío, le mostrás su propia contabilidad ya hecha.

```bash
php artisan migrate
```

## B. FRONTEND

### 8.7 Store del plan — `src/stores/plan.ts`
```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../lib/api'

export const usePlanStore = defineStore('plan', () => {
  const features = ref<string[]>([])
  const plan = ref('')
  const nombre = ref('')
  const vence = ref<string | null>(null)
  const vencido = ref(false)

  async function load(companyId: number) {
    const { data } = await api.get('/companies/' + companyId + '/plan')
    plan.value = data.plan
    nombre.value = data.nombre
    features.value = data.features
    vence.value = data.vence
    vencido.value = data.vencido
  }
  function tiene(feature?: string) {
    if (!feature) return true          // módulos sin feature = siempre visibles
    return features.value.includes(feature)
  }
  return { plan, nombre, features, vence, vencido, load, tiene }
})
```

### 8.8 Marcar cada módulo con su feature — en `src/modules.ts`
Agregá `feature` a cada item. Ejemplo del bloque completo:
```ts
import type { WorkTab } from './stores/tabs'

// Cada módulo declara qué feature del plan lo habilita. Sin feature = siempre visible.
export type ModuleItem = WorkTab & { feature?: string }

export const modules: { label: string; items: ModuleItem[] }[] = [
  {
    label: 'Catálogo',
    items: [
      { key: 'contacts', label: 'Contactos', icon: 'pi pi-users', component: 'Contacts', feature: 'catalogo' },
      { key: 'products', label: 'Productos y servicios', icon: 'pi pi-box', component: 'Products', feature: 'catalogo' },
      { key: 'accounts', label: 'Plan de cuentas', icon: 'pi pi-sitemap', component: 'Accounts', feature: 'contabilidad' },
    ],
  },
  {
    label: 'Ventas',
    items: [
      { key: 'pos', label: 'Punto de Venta', icon: 'pi pi-shopping-cart', component: 'Pos', feature: 'ventas' },
      { key: 'invoices', label: 'Facturas', icon: 'pi pi-file', component: 'Invoices', feature: 'ventas' },
      { key: 'quotes', label: 'Cotizaciones', icon: 'pi pi-file-edit', component: 'Quotes', feature: 'ventas' },
      { key: 'advances', label: 'Anticipos', icon: 'pi pi-arrow-down-left', component: 'Advances', feature: 'cartera' },
      { key: 'credit-notes', label: 'Notas de crédito', icon: 'pi pi-file-excel', component: 'CreditNotes', feature: 'cartera' },
      { key: 'receivables', label: 'Cuentas por cobrar', icon: 'pi pi-wallet', component: 'Receivables', feature: 'cartera' },
      { key: 'sales-ret', label: 'Retenciones recibidas', icon: 'pi pi-percentage', component: 'Withholdings', feature: 'ventas' },
      { key: 'documents', label: 'Documentos SRI', icon: 'pi pi-check-square', component: 'SriDocuments', feature: 'facturacion_sri' },
    ],
  },
  {
    label: 'Compras',
    items: [
      { key: 'purchases', label: 'Compras', icon: 'pi pi-shopping-bag', component: 'Purchases', feature: 'compras' },
      { key: 'batch-import', label: 'Importar del SRI (lote)', icon: 'pi pi-cloud-download', component: 'BatchImport', feature: 'import_lote' },
      { key: 'suppliers', label: 'Proveedores', icon: 'pi pi-truck', component: 'Suppliers', feature: 'compras' },
      { key: 'payables', label: 'Cuentas por pagar', icon: 'pi pi-credit-card', component: 'Payables', feature: 'cartera' },
    ],
  },
  {
    label: 'Inventario',
    items: [
      { key: 'inventory', label: 'Inventario y kardex', icon: 'pi pi-database', component: 'Inventory', feature: 'inventario' },
      { key: 'series', label: 'Garantías por serie', icon: 'pi pi-qrcode', component: 'Series', feature: 'series' },
    ],
  },
  {
    label: 'Caja y Bancos',
    items: [
      { key: 'cash', label: 'Caja', icon: 'pi pi-money-bill', component: 'Cash', feature: 'bancos' },
      { key: 'banks', label: 'Bancos', icon: 'pi pi-building-columns', component: 'Banks', feature: 'bancos' },
      { key: 'reconciliation', label: 'Conciliación bancaria', icon: 'pi pi-sync', component: 'Reconciliation', feature: 'conciliacion' },
    ],
  },
  {
    label: 'Contabilidad',
    items: [
      { key: 'journal', label: 'Libro diario', icon: 'pi pi-book', component: 'Accounting', feature: 'contabilidad' },
      { key: 'ledger', label: 'Libro mayor', icon: 'pi pi-list', component: 'Ledger', feature: 'contabilidad' },
      { key: 'statements', label: 'Estados financieros', icon: 'pi pi-chart-line', component: 'Accounting', feature: 'contabilidad' },
    ],
  },
  {
    label: 'Nómina',
    items: [
      { key: 'employees', label: 'Empleados', icon: 'pi pi-id-card', component: 'Employees', feature: 'nomina' },
      { key: 'payroll', label: 'Rol de pagos', icon: 'pi pi-wallet', component: 'Payroll', feature: 'nomina' },
    ],
  },
  {
    label: 'Administración',
    items: [
      { key: 'signature', label: 'Firma electrónica', icon: 'pi pi-shield', component: 'SignatureConfig', feature: 'facturacion_sri' },
      { key: 'companies', label: 'Empresas', icon: 'pi pi-building', component: 'Companies' },
      { key: 'emission', label: 'Puntos de emisión', icon: 'pi pi-hashtag', component: 'EmissionPoints', feature: 'facturacion_sri' },
      { key: 'users', label: 'Usuarios y roles', icon: 'pi pi-shield', component: 'Users', feature: 'usuarios' },
      { key: 'audit', label: 'Auditoría', icon: 'pi pi-history', component: 'Audit', feature: 'auditoria' },
      { key: 'reports', label: 'Reportes', icon: 'pi pi-chart-bar', component: 'Accounting', feature: 'reportes' },
    ],
  },
]

/** Solo los módulos que el plan contratado habilita. */
export function modulesPara(tiene: (f?: string) => boolean) {
  return modules
    .map((g) => ({ ...g, items: g.items.filter((i) => tiene(i.feature)) }))
    .filter((g) => g.items.length > 0)
}
```

### 8.9 Filtrar el menú — en `src/layouts/MainLayout.vue`
```ts
import { modulesPara } from '../modules'
import { usePlanStore } from '../stores/plan'

const plan = usePlanStore()

// El menú solo muestra lo que el plan habilita
const modulosVisibles = computed(() => modulesPara(plan.tiene))

const menuModel = computed(() =>
  modulosVisibles.value.map((group) => ({
    label: group.label,
    items: group.items.map((it) => ({ label: it.label, icon: it.icon, command: () => tabs.open(it) })),
  })),
)
```
En `onMounted`, después de `await company.load()`:
```ts
  if (company.activeId) await plan.load(company.activeId)
```

### 8.10 Mostrar el plan contratado en el topbar — en `src/layouts/MainLayout.vue`
> No hay pantalla de inicio (el cliente no la quiere): el menú lateral es el único lanzador,
> así que el filtro del punto 8.9 ya alcanza. Esto es solo para que se vea qué plan tiene.

En el template, dentro de `.topbar-right`, antes del nombre del usuario:
```html
<Tag v-if="plan.nombre" :value="'Plan ' + plan.nombre" severity="warn" />
<Tag v-if="plan.vencido" value="Plan vencido" severity="danger" />
```
(importá `Tag from 'primevue/tag'`)

### 8.11 Mostrar el error de plan — en `src/lib/api.ts`
En el interceptor de respuesta, junto al manejo del 401:
```ts
    if (error.response?.status === 402) {
      alert(error.response.data.message ?? 'Tu plan no incluye este módulo.')
    }
```

## Probar
1. Poné una empresa en plan `basico` (por tinker: `Company::first()->update(['plan'=>'basico'])`).
2. Recargá → **desaparecen del menú lateral**: series, conciliación, contabilidad, nómina.
3. Pegale a `/api/series` con esa empresa → responde **402** con el mensaje de upgrade.
4. Cambiala a `corporativo` → vuelve a aparecer todo.
5. Poné `plan_vence` en una fecha pasada → todo bloqueado con "plan vencido".

## 💰 Tu estrategia de precios (mi recomendación)

Él cobra **$289 básico** y mete la **firma incluida** en el PRO. Vos tenés 2 ventajas reales
sobre KVS y te las estás regalando si cobrás igual:

1. **Stack moderno** — KVS es Java viejo (Google Web Toolkit, JSP, jQuery). Vos: Vue 3 + Laravel.
   Eso no se lo decís al cliente (no le importa), pero significa que **vos entregás features
   en días y ellos en meses**.
2. **Los asientos ya están hechos** aunque no compre contabilidad. Cuando le vendas el upgrade,
   le abrís el módulo y **ya tiene su historia contable completa**. Ellos no pueden hacer eso
   sin volver a procesar.

**Mi consejo:** no arranques peleando por precio. Arrancá **igualando** ($289 básico) y ganá
por servicio (instalación el mismo día, la firma gestionada, y features que pedís y salen esa
semana). Cuando tengas 10 clientes contentos, subís.

---
---

# ✅ Checklist final

- [ ] Fase 0 — `.env` arreglado, `php artisan serve` corriendo
- [ ] Fase 1 — Firma cargada, dice "vence 20XX-XX-XX"
- [ ] Fase 2 — Series: buscás una serie y te dice a quién compraste y a quién vendiste
- [ ] Fase 3 — Cajero NO puede facturar con el punto de otro · Auditoría muestra quién hizo qué
- [ ] Fase 4 — Anticipo de $200 se cruza contra factura de $500 → queda $300
- [ ] Fase 5 — TXT del SRI → trae los XML → registra las compras
- [ ] Fase 6 — Combo descarga componentes · CSV del banco auto-concilia
- [ ] Fase 7 — Sueldo $600 → IESS $56.70 → neto $543.30
- [ ] Fase 8 — Plan `basico` esconde series/contabilidad/nómina · `/api/series` responde 402

**Tu semáforo en cada fase:** si los estados financieros dejan de cuadrar, el asiento quedó mal.

---

## Los 2 conceptos que no se te pueden olvidar

**1. Emitir vs importar**
> El **.p12 firma lo que SALE** (tus facturas). Lo que **ENTRA viene del XML** del proveedor
> (te llega por correo o lo bajás del portal SRI con RUC+clave).
> **El .p12 NUNCA se usa para importar.**

**2. Nómina**
> El **9.45%** se le descuenta al empleado. El **11.15% + décimos + fondos + vacaciones**
> los paga la empresa y **NO van en el rol** — son provisiones.

> # ⛔ ARCHIVO SUPERADO — NO PEGUES DESDE ACÁ
> Todo esto está actualizado y corregido en **[FASES_COMPLETAS.md](FASES_COMPLETAS.md)**.
> Este archivo quedó con numeración vieja y datos sin corregir. Podés borrarlo.

# FASE 10 — Notas de crédito, anticipos, abonos y uso de saldos

> Lo que más recalcó la contadora de cartera. Conceptos primero:
> - **Nota de crédito SRI**: comprobante electrónico (devolución de mercadería). Va al SRI.
> - **Nota de crédito INTERNA**: NO va al SRI. Da de baja una deuda por cruce de cuentas.
> - **Anticipo**: plata que te dan ANTES de facturar. Queda con saldo a favor del cliente.
> - **Abono**: pago aplicado a UNA factura puntual (ya lo tenés en Cuentas por cobrar).
> - **Uso de saldos**: cruzar un anticipo o una nota de crédito CONTRA una factura.
>
> Backend: `contabilidad-backend` · Frontend: `contabilidad-vue`

---

## A. BACKEND

### 1. Migración — `database/migrations/2026_07_24_000001_create_credit_notes_and_advances.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        // Notas de crédito (SRI o internas)
        Schema::create('credit_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->foreignId('invoice_id')->nullable()->constrained(); // factura que modifica
            $t->enum('tipo', ['sri', 'interna'])->default('interna');
            $t->string('numero')->nullable();
            $t->date('fecha');
            $t->string('motivo');
            $t->json('items')->nullable();
            $t->decimal('total_sin_impuestos', 12, 2)->default(0);
            $t->decimal('total_impuesto', 12, 2)->default(0);
            $t->decimal('importe_total', 12, 2);
            $t->decimal('saldo_disponible', 12, 2)->default(0); // lo que queda por cruzar
            $t->timestamps();
        });
        // Anticipos (plata recibida antes de facturar)
        Schema::create('advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_id')->constrained('contacts');
            $t->date('fecha');
            $t->decimal('monto', 12, 2);
            $t->decimal('saldo', 12, 2);           // lo que queda sin usar
            $t->string('forma_pago', 20);
            $t->foreignId('bank_id')->nullable()->constrained();
            $t->string('nota')->nullable();
            $t->timestamps();
        });
        // Aplicaciones: qué anticipo/nota se cruzó contra qué factura
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

### 2. Modelos

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

### 3. Controlador de anticipos — `app/Http/Controllers/AdvanceController.php`
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
            // Asiento: entra el dinero, nace un pasivo (le debés la mercadería al cliente)
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

### 4. Controlador de notas de crédito — `app/Http/Controllers/CreditNoteController.php`
```php
<?php
namespace App\Http\Controllers;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\DocumentCalculator;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditNoteController extends Controller {
    public function index(Request $r) {
        return CreditNote::with('contact:id,razon_social','invoice:id,numero')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest('fecha')->get();
    }
    // Nota INTERNA: no va al SRI. Da de baja deuda por cruce de cuentas.
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
            // Asiento: baja la venta (o nace un pasivo) contra lo que te deben
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

### 5. USO DE SALDOS (lo clave) — `app/Http/Controllers/CreditApplicationController.php`
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
    // Saldos disponibles de un cliente (anticipos + notas de crédito sin usar)
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
    // Cruzar un anticipo o nota CONTRA una factura
    public function apply(Request $r, Invoice $invoice) {
        $d = $r->validate([
            'tipo'=>['required','in:anticipo,nota'],
            'id'=>['required','integer'],
            'monto'=>['required','numeric','min:0.01'],
        ]);
        $origen = $d['tipo']==='anticipo'
            ? Advance::findOrFail($d['id'])
            : CreditNote::findOrFail($d['id']);
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
            // Asiento del cruce: se salda el anticipo/nota contra lo que te deben
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

### 6. PAGO MÚLTIPLE (varios proveedores a la vez) — agregá a `app/Http/Controllers/PayableController.php`
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
            // UN solo asiento por todo el lote
            $origen = match($d['forma_pago']) {
                'efectivo' => ['codigo'=>'1.1.01','nombre'=>'Caja','tipo'=>'activo'],
                'cruce'    => ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo'],
                default    => ['codigo'=>'1.1.02','nombre'=>'Bancos','tipo'=>'activo'],
            };
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

### 7. Rutas — en `routes/api.php` (dentro del grupo `auth:sanctum`)
```php
    // Fase 10 — Notas de crédito, anticipos y uso de saldos
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

---

## B. FRONTEND

### 1. `src/views/Advances.vue` — anticipos
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

### 2. `src/views/CreditNotes.vue` — notas de crédito
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

### 3. "Usar saldo" en Cuentas por cobrar — agregá a `src/views/Receivables.vue`

En el `<script setup>`:
```ts
const saldos = ref<any>({ saldos: [], total: 0 })
const usarDialog = ref<any>(null)

async function abrirUsarSaldo(r: any) {
  const inv = data.value.cartera.find((x: any) => x.id === r.id)
  const res = await api.get('/credits/available?company_id=' + company.activeId +
    '&contact_id=' + (inv.contact_id ?? r.contact_id))
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

En la columna de acciones (junto a "Registrar cobro"):
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
> Nota: en `ReceivableController@index`, agregá `'contact_id'=>$i->contact_id,` al `map()` para
> que el frontend sepa de qué cliente es cada factura.

### 4. Conectar en `src/modules.ts` (grupo Ventas)
```ts
{ key: 'advances', label: 'Anticipos', icon: 'pi pi-arrow-down-left', component: 'Advances' },
{ key: 'credit-notes', label: 'Notas de crédito', icon: 'pi pi-file-excel', component: 'CreditNotes' },
```
Y en `src/layouts/MainLayout.vue`: importá `Advances` y `CreditNotes` y agregalos al `componentMap`.

---

## Probar
1. Creá un anticipo de $200 a Juan Pérez.
2. Emití una factura a Juan por $500 a crédito.
3. En Cuentas por cobrar → "Usar saldo" → aplicá los $200 → el saldo baja a $300.
4. Mirá el Libro diario: debe estar el asiento del anticipo y el del cruce.
5. Revisá Estados financieros: **debe seguir cuadrando**.

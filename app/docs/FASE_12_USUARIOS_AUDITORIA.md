> # ⛔ ARCHIVO SUPERADO — NO PEGUES DESDE ACÁ
> Todo esto está actualizado y corregido en **[FASES_COMPLETAS.md](FASES_COMPLETAS.md)**.
> Este archivo quedó con numeración vieja y datos sin corregir. Podés borrarlo.

# FASE 12 — Usuarios, roles, restricción por punto de emisión y auditoría

> Lo que insistió la contadora:
> 1. **Restringir cada usuario a SU punto de emisión** — si el de la farmacia (001-902) puede
>    facturar con el punto de caja (001-901), le rompe la numeración a la otra caja. El SRI no
>    perdona secuencias rotas.
> 2. **Saber quién hizo cada factura** — auditoría de quién creó/modificó qué.
>
> Backend: `contabilidad-backend` · Frontend: `contabilidad-vue`

---

## A. BACKEND

### 1. Migración — `database/migrations/2026_07_26_000001_create_roles_and_audit.php`
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            // admin: todo · contador: contabilidad y reportes · cajero: solo vende en SU punto
            $t->string('rol')->default('admin');
            // Si tiene punto asignado, solo puede facturar con ese
            $t->foreignId('emission_point_id')->nullable()->constrained();
            $t->boolean('activo')->default(true);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('accion');        // creo | actualizo | elimino
            $t->string('modelo');        // Invoice, Purchase, ...
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

### 2. En `app/Models/User.php` — agregá al `$fillable`:
```php
'rol', 'emission_point_id', 'activo',
```
Y estos helpers dentro de la clase:
```php
    public function emissionPoint() { return $this->belongsTo(EmissionPoint::class); }
    public function esAdmin(): bool { return $this->rol === 'admin'; }
    public function puedeUsarPunto(?int $emissionPointId): bool {
        // Sin punto asignado = puede usar cualquiera (admin/contador)
        if (! $this->emission_point_id) return true;
        return $this->emission_point_id === $emissionPointId;
    }
```

### 3. Modelo — `app/Models/AuditLog.php`
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

### 4. Trait de auditoría — `app/Models/Concerns/Auditable.php`
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

### 5. Activar la auditoría — en cada modelo que quieras auditar:
```php
use App\Models\Concerns\Auditable;

class Invoice extends Model {
    use Auditable;   // ← agregá esta línea
    // ...resto igual
}
```
> Recomendado en: `Invoice`, `Purchase`, `JournalEntry`, `Product`, `Contact`, `Payroll`.

### 6. Restringir el punto de emisión al facturar — en `app/Http/Controllers/InvoiceController.php`

Al inicio de `store()`, antes de emitir:
```php
        // La contadora insistió: cada usuario factura SOLO con su punto de emisión
        $user = $request->user();
        if (! $user->puedeUsarPunto($request->input('emission_point_id'))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'emission_point_id' => ['No podés facturar con un punto de emisión que no es el tuyo.'],
            ]);
        }
```

### 7. Middleware de rol — `app/Http/Middleware/EnsureRole.php`
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
Registralo en `bootstrap/app.php`, dentro de `->withMiddleware(function (Middleware $middleware) {`:
```php
    $middleware->alias(['rol' => \App\Http\Middleware\EnsureRole::class]);
```

### 8. Controlador de usuarios — `app/Http/Controllers/UserController.php`
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

### 9. Controlador de auditoría — `app/Http/Controllers/AuditController.php`
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

### 10. Rutas — en `routes/api.php`
```php
    // Fase 12 — Usuarios y auditoría (solo admin)
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

---

## B. FRONTEND

### 1. `src/views/Users.vue`
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
          {{ form.id ? 'Contraseña (dejala vacía para no cambiarla)' : 'Contraseña *' }}
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

### 2. `src/views/Audit.vue`
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

### 3. Conectar en `src/modules.ts` (grupo Administración)
```ts
{ key: 'users', label: 'Usuarios y roles', icon: 'pi pi-shield', component: 'Users' },
{ key: 'audit', label: 'Auditoría', icon: 'pi pi-history', component: 'Audit' },
```
Y en `MainLayout.vue`: importá `Users` y `Audit`, agregalos al `componentMap`.

---

## Probar
1. Creá 2 puntos de emisión: `001-901 Caja` y `001-902 Farmacia`.
2. Creá un usuario cajero asignado a `001-901`.
3. Entrá con ese usuario e intentá facturar con el punto `001-902` → **debe rechazarlo**.
4. Emití una factura y andá a Auditoría → tiene que aparecer quién la hizo, cuándo y desde qué IP.

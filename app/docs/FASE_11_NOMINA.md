> # ⛔ ARCHIVO SUPERADO — NO PEGUES DESDE ACÁ
> Todo esto está actualizado y corregido en **[FASES_COMPLETAS.md](FASES_COMPLETAS.md)**.
> Este archivo quedó con numeración vieja y datos sin corregir. Podés borrarlo.

# FASE 11 — Nómina (roles de pago, IESS, provisiones, liquidaciones)

> Conceptos primero (Ecuador), porque acá se equivoca todo el mundo:
>
> **El rol de pago del empleado:**
> - **Ingresos**: sueldo + horas extra + comisiones/bonos
> - **Egresos**: aporte personal IESS **9.45%**, préstamos, anticipos
> - **Neto a pagar** = ingresos − egresos
>
> **Las provisiones NO van en el rol** — son costo de la empresa, aparte:
> - Aporte patronal IESS **11.15%**
> - Décimo tercero: **8.33%** (sueldo/12) — se paga en diciembre
> - Décimo cuarto: **SBU/12** — se paga en agosto/marzo según región
> - Fondos de reserva: **8.33%** (solo después del primer año)
> - Vacaciones: **4.17%** (sueldo/24)
>
> ⚠️ El SBU (Salario Básico Unificado) cambia cada año — por eso va en configuración, no fijo.
>
> Backend: `contabilidad-backend` · Frontend: `contabilidad-vue`

---

## A. BACKEND

### 1. Migración — `database/migrations/2026_07_25_000001_create_payroll_tables.php`
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
            $t->boolean('fondos_reserva')->default(false); // true después del primer año
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
            // Ingresos
            $t->decimal('sueldo', 12, 2)->default(0);
            $t->decimal('horas_extra', 12, 2)->default(0);
            $t->decimal('comisiones', 12, 2)->default(0);
            // Egresos
            $t->decimal('aporte_personal', 12, 2)->default(0); // 9.45%
            $t->decimal('prestamos', 12, 2)->default(0);
            $t->decimal('anticipos', 12, 2)->default(0);
            $t->decimal('neto', 12, 2)->default(0);
            // Provisiones (costo empresa)
            $t->decimal('aporte_patronal', 12, 2)->default(0); // 11.15%
            $t->decimal('decimo_tercero', 12, 2)->default(0);
            $t->decimal('decimo_cuarto', 12, 2)->default(0);
            $t->decimal('fondos_reserva', 12, 2)->default(0);
            $t->decimal('vacaciones', 12, 2)->default(0);
            $t->timestamps();
        });
        // El SBU cambia cada año → configurable por empresa
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
> Agregá `'sbu'` al `$fillable` de `app/Models/Company.php`.

### 2. Modelos

`app/Models/Employee.php`
```php
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

`app/Models/Payroll.php`
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payroll extends Model {
    protected $fillable = ['company_id','anio','mes','total_ingresos','total_egresos',
        'total_neto','total_provisiones','estado'];
    public function lines() { return $this->hasMany(PayrollLine::class); }
}
```

`app/Models/PayrollLine.php`
```php
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

### 3. Servicio de cálculo — `app/Services/PayrollCalculator.php`
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
        // El IESS se calcula sobre TODO lo que es materia gravada, no solo el sueldo
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
            // Provisiones (costo empresa, NO se descuentan al empleado)
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
        $inicioDecimoTercero = \Carbon\Carbon::create($salida->month >= 12 ? $salida->year : $salida->year - 1, 12, 1);
        $mesesD3 = max(0, $inicioDecimoTercero->diffInMonths($salida));
        // Décimo cuarto: proporcional desde el 1-ago
        $inicioDecimoCuarto = \Carbon\Carbon::create($salida->month >= 8 ? $salida->year : $salida->year - 1, 8, 1);
        $mesesD4 = max(0, $inicioDecimoCuarto->diffInMonths($salida));
        // Vacaciones no gozadas: proporcional al último año
        $mesesVac = max(0, min(12, $ingreso->diffInMonths($salida) % 12));

        return [
            'dias_trabajados'      => (int)$ingreso->diffInDays($salida),
            'decimo_tercero_prop'  => round($sueldo / 12 * $mesesD3, 2),
            'decimo_cuarto_prop'   => round($sbu / 12 * $mesesD4, 2),
            'vacaciones_prop'      => round($sueldo / 24 * $mesesVac, 2),
            'total' => round($sueldo / 12 * $mesesD3 + $sbu / 12 * $mesesD4 + $sueldo / 24 * $mesesVac, 2),
        ];
    }
}
```

### 4. Controladores

`app/Http/Controllers/EmployeeController.php`
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
        $employee->update($this->validated($r, $employee->id));
        return $employee;
    }
    public function destroy(Employee $employee) { $employee->delete(); return response()->noContent(); }

    private function validated(Request $r, ?int $id = null): array {
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

`app/Http/Controllers/PayrollController.php`
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
            'extras'=>['sometimes','array'], // extras[employee_id] = {horas_extra, comisiones, prestamos, anticipos}
        ]);
        $company = Company::findOrFail($d['company_id']);
        $sbu = (float)($company->sbu ?? 470);

        return DB::transaction(function() use ($d, $company, $sbu, $calc) {
            $payroll = Payroll::updateOrCreate(
                ['company_id'=>$company->id, 'anio'=>$d['anio'], 'mes'=>$d['mes']],
                ['estado'=>'abierto']
            );
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
    // Cierra el rol y genera el asiento contable
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

            SimpleEntry::make($payroll->company_id,
                "Rol de pagos {$payroll->mes}/{$payroll->anio}", [
                // Gastos de la empresa
                ['codigo'=>'5.2.01','nombre'=>'Sueldos y salarios','tipo'=>'gasto',
                 'debe'=>$sueldos,'haber'=>0,'ref'=>'ROL'],
                ['codigo'=>'5.2.02','nombre'=>'Aporte patronal IESS','tipo'=>'gasto',
                 'debe'=>$patronal,'haber'=>0,'ref'=>'ROL'],
                ['codigo'=>'5.2.03','nombre'=>'Beneficios sociales','tipo'=>'gasto',
                 'debe'=>$provisiones,'haber'=>0,'ref'=>'ROL'],
                // Lo que la empresa debe
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
            return ['ok'=>true,'mensaje'=>'Rol cerrado y contabilizado.'];
        });
    }
    // Liquidación / finiquito
    public function liquidacion(Request $r, Employee $employee, PayrollCalculator $calc) {
        $d = $r->validate(['fecha_salida'=>['required','date']]);
        $company = Company::findOrFail($employee->company_id);
        return $calc->liquidacion($employee, (float)($company->sbu ?? 470), $d['fecha_salida']);
    }
}
```

### 5. Rutas — en `routes/api.php`
```php
    // Fase 11 — Nómina
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

---

## B. FRONTEND

### 1. `src/views/Employees.vue`
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

### 2. `src/views/Payroll.vue` — rol de pagos
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
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

### 3. Conectar en `src/modules.ts` (nuevo grupo antes de Administración)
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

---

## Probar
1. Creá un empleado: sueldo $600, sin fondos de reserva.
2. Generá el rol del mes → el IESS personal debe dar **$56.70** (600 × 9.45%) y el neto **$543.30**.
3. Las provisiones: patronal **$66.90**, décimo 13 **$50.00**, décimo 14 **$39.17** (SBU 470/12).
4. Cerrá el rol → mirá el asiento en el Libro diario.
5. Estados financieros: **debe seguir cuadrando**.

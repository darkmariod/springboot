<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Concerns\ExportsReports;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Services\PayrollCalculator;
use App\Support\NominaRol;
use App\Support\RolDePagosPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller {
    use ExportsReports;

    public function index(Request $r) {
        return Payroll::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderByDesc('anio')->orderByDesc('mes')->get();
    }
    public function show(Payroll $payroll) {
        return $payroll->load('lines.employee:id,nombres,cedula,cargo');
    }

    /**
     * Genera (o regenera, mientras siga abierto) el rol de un mes. Cada línea toma sola los movimientos pendientes del empleado,
     * sus permisos sin sueldo y los consumos facturados a su cliente ligado (ver NominaRol). `extras` (por id de empleado:
     * horas_extra, comisiones, otros_ingresos, prestamos, anticipos, descuentos) sigue funcionando para lo que se escriba a mano.
     * Un rol cerrado no se vuelve a generar: ya está contabilizado.
     */
    public function generate(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'anio'=>['required','integer','min:2020'],
            'mes'=>['required','integer','min:1','max:12'],
            'extras'=>['sometimes','array'],
            'extras.*'=>['array'],
            'extras.*.*'=>['numeric','min:0'],
        ]);
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);
        $company = Company::findOrFail($d['company_id']);
        $sbu = (float)($company->sbu ?? 470);

        return DB::transaction(function() use ($d, $company, $sbu) {
            $existente = Payroll::where('company_id',$company->id)->where('anio',$d['anio'])->where('mes',$d['mes'])->lockForUpdate()->first();
            if ($existente && $existente->estado === 'cerrado') {
                throw ValidationException::withMessages(['payroll'=>[sprintf('El rol de pagos de %02d/%d ya está cerrado y contabilizado; no se vuelve a generar.', $d['mes'], $d['anio'])]]);
            }
            $payroll = $existente ?? Payroll::create(['company_id'=>$company->id, 'anio'=>$d['anio'], 'mes'=>$d['mes'], 'estado'=>'abierto']);
            $payroll->lines()->delete();

            foreach (Employee::where('company_id',$company->id)->where('activo',true)->orderBy('nombres')->get() as $e) {
                $payroll->lines()->create(NominaRol::lineaDe($payroll, $e, $sbu, $d['extras'][$e->id] ?? []));
            }
            NominaRol::totales($payroll);
            return $payroll->fresh()->load('lines.employee:id,nombres,cedula,cargo');
        });
    }

    /** Cambia qué facturas del cliente ligado al empleado se descuentan en este rol (todas las abiertas entran por defecto). */
    public function consumos(Request $r, Payroll $payroll) {
        $d = $r->validate([
            'employee_id'=>['required','integer'],
            'excluir'=>['present','array'],
            'excluir.*'=>['integer'],
        ]);
        $this->exigirUsuarioDeLaEmpresa($r, (int) $payroll->company_id);
        return DB::transaction(function() use ($payroll, $d) {
            $p = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            if ($p->estado === 'cerrado') {
                throw ValidationException::withMessages(['payroll'=>['El rol ya está cerrado: no se pueden cambiar sus descuentos.']]);
            }
            $linea = $p->lines()->where('employee_id', $d['employee_id'])->first();
            if (! $linea) {
                throw ValidationException::withMessages(['employee_id'=>['Ese empleado no está en este rol. Genera el rol otra vez.']]);
            }
            NominaRol::recalcular($p, $linea, $d['excluir']);
            return $p->fresh()->load('lines.employee:id,nombres,cedula,cargo');
        });
    }

    /** Cierra el rol: aplica movimientos y facturas descontadas y publica UN asiento. Cerrarlo dos veces, o en un mes contable cerrado, da 422 sin cambiar nada. */
    public function close(Request $r, Payroll $payroll) {
        $this->exigirUsuarioDeLaEmpresa($r, (int) $payroll->company_id);
        return DB::transaction(function() use ($payroll) {
            $asiento = NominaRol::cerrar($payroll);
            return ['ok'=>true, 'mensaje'=>'Rol cerrado y contabilizado.', 'asiento'=>$asiento->numero];
        });
    }

    /** Rol de pagos individual en PDF: empresa, período, empleado, ingresos, egresos (movimientos y consumos incluidos), neto en números y letras y firmas. */
    public function rolIndividual(Request $r, Payroll $payroll, Employee $employee) {
        $this->exigirDeLaEmpresa($r, $payroll);
        $linea = $payroll->lines()->with('employee:id,nombres,cedula,cargo')->where('employee_id', $employee->id)->first();
        abort_unless($linea, 404, 'Ese empleado no está en este rol de pagos.');

        $html = RolDePagosPdf::html($payroll, $linea, $r->user()?->name);
        $archivo = sprintf('Rol-%d-%02d-%s.pdf', $payroll->anio, $payroll->mes, preg_replace('/[^A-Za-z0-9._-]+/', '-', $employee->cedula));

        return Pdf::loadHtml($html)->setPaper('a4', 'portrait')->download($archivo);
    }

    /** Rol general: una fila por empleado con todos los rubros. `formato` = pdf (por defecto) o csv. */
    public function rolGeneral(Request $r, Payroll $payroll) {
        $this->exigirDeLaEmpresa($r, $payroll);
        $formato = $r->query('formato', 'pdf');
        abort_unless(in_array($formato, ['pdf','csv'], true), 422, 'El formato debe ser pdf o csv.');

        $m = fn($v) => number_format((float) $v, 2, '.', '');
        $cabecera = ['Empleado','Cédula','Sueldo','H. extra','Comisiones','Otros ingr.','Total ingresos','IESS 9.45%','Permisos',
            'Anticipos','Préstamos','Descuentos','Consumos','Total egresos','Neto a pagar'];
        $filas = [];
        $acum = array_fill(0, 13, 0.0);
        foreach ($payroll->lines()->with('employee:id,nombres,cedula')->get()->sortBy(fn($l)=>$l->employee?->nombres) as $l) {
            $ing = (float)$l->sueldo + (float)$l->horas_extra + (float)$l->comisiones + (float)$l->otros_ingresos;
            $egr = (float)$l->aporte_personal + (float)$l->permisos + (float)$l->anticipos + (float)$l->prestamos + (float)$l->descuentos + (float)$l->consumos;
            $v = [(float)$l->sueldo,(float)$l->horas_extra,(float)$l->comisiones,(float)$l->otros_ingresos,$ing,(float)$l->aporte_personal,
                (float)$l->permisos,(float)$l->anticipos,(float)$l->prestamos,(float)$l->descuentos,(float)$l->consumos,$egr,(float)$l->neto];
            foreach ($v as $i => $x) $acum[$i] += $x;
            $filas[] = array_merge([$l->employee?->nombres, $l->employee?->cedula], array_map($m, $v));
        }
        $total = array_merge(['TOTAL',''], array_map($m, $acum));
        $periodo = RolDePagosPdf::periodo($payroll);
        $archivo = sprintf('Rol-general-%d-%02d', $payroll->anio, $payroll->mes);

        if ($formato === 'csv') {
            return $this->csvResponse($cabecera, array_merge($filas, [['__total'=>$total]]), $archivo, true);
        }
        return $this->pdfResponse("Rol de pagos general · $periodo", $cabecera, $filas, $archivo, $total);
    }

    public function liquidacion(Request $r, Employee $employee, PayrollCalculator $calc) {
        $d = $r->validate(['fecha_salida'=>['required','date']]);
        $company = Company::findOrFail($employee->company_id);
        return $calc->liquidacion($employee, (float)($company->sbu ?? 470), $d['fecha_salida']);
    }

    /** Los PDF y exportaciones piden la empresa en `company_id`; un rol de otra empresa no existe (404). */
    private function exigirDeLaEmpresa(Request $r, Payroll $payroll): void {
        $d = $r->validate(['company_id'=>['required','integer','exists:companies,id']]);
        abort_unless((int) $d['company_id'] === (int) $payroll->company_id, 404, 'No se encontró el rol de pagos en esta empresa.');
        $this->exigirUsuarioDeLaEmpresa($r, (int) $payroll->company_id);
    }

    private function exigirUsuarioDeLaEmpresa(Request $r, int $empresa): void {
        $u = $r->user();
        abort_if($u && ! $u->esAdmin() && $u->company_id && (int) $u->company_id !== $empresa, 404, 'No se encontró el rol de pagos en esta empresa.');
    }
}

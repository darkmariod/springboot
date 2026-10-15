<?php
namespace App\Http\Controllers;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Payroll;
use App\Support\VacacionesEmpleado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Permisos, vacaciones, enfermedad y otras ausencias de un empleado (T7.1), y el saldo de vacaciones.
 * Los permisos sin sueldo aprobados se descuentan en el rol del mes (NominaRol / VacacionesEmpleado).
 */
class EmployeeLeaveController extends Controller {
    public function index(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'employee_id'=>['nullable','integer'],
            'estado'=>['nullable', Rule::in(EmployeeLeave::ESTADOS)],
            'tipo'=>['nullable', Rule::in(EmployeeLeave::TIPOS)],
        ]);
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);
        return EmployeeLeave::with('employee:id,nombres,cedula')->where('company_id', $d['company_id'])
            ->when($d['employee_id'] ?? null, fn($q,$id)=>$q->where('employee_id',$id))
            ->when($d['estado'] ?? null, fn($q,$v)=>$q->where('estado',$v))
            ->when($d['tipo'] ?? null, fn($q,$v)=>$q->where('tipo',$v))
            ->orderByDesc('fecha_desde')->orderByDesc('id')->get();
    }

    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'employee_id'=>['required','integer'],
            'tipo'=>['required', Rule::in(EmployeeLeave::TIPOS)],
            'fecha_desde'=>['required','date'],
            'fecha_hasta'=>['required','date','after_or_equal:fecha_desde'],
            'dias'=>['nullable','numeric','gt:0','max:366'],
            'remunerado'=>['nullable','boolean'],
            'observacion'=>['nullable','string','max:500'],
            'estado'=>['nullable', Rule::in(['pendiente','aprobado'])],
        ], [
            'fecha_hasta.after_or_equal'=>'La fecha final no puede ser anterior a la inicial.',
            'dias.gt'=>'Los días deben ser mayores que cero.',
        ]);
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);
        $empleado = Employee::where('company_id', $d['company_id'])->find($d['employee_id']);
        if (! $empleado) {
            throw ValidationException::withMessages(['employee_id'=>['El empleado no existe en esta empresa.']]);
        }

        $desde = Carbon::parse($d['fecha_desde'])->startOfDay();
        $hasta = Carbon::parse($d['fecha_hasta'])->startOfDay();
        $calendario = (int) $desde->diffInDays($hasta) + 1;
        if ($calendario > VacacionesEmpleado::MAX_DIAS_CALENDARIO) {
            throw ValidationException::withMessages(['fecha_hasta'=>['Una ausencia no puede durar más de un año. Divídela en varias.']]);
        }
        // Días: los que escribas (medio día, por ejemplo) o, si no, los días hábiles (lunes a viernes) entre las dos fechas
        $dias = isset($d['dias']) ? round((float) $d['dias'], 2) : (float) VacacionesEmpleado::diasHabiles($desde, $hasta);
        if ($dias <= 0) {
            throw ValidationException::withMessages(['dias'=>['Ese rango solo tiene sábados y domingos. Escribe los días a mano si de verdad faltó.']]);
        }
        if ($dias > $calendario) {
            throw ValidationException::withMessages(['dias'=>["Los días ($dias) no pueden ser más que los del rango ($calendario)."]]);
        }

        $cruce = EmployeeLeave::where('company_id', $d['company_id'])->where('employee_id', $empleado->id)
            ->whereIn('estado', ['pendiente','aprobado'])
            ->whereDate('fecha_desde', '<=', $hasta->toDateString())->whereDate('fecha_hasta', '>=', $desde->toDateString())->first();
        if ($cruce) {
            throw ValidationException::withMessages(['fecha_desde'=>[
                'Ya tiene una ausencia registrada del '.$cruce->fecha_desde->format('d/m/Y').' al '.$cruce->fecha_hasta->format('d/m/Y').'. Cancélala primero si quieres reemplazarla.']]);
        }

        $leave = EmployeeLeave::create([
            'company_id'=>$d['company_id'], 'employee_id'=>$empleado->id, 'tipo'=>$d['tipo'],
            'fecha_desde'=>$desde->toDateString(), 'fecha_hasta'=>$hasta->toDateString(), 'dias'=>$dias,
            'remunerado'=>(bool) ($d['remunerado'] ?? true), 'observacion'=>$d['observacion'] ?? null,
            'estado'=>$d['estado'] ?? 'aprobado',
        ]);

        return response()->json($leave->load('employee:id,nombres,cedula')->toArray() + $this->avisos($leave, $empleado), 201);
    }

    /** Cambia el estado (aprobar, rechazar, cancelar) o la observación. Las fechas y los días no se editan: cancela y registra de nuevo. */
    public function update(Request $r, EmployeeLeave $leave) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'estado'=>['nullable', Rule::in(EmployeeLeave::ESTADOS)],
            'observacion'=>['nullable','string','max:500'],
        ]);
        abort_unless((int) $d['company_id'] === (int) $leave->company_id, 404, 'No se encontró la ausencia en esta empresa.');
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);
        $leave->update(array_filter(['estado'=>$d['estado'] ?? null, 'observacion'=>$d['observacion'] ?? null], fn($v)=>$v !== null));
        $empleado = Employee::find($leave->employee_id);
        return $leave->load('employee:id,nombres,cedula')->toArray() + $this->avisos($leave, $empleado);
    }

    /** Saldo de vacaciones del empleado: ganados, tomados y lo que queda (o lo que "debe"), con sus ausencias aprobadas de vacaciones. */
    public function vacaciones(Request $r, Employee $employee) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'], 'al'=>['nullable','date']]);
        abort_unless((int) $d['company_id'] === (int) $employee->company_id, 404, 'No se encontró el empleado en esta empresa.');
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);
        return VacacionesEmpleado::saldo($employee, $d['al'] ?? null) + [
            'empleado'=>['id'=>$employee->id, 'nombres'=>$employee->nombres, 'fecha_ingreso'=>$employee->fecha_ingreso?->toDateString()],
        ];
    }

    /** Avisos para quien registra: vacaciones por encima del saldo y descuentos de meses cuyo rol ya se cerró (no se corrigen hacia atrás). */
    private function avisos(EmployeeLeave $leave, ?Employee $empleado): array {
        $avisos = [];
        if ($empleado && $leave->tipo === 'vacaciones' && $leave->estado === 'aprobado') {
            $s = VacacionesEmpleado::saldo($empleado);
            if ($s['debe'] > 0) {
                $avisos[] = 'El empleado debe '.rtrim(rtrim(number_format($s['debe'], 2, '.', ''), '0'), '.').' días de vacaciones (tomó más de los que ganó).';
            }
        }
        if ($empleado && $leave->estado === 'aprobado' && ! $leave->remunerado) {
            for ($c = Carbon::parse($leave->fecha_desde)->startOfMonth(); $c->lte($leave->fecha_hasta); $c->addMonth()) {
                if (Payroll::where('company_id', $leave->company_id)->where('anio', $c->year)->where('mes', $c->month)->where('estado', 'cerrado')->exists()) {
                    $avisos[] = sprintf('El rol de %02d/%d ya está cerrado: el descuento de ese mes no se aplicará.', $c->month, $c->year);
                }
            }
        }
        return ['avisos'=>$avisos];
    }

    private function exigirUsuarioDeLaEmpresa(Request $r, int $empresa): void {
        $u = $r->user();
        abort_if($u && ! $u->esAdmin() && $u->company_id && (int) $u->company_id !== $empresa, 404, 'No se encontró la empresa.');
    }
}

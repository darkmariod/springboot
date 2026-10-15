<?php
namespace App\Http\Controllers;
use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Services\RegistrarPagos;
use App\Support\ContraAsiento;
use App\Support\Cuentas;
use App\Support\MovimientosBanco;
use App\Support\NominaMovimientos;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Movimientos de empleado (T7.1): anticipos, préstamos a cuotas, descuentos e ingresos extra. El rol de pagos los toma solo
 * (NominaRol); aquí se registran, se listan y se cancelan mientras nunca hayan entrado a un rol cerrado.
 */
class EmployeeMovementController extends Controller {
    /** Formas con que la empresa entrega el dinero de un anticipo o préstamo (las de caja y banco de config/formas_pago.php). */
    private const FORMAS_ENTREGA = ['efectivo', 'cheque_caja', 'cheque_banco', 'transferencia'];

    public function index(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'employee_id'=>['nullable','integer'],
            'mes'=>['nullable','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'estado'=>['nullable', Rule::in(['pendiente','aplicado','cancelado'])],
            'tipo'=>['nullable', Rule::in(EmployeeMovement::TIPOS)],
        ]);
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);
        return EmployeeMovement::with('employee:id,nombres,cedula')->where('company_id', $d['company_id'])
            ->when($d['employee_id'] ?? null, fn($q,$id)=>$q->where('employee_id',$id))
            ->when($d['estado'] ?? null, fn($q,$v)=>$q->where('estado',$v))
            ->when($d['tipo'] ?? null, fn($q,$v)=>$q->where('tipo',$v))
            ->when($d['mes'] ?? null, function($q,$mes) {
                $ini = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();
                $q->whereBetween('fecha', [$ini->toDateString(), $ini->copy()->endOfMonth()->toDateString()]);
            })
            ->orderByDesc('fecha')->orderByDesc('id')->get()
            ->map(fn(EmployeeMovement $m) => $this->presentar($m))->values();
    }

    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'employee_id'=>['required','integer'],
            'tipo'=>['required', Rule::in(EmployeeMovement::TIPOS)],
            'subtipo'=>['nullable', Rule::in(EmployeeMovement::SUBTIPOS)],
            'concepto'=>['required','string','max:150'],
            'fecha'=>['required','date'],
            'aplicar_desde'=>['nullable','date'],
            'monto'=>['required','numeric','min:0.01','max:9999999'],
            'cuotas'=>['nullable','integer','min:1','max:60'],
            'entrega'=>['nullable','array'],
            'entrega.forma_pago'=>['required_with:entrega', Rule::in(self::FORMAS_ENTREGA)],
            'entrega.bank_id'=>['nullable','integer'],
            'entrega.documento'=>['nullable','string','max:60'],
        ], [
            'subtipo.in'=>'Elige si el ingreso extra son horas extra, comisiones o un bono.',
            'entrega.forma_pago.in'=>'Elige cómo entregas el dinero: efectivo, cheque o transferencia.',
        ]);
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);

        $empleado = Employee::where('company_id', $d['company_id'])->find($d['employee_id']);
        if (! $empleado) {
            throw ValidationException::withMessages(['employee_id'=>['El empleado no existe en esta empresa.']]);
        }
        $tipo = $d['tipo'];
        if ($tipo === 'ingreso_extra' && empty($d['subtipo'])) {
            throw ValidationException::withMessages(['subtipo'=>['Elige si el ingreso extra son horas extra, comisiones o un bono.']]);
        }
        $entrega = $d['entrega'] ?? null;
        if ($entrega && ! in_array($tipo, ['anticipo','prestamo'], true)) {
            throw ValidationException::withMessages(['entrega'=>['Solo un anticipo o un préstamo entrega dinero. Un descuento o un ingreso extra no mueve caja ni bancos.']]);
        }
        if ($entrega && in_array($entrega['forma_pago'], ['cheque_banco','transferencia'], true) && empty($entrega['bank_id'])) {
            throw ValidationException::withMessages(['entrega.bank_id'=>['Elige el banco desde el que entregas el dinero.']]);
        }

        $monto = round((float) $d['monto'], 2);
        $cuotas = $tipo === 'prestamo' ? (int) ($d['cuotas'] ?? 1) : 1;
        // La entrega sale hoy de caja o banco: el asiento lleva la fecha de hoy, así que el movimiento también
        $fecha = $entrega ? now()->toDateString() : Carbon::parse($d['fecha'])->toDateString();
        // Primer mes en que se descuenta (día 1): por defecto el mes del movimiento
        $desde = Carbon::parse($d['aplicar_desde'] ?? $fecha)->startOfMonth()->toDateString();

        $m = DB::transaction(function() use ($d, $empleado, $tipo, $monto, $cuotas, $fecha, $desde, $entrega) {
            $m = EmployeeMovement::create([
                'company_id'=>$d['company_id'], 'employee_id'=>$empleado->id, 'tipo'=>$tipo,
                'subtipo'=>$tipo === 'ingreso_extra' ? $d['subtipo'] : null,
                'concepto'=>trim($d['concepto']), 'fecha'=>$fecha, 'aplicar_desde'=>$desde,
                'monto'=>$monto, 'cuotas'=>$cuotas, 'saldo'=>$monto, 'estado'=>EmployeeMovement::PENDIENTE,
            ]);
            if ($entrega) {
                // Debe Anticipos y préstamos a empleados / Haber Caja o Banco (con su movimiento de banco para conciliar)
                Cuentas::cuenta((int) $d['company_id'], 'anticipos_empleados');
                $etiqueta = $tipo === 'prestamo' ? 'Préstamo' : 'Anticipo';
                app(RegistrarPagos::class)->handle($m, [[
                    'tipo'=>$entrega['forma_pago'], 'valor'=>$monto, 'fecha'=>$fecha,
                    'bank_id'=>$entrega['bank_id'] ?? null, 'documento'=>$entrega['documento'] ?? null,
                ]], Cuentas::codigo('anticipos_empleados'), "$etiqueta a {$empleado->nombres}: {$m->concepto}", RegistrarPagos::PAGO, $empleado->cedula);
                $m->update(['entregado'=>true, 'forma_entrega'=>$entrega['forma_pago'],
                    'bank_id'=>$entrega['bank_id'] ?? null, 'documento'=>$entrega['documento'] ?? null]);
            }
            return $m;
        });

        return response()->json($this->presentar($m->load('employee:id,nombres,cedula')), 201);
    }

    /** Cancela un movimiento que nunca entró a un rol cerrado. Si la empresa entregó el dinero, se reversa su asiento y su movimiento de banco. */
    public function cancelar(Request $r, EmployeeMovement $movement) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'], 'motivo'=>['nullable','string','max:200']]);
        $this->exigirDeLaEmpresa((int) $d['company_id'], (int) $movement->company_id);

        $m = DB::transaction(function() use ($movement, $d) {
            $m = EmployeeMovement::whereKey($movement->id)->lockForUpdate()->firstOrFail();
            if ($m->estado === EmployeeMovement::CANCELADO) {
                throw ValidationException::withMessages(['movimiento'=>['Este movimiento ya está cancelado.']]);
            }
            if ($m->tuvoAplicacion()) {
                throw ValidationException::withMessages(['movimiento'=>['No se puede cancelar: ya se descontó en un rol cerrado ($'.number_format($m->aplicado(), 2).'). Lo que falta se sigue descontando.']]);
            }
            if ($m->entregado) {
                $texto = "Reversión de {$m->tipo} cancelado: {$m->concepto}";
                ContraAsiento::deDocumento($m, $texto, 'CANCELADO');
                if ($m->bank_id) {
                    MovimientosBanco::registrar((int) $m->company_id, $m->bank_id, MovimientosBanco::COBRO, (float) $m->monto, $texto, $m->documento, $m);
                }
            }
            $m->update(['estado'=>EmployeeMovement::CANCELADO, 'cancelado_at'=>now(), 'motivo_cancelacion'=>$d['motivo'] ?? null]);
            return $m;
        });

        return $this->presentar($m->load('employee:id,nombres,cedula'));
    }

    /** Movimiento con lo que la pantalla necesita: lo aplicado, el valor de la cuota y si todavía se puede cancelar. */
    private function presentar(EmployeeMovement $m): array {
        $cuota = $m->tipo === 'prestamo' ? round($m->monto / max(1, $m->cuotas), 2) : $m->monto;
        return $m->toArray() + [
            'aplicado'=>$m->aplicado(),
            'cuota'=>$cuota,
            'puede_cancelar'=>$m->estado === EmployeeMovement::PENDIENTE && ! $m->tuvoAplicacion(),
            'proxima_cuota'=>$m->estado === EmployeeMovement::PENDIENTE
                ? NominaMovimientos::debidoEnMes($m, (int) now()->year, (int) now()->month) : 0.0,
        ];
    }

    private function exigirDeLaEmpresa(int $pedida, int $propia): void {
        abort_unless($pedida === $propia, 404, 'No se encontró el movimiento en esta empresa.');
    }

    private function exigirUsuarioDeLaEmpresa(Request $r, int $empresa): void {
        $u = $r->user();
        abort_if($u && ! $u->esAdmin() && $u->company_id && (int) $u->company_id !== $empresa, 404, 'No se encontró la empresa.');
    }
}

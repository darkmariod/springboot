<?php
namespace App\Http\Controllers;
use App\Models\Bank;
use App\Models\BankMovement;
use App\Support\ArchivoBanco;
use App\Support\AsientoCajaBanco;
use App\Support\ConciliacionArchivo;
use App\Support\NumeroDocumento;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BankMovementController extends Controller {
    /**
     * Movimientos de un banco con sus saldos. Un cheque posfechado (cheque con fecha de cobro todavía por llegar) se muestra
     * pero NO cuenta en el saldo del sistema ni en el conciliado: el banco aún no lo cobra. Su total va aparte en `saldo_posfechado`.
     */
    public function index(Request $r) {
        $rows = BankMovement::with('cuenta:id,codigo,nombre')
            ->when($r->bank_id, fn($q,$id)=>$q->where('bank_id',$id))
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderBy('fecha')->orderBy('id')->get();
        $hoy = now()->toDateString();
        $efecto = fn($m) => $m->tipo==='credito' ? (float) $m->monto : -(float) $m->monto;
        $vigentes = $rows->reject(fn($m)=>$m->esPosfechado($hoy));
        $posfechados = $rows->filter(fn($m)=>$m->esPosfechado($hoy));
        return [
            'movimientos'=>$rows->map(fn($m)=>array_merge($m->toArray(), ['posfechado'=>$m->esPosfechado($hoy)]))->values(),
            'saldo_sistema'=>round($vigentes->sum($efecto),2),
            'saldo_conciliado'=>round($vigentes->where('conciliado',true)->sum($efecto),2),
            'saldo_posfechado'=>round($posfechados->sum($efecto),2),
            'posfechados'=>$posfechados->count(),
        ];
    }

    /**
     * Movimiento de banco escrito a mano (T5.3). Lleva su número MB-000001 y su asiento, en la fecha del movimiento:
     *   entra dinero ('credito')  Debe Bancos / Haber la contrapartida;   sale dinero ('debito')  Debe la contrapartida / Haber Bancos.
     * La contrapartida es obligatoria, salvo que se marque `ya_contabilizado` (el movimiento ya está asentado por otro lado y solo
     * se quiere conciliar): entonces no lleva número ni asiento. Los que nacen de un pago o cobro ya traen su asiento (el del pago)
     * y no pasan por aquí, nunca se asientan dos veces.
     * `forma` = cheque con `fecha_cobro` posterior a hoy es un cheque posfechado: el asiento queda en la fecha de emisión, pero no
     * cuenta en el saldo del banco ni se concilia hasta su fecha de cobro. `numero_documento` es otro nombre de `documento`.
     */
    public function store(Request $r) {
        $cid = (int) $r->input('company_id');
        $yaContabilizado = $r->boolean('ya_contabilizado');
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'bank_id'=>['required',Rule::exists('banks','id')->where('company_id',$cid)],
            'fecha'=>['required','date'],'tipo'=>['required','in:debito,credito'],
            'monto'=>['required','numeric','min:0.01'],'concepto'=>['required','string','max:255'],
            'documento'=>['nullable','string','max:100'],'numero_documento'=>['nullable','string','max:100'],
            'forma'=>['nullable','in:cheque,transferencia,deposito,otro'],
            'fecha_cobro'=>['nullable','date','after_or_equal:fecha'],
            'ya_contabilizado'=>['nullable','boolean'],
            'cuenta_contrapartida_id'=>[Rule::requiredIf(! $yaContabilizado),'nullable','integer'],
        ], [
            'bank_id.exists'=>'El banco elegido no existe en esta empresa.',
            'cuenta_contrapartida_id.required'=>'Elige la cuenta de contrapartida del movimiento.',
            'fecha_cobro.after_or_equal'=>'La fecha de cobro no puede ser anterior a la fecha del movimiento.',
        ]);
        if (! empty($d['fecha_cobro']) && ($d['forma'] ?? null) !== 'cheque') {
            throw ValidationException::withMessages(['fecha_cobro'=>['La fecha de cobro solo aplica a los cheques.']]);
        }
        $documento = trim((string) ($d['documento'] ?? $d['numero_documento'] ?? ''));
        $fecha = Carbon::parse($d['fecha'])->toDateString();
        $monto = round((float) $d['monto'], 2);

        $mov = DB::transaction(function () use ($d, $cid, $yaContabilizado, $documento, $fecha, $monto) {
            $banco = Bank::where('company_id',$cid)->findOrFail($d['bank_id']);
            $cuentaBanco = AsientoCajaBanco::cuentaBanco($banco);
            $contra = $yaContabilizado ? null : AsientoCajaBanco::exigirContrapartida($cid, $d['cuenta_contrapartida_id'] ?? null, $cuentaBanco);
            $numero = $yaContabilizado ? null : NumeroDocumento::siguiente($cid, BankMovement::where('company_id',$cid), 'MB');

            $mov = BankMovement::create([
                'company_id'=>$cid,'bank_id'=>$banco->id,'fecha'=>$fecha,'tipo'=>$d['tipo'],'monto'=>$monto,'concepto'=>$d['concepto'],
                'documento'=>$documento !== '' ? $documento : null,'conciliado'=>false,
                'numero'=>$numero,'clase'=>$yaContabilizado ? BankMovement::SIN_ASIENTO : BankMovement::MANUAL,
                'forma'=>$d['forma'] ?? null,'fecha_cobro'=>$d['fecha_cobro'] ?? null,'cuenta_contrapartida_id'=>$contra?->id,
            ]);
            if (! $yaContabilizado) {
                $ref = $documento !== '' ? $documento : $numero;
                $entra = $d['tipo'] === 'credito';
                AsientoCajaBanco::publicar($cid, $fecha, 'Movimiento de banco '.$numero.' · '.$banco->nombre.' · '.$d['concepto'], $mov, [
                    $entra ? AsientoCajaBanco::debe($cuentaBanco,$monto,$ref) : AsientoCajaBanco::debe($contra,$monto,$ref),
                    $entra ? AsientoCajaBanco::haber($contra,$monto,$ref) : AsientoCajaBanco::haber($cuentaBanco,$monto,$ref),
                ]);
            }
            return $mov;
        });
        return response()->json($mov->fresh(), 201);
    }

    public function toggle(BankMovement $movement) {
        if (! $movement->conciliado && $movement->esPosfechado()) {
            throw ValidationException::withMessages(['conciliado'=>['Un cheque posfechado no se puede conciliar antes de su fecha de cobro ('.$movement->fecha_cobro->format('d/m/Y').').']]);
        }
        $movement->update(['conciliado'=>!$movement->conciliado]);
        return $movement;
    }

    /**
     * Primera mitad de la conciliación con el archivo del banco: lee el CSV y PROPONE las parejas (documento, o fecha y monto)
     * sin marcar nada y sin asentar nada. Devuelve cada línea del archivo con su pareja del sistema (o sin ella), los movimientos del
     * sistema que el banco no trae y los conteos. Los cheques posfechados y los ya conciliados no entran.
     */
    public function importar(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'bank_id'=>['required',Rule::exists('banks','id')->where('company_id',(int) $r->input('company_id'))],
            'csv'=>['required','file','max:4096'],
            'desde'=>['nullable','date'],'hasta'=>['nullable','date','after_or_equal:desde'],
        ], ['bank_id.exists'=>'El banco elegido no existe en esta empresa.','csv.required'=>'Elige el archivo CSV del banco.']);
        $archivo = ArchivoBanco::leer((string) file_get_contents($r->file('csv')->getRealPath()));

        $lineas = $archivo['lineas'];
        $fueraDelPeriodo = 0;
        if (! empty($d['desde']) || ! empty($d['hasta'])) {
            $antes = count($lineas);
            $lineas = array_values(array_filter($lineas, fn($l)=> (empty($d['desde']) || $l['fecha'] >= $d['desde']) && (empty($d['hasta']) || $l['fecha'] <= $d['hasta'])));
            $fueraDelPeriodo = $antes - count($lineas);
            if (! $lineas) {
                throw ValidationException::withMessages(['csv'=>['Ningún movimiento del archivo cae dentro del período elegido.']]);
            }
        }
        $fechas = array_column($lineas, 'fecha');
        $desde = $d['desde'] ?? min($fechas);
        $hasta = $d['hasta'] ?? max($fechas);

        // Candidatos del sistema: sin conciliar y que ya cuentan en el banco, cerca del período
        $margen = ConciliacionArchivo::DIAS_DOCUMENTO;
        $pool = BankMovement::where('company_id',$d['company_id'])->where('bank_id',$d['bank_id'])->where('conciliado',false)->vigentes()
            ->whereDate('fecha','>=',Carbon::parse($desde)->subDays($margen)->toDateString())
            ->whereDate('fecha','<=',Carbon::parse($hasta)->addDays($margen)->toDateString())
            ->orderBy('fecha')->orderBy('id')->get();
        $pares = ConciliacionArchivo::emparejar($lineas, $pool);

        $resumen = fn(BankMovement $m)=>['id'=>$m->id,'numero'=>$m->numero,'fecha'=>substr((string) $m->fecha,0,10),'concepto'=>$m->concepto,
            'documento'=>$m->documento,'tipo'=>$m->tipo,'monto'=>round((float) $m->monto,2)];
        $salida = [];
        foreach ($lineas as $i => $l) {
            $salida[] = $l + [
                'movimiento'=>isset($pares[$i]) ? $resumen($pares[$i]['movimiento']) : null,
                'criterio'=>$pares[$i]['criterio'] ?? null,
            ];
        }
        $usados = collect($pares)->map(fn($p)=>$p['movimiento']->id)->all();
        $sinPareja = $pool->filter(fn($m)=>!in_array($m->id,$usados,true) && substr((string) $m->fecha,0,10) >= $desde && substr((string) $m->fecha,0,10) <= $hasta)
            ->map($resumen)->values();

        return [
            'formato'=>$archivo['formato'],
            'periodo'=>['desde'=>$desde,'hasta'=>$hasta],
            'lineas'=>$salida,
            'sin_pareja_banco'=>$sinPareja,
            'ignoradas'=>$archivo['ignoradas'],
            'resumen'=>[
                'lineas'=>count($lineas),
                'con_pareja'=>count($pares),
                'sin_pareja_sistema'=>count($lineas) - count($pares),
                'sin_pareja_banco'=>$sinPareja->count(),
                'ignoradas'=>count($archivo['ignoradas']),
                'fuera_del_periodo'=>$fueraDelPeriodo,
            ],
        ];
    }

    /** Segunda mitad: acepta las parejas propuestas. Solo marca como conciliados los movimientos indicados (nada se asienta). */
    public function aplicarImportacion(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'bank_id'=>['required',Rule::exists('banks','id')->where('company_id',(int) $r->input('company_id'))],
            'ids'=>['required','array','min:1'],'ids.*'=>['integer'],
        ], ['ids.required'=>'No hay parejas que aceptar.','ids.min'=>'No hay parejas que aceptar.']);
        $conciliados = BankMovement::where('company_id',$d['company_id'])->where('bank_id',$d['bank_id'])
            ->whereIn('id',$d['ids'])->where('conciliado',false)->vigentes()->update(['conciliado'=>true]);
        return ['conciliados'=>$conciliados,'omitidos'=>count($d['ids']) - $conciliados];
    }

    public function autoMatch(Request $r) {
        $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'bank_id'=>['required','exists:banks,id'],
            'csv'=>['required','file','max:4096'],
        ]);
        $filas = array_map('str_getcsv', file($r->file('csv')->getRealPath(), FILE_SKIP_EMPTY_LINES));
        $conciliados = 0; $sinMatch = [];
        foreach ($filas as $i => $f) {
            if ($i === 0 && ! is_numeric(trim($f[2] ?? ''))) continue;
            $fecha = trim($f[0] ?? ''); $monto = abs((float) ($f[2] ?? 0));
            if (! $monto) continue;
            $mov = BankMovement::where('company_id', $r->company_id)
                ->where('bank_id', $r->bank_id)->where('conciliado', false)->vigentes()
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
}

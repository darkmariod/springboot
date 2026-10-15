<?php
namespace App\Http\Controllers;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashMovement;
use App\Support\AsientoCajaBanco;
use App\Support\ComprobanteCaja;
use App\Support\Cuentas;
use App\Support\NumeroDocumento;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashController extends Controller {
    public function current(Request $r) {
        return CashSession::with('movements.cuenta:id,codigo,nombre')->where('company_id',$r->company_id)->where('estado','abierta')->latest()->first();
    }
    public function open(Request $r) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'],'saldo_inicial'=>['required','numeric','min:0']]);
        $caja = CashRegister::firstOrCreate(['company_id'=>$d['company_id'],'nombre'=>'Caja principal']);
        return CashSession::create(['company_id'=>$d['company_id'],'cash_register_id'=>$caja->id,
            'fecha'=>now()->toDateString(),'saldo_inicial'=>$d['saldo_inicial'],'estado'=>'abierta']);
    }

    /**
     * Ingreso o egreso de caja hecho a mano (T5.3). Cada uno lleva su número (CI-000001 ingresos, CE-000001 egresos; por empresa)
     * y su asiento con la contrapartida elegida:
     *   ingreso  Debe Caja / Haber la contrapartida;   egreso  Debe la contrapartida / Haber Caja.
     * Todo en una transacción: un mes contable cerrado (guarda de JournalEntry) rechaza el movimiento y no queda nada creado.
     */
    public function addMovement(Request $r, CashSession $session) {
        $cid = (int) $session->company_id;
        $d = $r->validate([
            'tipo'=>['required','in:ingreso,egreso'],
            'monto'=>['required','numeric','min:0.01'],
            'concepto'=>['required','string','max:255'],
            'cuenta_contrapartida_id'=>['required','integer',Rule::exists('accounts','id')->where('company_id',$cid)],
        ], [
            'concepto.required'=>'Escribe el concepto del movimiento.',
            'monto.min'=>'El monto debe ser mayor que cero.',
            'cuenta_contrapartida_id.required'=>'Elige la cuenta de contrapartida del movimiento.',
            'cuenta_contrapartida_id.exists'=>'La cuenta de contrapartida no existe en el plan de cuentas de esta empresa.',
        ]);
        abort_if($session->estado !== 'abierta', 422, 'La caja ya está cerrada: abre una nueva para registrar movimientos.');

        $movimiento = DB::transaction(function () use ($session, $d, $cid) {
            $caja = Cuentas::cuenta($cid, 'caja');
            $contra = AsientoCajaBanco::exigirContrapartida($cid, $d['cuenta_contrapartida_id'], $caja);
            $esIngreso = $d['tipo'] === 'ingreso';
            $monto = round((float) $d['monto'], 2);
            $numero = NumeroDocumento::siguiente($cid, CashMovement::where('company_id', $cid), $esIngreso ? 'CI' : 'CE');
            $hoy = now()->toDateString();

            $mov = $session->movements()->create([
                'tipo'=>$d['tipo'],'monto'=>$monto,'concepto'=>$d['concepto'],
                'company_id'=>$cid,'numero'=>$numero,'fecha'=>$hoy,'cuenta_contrapartida_id'=>$contra->id,
            ]);
            AsientoCajaBanco::publicar($cid, $hoy, ($esIngreso ? 'Ingreso de caja ' : 'Egreso de caja ').$numero.' · '.$d['concepto'], $mov, [
                $esIngreso ? AsientoCajaBanco::debe($caja, $monto, $numero) : AsientoCajaBanco::debe($contra, $monto, $numero),
                $esIngreso ? AsientoCajaBanco::haber($contra, $monto, $numero) : AsientoCajaBanco::haber($caja, $monto, $numero),
            ]);
            $session->increment($esIngreso ? 'ingresos' : 'egresos', $monto);

            return $mov;
        });

        return $session->fresh('movements.cuenta:id,codigo,nombre')->setRelation('movimiento', $movimiento->load('cuenta:id,codigo,nombre'));
    }

    public function close(Request $r, CashSession $session) {
        $d = $r->validate(['saldo_final_contado'=>['required','numeric']]);
        $session->update(['saldo_final_contado'=>$d['saldo_final_contado'],'estado'=>'cerrada']);
        $esperado = $session->saldo_inicial + $session->ingresos - $session->egresos;
        return ['session'=>$session,'esperado'=>round($esperado,2),'diferencia'=>round($d['saldo_final_contado']-$esperado,2)];
    }

    /**
     * Comprobante de ingreso o egreso en PDF: empresa, número, fecha, concepto, contrapartida, monto en números y en letras y
     * líneas de firma. Igual que el resto de la API, la empresa llega en `company_id`; un movimiento de otra empresa no existe (404).
     */
    public function comprobante(Request $r, CashMovement $movement) {
        $d = $r->validate(['company_id'=>['required','integer','exists:companies,id']]);
        $empresa = $movement->empresaId();
        abort_unless($empresa !== null && (int) $d['company_id'] === $empresa, 404, 'No se encontró el movimiento de caja en esta empresa.');
        $usuario = $r->user();
        abort_if(! $usuario?->esAdmin() && $usuario?->company_id && (int) $usuario->company_id !== $empresa, 404, 'No se encontró el movimiento de caja en esta empresa.');

        $html = ComprobanteCaja::html($movement, $usuario?->name);
        $archivo = 'Comprobante-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $movement->numero ?: 'caja-'.$movement->id).'.pdf';

        return Pdf::loadHtml($html)->setPaper('a4', 'portrait')->download($archivo);
    }
}

<?php
namespace App\Http\Controllers;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashMovement;
use Illuminate\Http\Request;
class CashController extends Controller {
    public function current(Request $r) {
        return CashSession::with('movements')->where('company_id',$r->company_id)->where('estado','abierta')->latest()->first();
    }
    public function open(Request $r) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'],'saldo_inicial'=>['required','numeric','min:0']]);
        $caja = CashRegister::firstOrCreate(['company_id'=>$d['company_id'],'nombre'=>'Caja principal']);
        return CashSession::create(['company_id'=>$d['company_id'],'cash_register_id'=>$caja->id,
            'fecha'=>now()->toDateString(),'saldo_inicial'=>$d['saldo_inicial'],'estado'=>'abierta']);
    }
    public function addMovement(Request $r, CashSession $session) {
        $d = $r->validate(['tipo'=>['required','in:ingreso,egreso'],'monto'=>['required','numeric','min:0.01'],'concepto'=>['required','string']]);
        $session->movements()->create($d);
        $col = $d['tipo']==='ingreso' ? 'ingresos':'egresos';
        $session->increment($col, $d['monto']);
        return $session->fresh('movements');
    }
    public function close(Request $r, CashSession $session) {
        $d = $r->validate(['saldo_final_contado'=>['required','numeric']]);
        $session->update(['saldo_final_contado'=>$d['saldo_final_contado'],'estado'=>'cerrada']);
        $esperado = $session->saldo_inicial + $session->ingresos - $session->egresos;
        return ['session'=>$session,'esperado'=>round($esperado,2),'diferencia'=>round($d['saldo_final_contado']-$esperado,2)];
    }
}

<?php
namespace App\Http\Controllers;
use App\Models\BankMovement;
use Illuminate\Http\Request;

class BankMovementController extends Controller {
    public function index(Request $r) {
        $rows = BankMovement::when($r->bank_id, fn($q,$id)=>$q->where('bank_id',$id))
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderBy('fecha')->get();
        $saldo = $rows->reduce(fn($s,$m)=> $m->tipo==='credito' ? $s+$m->monto : $s-$m->monto, 0);
        $conciliado = $rows->where('conciliado',true)
            ->reduce(fn($s,$m)=> $m->tipo==='credito' ? $s+$m->monto : $s-$m->monto, 0);
        return ['movimientos'=>$rows,'saldo_sistema'=>round($saldo,2),'saldo_conciliado'=>round($conciliado,2)];
    }
    public function store(Request $r) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id'],'bank_id'=>['required','exists:banks,id'],
            'fecha'=>['required','date'],'tipo'=>['required','in:debito,credito'],
            'monto'=>['required','numeric','min:0.01'],'concepto'=>['required','string']]);
        return response()->json(BankMovement::create($d), 201);
    }
    public function toggle(BankMovement $movement) {
        $movement->update(['conciliado'=>!$movement->conciliado]);
        return $movement;
    }
}

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
                ->where('bank_id', $r->bank_id)->where('conciliado', false)
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

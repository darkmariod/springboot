<?php
namespace App\Http\Controllers;
use App\Models\Advance;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvanceController extends Controller {
    public function index(Request $r) {
        return Advance::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->con_saldo, fn($q)=>$q->where('saldo','>',0))
            ->latest('fecha')->get();
    }
    public function store(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'contact_id'=>['required','exists:contacts,id'],
            'monto'=>['required','numeric','min:0.01'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque'],
            'bank_id'=>['nullable','exists:banks,id'],
            'nota'=>['nullable','string'],
        ]);
        return DB::transaction(function() use ($d) {
            $a = Advance::create($d + ['fecha'=>now()->toDateString(), 'saldo'=>$d['monto']]);
            $destino = $d['forma_pago']==='efectivo'
                ? ['codigo'=>'1.1.01','nombre'=>'Caja','tipo'=>'activo']
                : ['codigo'=>'1.1.02','nombre'=>'Bancos','tipo'=>'activo'];
            SimpleEntry::make($d['company_id'], 'Anticipo de cliente', [
                $destino + ['debe'=>$d['monto'],'haber'=>0,'ref'=>'ANT-'.$a->id],
                ['codigo'=>'2.1.03','nombre'=>'Anticipos de clientes','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>$d['monto'],'ref'=>'ANT-'.$a->id],
            ], $a);
            return response()->json($a->load('contact'), 201);
        });
    }
}

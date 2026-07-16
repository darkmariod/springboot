<?php
namespace App\Http\Controllers;
use App\Models\CreditNote;
use App\Services\DocumentCalculator;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditNoteController extends Controller {
    public function index(Request $r) {
        return CreditNote::with('contact:id,razon_social','invoice:id,numero')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest('fecha')->get();
    }
    public function store(Request $r, DocumentCalculator $calc) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'contact_id'=>['required','exists:contacts,id'],
            'invoice_id'=>['nullable','exists:invoices,id'],
            'tipo'=>['required','in:sri,interna'],
            'motivo'=>['required','string'],
            'items'=>['sometimes','array'],
            'importe_total'=>['required_without:items','numeric','min:0.01'],
        ]);
        return DB::transaction(function() use ($d, $calc) {
            if (!empty($d['items'])) {
                $t = $calc->fromItems($d['items']);
                $d['total_sin_impuestos'] = $t['total_sin_impuestos'];
                $d['total_impuesto'] = $t['total_impuesto'];
                $d['importe_total'] = $t['importe_total'];
            }
            $n = CreditNote::create($d + [
                'fecha'=>now()->toDateString(),
                'saldo_disponible'=>$d['importe_total'],
            ]);
            SimpleEntry::make($d['company_id'], 'Nota de crédito '.$d['tipo'].' — '.$d['motivo'], [
                ['codigo'=>'4.1.02','nombre'=>'Devoluciones y descuentos en ventas','tipo'=>'gasto',
                 'debe'=>$d['importe_total'],'haber'=>0,'ref'=>'NC-'.$n->id],
                ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo',
                 'debe'=>0,'haber'=>$d['importe_total'],'ref'=>'NC-'.$n->id],
            ], $n);
            return response()->json($n->load('contact'), 201);
        });
    }
}

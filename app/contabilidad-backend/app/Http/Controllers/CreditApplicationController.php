<?php
namespace App\Http\Controllers;
use App\Models\Advance;
use App\Models\CreditApplication;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditApplicationController extends Controller {
    public function available(Request $r) {
        $anticipos = Advance::where('company_id',$r->company_id)
            ->where('contact_id',$r->contact_id)->where('saldo','>',0)->get()
            ->map(fn($a)=>['tipo'=>'anticipo','id'=>$a->id,'fecha'=>$a->fecha->format('Y-m-d'),
                'disponible'=>(float)$a->saldo,'detalle'=>$a->nota ?? 'Anticipo']);
        $notas = CreditNote::where('company_id',$r->company_id)
            ->where('contact_id',$r->contact_id)->where('saldo_disponible','>',0)->get()
            ->map(fn($n)=>['tipo'=>'nota','id'=>$n->id,'fecha'=>$n->fecha->format('Y-m-d'),
                'disponible'=>(float)$n->saldo_disponible,'detalle'=>$n->motivo]);
        $todos = $anticipos->concat($notas);
        return ['saldos'=>$todos->values(),'total'=>round($todos->sum('disponible'),2)];
    }
    public function apply(Request $r, Invoice $invoice) {
        $d = $r->validate([
            'tipo'=>['required','in:anticipo,nota'],
            'id'=>['required','integer'],
            'monto'=>['required','numeric','min:0.01'],
        ]);
        $origen = $d['tipo']==='anticipo' ? Advance::findOrFail($d['id']) : CreditNote::findOrFail($d['id']);
        $campoSaldo = $d['tipo']==='anticipo' ? 'saldo' : 'saldo_disponible';

        if ($d['monto'] > (float)$origen->$campoSaldo + 0.001)
            throw ValidationException::withMessages(['monto'=>['El monto supera el saldo disponible.']]);
        if ($d['monto'] > (float)$invoice->saldo_pendiente + 0.001)
            throw ValidationException::withMessages(['monto'=>['El monto supera el saldo de la factura.']]);

        return DB::transaction(function() use ($invoice,$origen,$d,$campoSaldo) {
            CreditApplication::create([
                'invoice_id'=>$invoice->id,
                'origen_type'=>$origen->getMorphClass(),
                'origen_id'=>$origen->getKey(),
                'monto'=>$d['monto'],'fecha'=>now()->toDateString(),
            ]);
            $origen->decrement($campoSaldo, $d['monto']);
            $invoice->decrement('saldo_pendiente', $d['monto']);
            $debe = $d['tipo']==='anticipo'
                ? ['codigo'=>'2.1.03','nombre'=>'Anticipos de clientes','tipo'=>'pasivo']
                : ['codigo'=>'4.1.02','nombre'=>'Devoluciones y descuentos en ventas','tipo'=>'gasto'];
            SimpleEntry::make($invoice->company_id, 'Uso de saldo en factura '.$invoice->numero, [
                $debe + ['debe'=>$d['monto'],'haber'=>0,'ref'=>$invoice->numero],
                ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo',
                 'debe'=>0,'haber'=>$d['monto'],'ref'=>$invoice->numero],
            ], $invoice);
            return ['ok'=>true,'saldo_factura'=>(float)$invoice->fresh()->saldo_pendiente];
        });
    }
}

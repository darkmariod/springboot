<?php
namespace App\Http\Controllers;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayableController extends Controller {
    public function index(Request $r) {
        $rows = Purchase::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->where('saldo_pendiente','>',0)->orderBy('fecha_emision')->get()
            ->map(fn($p)=>['id'=>$p->id,'numero'=>$p->numero,'proveedor'=>$p->contact?->razon_social,
                'fecha'=>optional($p->fecha_emision)->format('Y-m-d'),
                'total'=>(float)$p->importe_total,'saldo'=>(float)$p->saldo_pendiente]);
        return ['cartera'=>$rows,'total'=>round($rows->sum('saldo'),2)];
    }
    public function pay(Request $r, Purchase $purchase) {
        $d = $r->validate(['monto'=>['required','numeric','min:0.01'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque,cruce'],
            'bank_id'=>['nullable','exists:banks,id'],'cheque_numero'=>['nullable','string']]);
        if ($d['monto'] > (float)$purchase->saldo_pendiente + 0.001)
            throw ValidationException::withMessages(['monto'=>['El pago supera el saldo pendiente.']]);
        return DB::transaction(function() use ($purchase,$d) {
            PurchasePayment::create($d + ['purchase_id'=>$purchase->id,'fecha'=>now()->toDateString()]);
            $purchase->decrement('saldo_pendiente', $d['monto']);
            $origen = match($d['forma_pago']) {
                'efectivo' => ['codigo'=>'1.1.01','nombre'=>'Caja','tipo'=>'activo'],
                'cruce'    => ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo'],
                default    => ['codigo'=>'1.1.02','nombre'=>'Bancos','tipo'=>'activo'],
            };
            SimpleEntry::make($purchase->company_id, 'Pago compra '.$purchase->numero, [
                ['codigo'=>'2.1.01','nombre'=>'Cuentas por pagar proveedores','tipo'=>'pasivo','debe'=>$d['monto'],'haber'=>0,'ref'=>$purchase->numero],
                $origen + ['debe'=>0,'haber'=>$d['monto'],'ref'=>$purchase->numero],
            ], $purchase);
            return ['ok'=>true,'saldo'=>(float)$purchase->fresh()->saldo_pendiente];
        });
    }
    public function payMultiple(\Illuminate\Http\Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque,cruce'],
            'bank_id'=>['nullable','exists:banks,id'],
            'pagos'=>['required','array','min:1'],
            'pagos.*.purchase_id'=>['required','exists:purchases,id'],
            'pagos.*.monto'=>['required','numeric','min:0.01'],
        ]);
        return \Illuminate\Support\Facades\DB::transaction(function() use ($d) {
            $total = 0;
            foreach ($d['pagos'] as $p) {
                $purchase = \App\Models\Purchase::findOrFail($p['purchase_id']);
                if ($p['monto'] > (float)$purchase->saldo_pendiente + 0.001)
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'pagos'=>["El pago a {$purchase->numero} supera su saldo."]]);
                \App\Models\PurchasePayment::create([
                    'purchase_id'=>$purchase->id,'fecha'=>now()->toDateString(),
                    'monto'=>$p['monto'],'forma_pago'=>$d['forma_pago'],'bank_id'=>$d['bank_id'] ?? null,
                ]);
                $purchase->decrement('saldo_pendiente', $p['monto']);
                $total += $p['monto'];
            }
            $origen = match($d['forma_pago']) {
                'efectivo' => ['codigo'=>'1.1.01','nombre'=>'Caja','tipo'=>'activo'],
                'cruce'    => ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo'],
                default    => ['codigo'=>'1.1.02','nombre'=>'Bancos','tipo'=>'activo'],
            };
            \App\Services\SimpleEntry::make($d['company_id'],
                'Pago múltiple a proveedores ('.count($d['pagos']).' facturas)', [
                ['codigo'=>'2.1.01','nombre'=>'Cuentas por pagar proveedores','tipo'=>'pasivo',
                 'debe'=>round($total,2),'haber'=>0,'ref'=>'PAGO-MULT'],
                $origen + ['debe'=>0,'haber'=>round($total,2),'ref'=>'PAGO-MULT'],
            ]);
            return ['ok'=>true,'pagado'=>round($total,2),'facturas'=>count($d['pagos'])];
        });
    }
}

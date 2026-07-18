<?php
namespace App\Http\Controllers;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\SimpleEntry;
use App\Services\RegistrarPagos;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivableController extends Controller {
    public function index(Request $r) {
        $hoy = Carbon::today();
        $rows = Invoice::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->where('saldo_pendiente','>',0)->orderBy('fecha_emision')->get()
            ->map(function($i) use ($hoy) {
                $dias = $i->fecha_emision ? (int)$hoy->diffInDays(Carbon::parse($i->fecha_emision)->startOfDay()) : 0;
                return ['id'=>$i->id,'numero'=>$i->numero,'cliente'=>$i->contact?->razon_social,
                    'contact_id'=>$i->contact_id,
                    'fecha'=>optional($i->fecha_emision)->format('Y-m-d'),'total'=>(float)$i->importe_total,
                    'saldo'=>(float)$i->saldo_pendiente,'dias'=>$dias,
                    'tramo'=>match(true){ $dias<=30=>'0-30', $dias<=60=>'31-60', $dias<=90=>'61-90', default=>'90+' }];
            });
        $tramos = ['0-30'=>0,'31-60'=>0,'61-90'=>0,'90+'=>0];
        foreach ($rows as $x) $tramos[$x['tramo']] += $x['saldo'];
        return ['cartera'=>$rows,'total'=>round($rows->sum('saldo'),2),
            'antiguedad'=>array_map(fn($v)=>round($v,2), $tramos)];
    }
    public function pay(Request $r, Invoice $invoice) {
        // Soporta formato antiguo (monto + forma_pago) y nuevo (pagos array)
        if ($r->has('pagos') && is_array($r->pagos)) {
            $r->validate(['pagos'=>['required','array','min:1'],
                'pagos.*.tipo'=>['required','string'],
                'pagos.*.valor'=>['required','numeric','min:0.01']]);
            $total = array_sum(array_column($r->pagos, 'valor'));
            if ($total > (float)$invoice->saldo_pendiente + 0.001)
                throw ValidationException::withMessages(['pagos'=>['El cobro supera el saldo pendiente.']]);
            return DB::transaction(function() use ($invoice, $r) {
                $service = app(RegistrarPagos::class);
                $service->handle($invoice, $r->pagos, '1.1.03', 'Cobro factura '.$invoice->numero);
                $invoice->decrement('saldo_pendiente', array_sum(array_column($r->pagos, 'valor')));
                // Registrar en invoice_payments para compatibilidad
                foreach ($r->pagos as $p) {
                    InvoicePayment::create([
                        'invoice_id'=>$invoice->id, 'fecha'=>now()->toDateString(),
                        'monto'=>$p['valor'], 'forma_pago'=>$p['tipo'],
                        'bank_id'=>$p['bank_id'] ?? null,
                    ]);
                }
                return ['ok'=>true,'saldo'=>(float)$invoice->fresh()->saldo_pendiente];
            });
        }
        // Formato antiguo: monto + forma_pago
        $d = $r->validate(['monto'=>['required','numeric','min:0.01'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque,cruce'],
            'bank_id'=>['nullable','exists:banks,id']]);
        if ($d['monto'] > (float)$invoice->saldo_pendiente + 0.001)
            throw ValidationException::withMessages(['monto'=>['El cobro supera el saldo pendiente.']]);
        return DB::transaction(function() use ($invoice,$d) {
            InvoicePayment::create($d + ['invoice_id'=>$invoice->id,'fecha'=>now()->toDateString()]);
            $invoice->decrement('saldo_pendiente', $d['monto']);
            $destino = match($d['forma_pago']) {
                'efectivo' => ['codigo'=>'1.1.01','nombre'=>'Caja','tipo'=>'activo'],
                'cruce'    => ['codigo'=>'2.1.01','nombre'=>'Cuentas por pagar proveedores','tipo'=>'pasivo'],
                default    => ['codigo'=>'1.1.02','nombre'=>'Bancos','tipo'=>'activo'],
            };
            SimpleEntry::make($invoice->company_id, 'Cobro factura '.$invoice->numero, [
                $destino + ['debe'=>$d['monto'],'haber'=>0,'ref'=>$invoice->numero],
                ['codigo'=>'1.1.03','nombre'=>'Cuentas por cobrar clientes','tipo'=>'activo','debe'=>0,'haber'=>$d['monto'],'ref'=>$invoice->numero],
            ], $invoice);
            return ['ok'=>true,'saldo'=>(float)$invoice->fresh()->saldo_pendiente];
        });
    }
}

<?php
namespace App\Http\Controllers;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\SimpleEntry;
use App\Services\RegistrarPagos;
use App\Support\Cuentas;
use App\Support\CruceSaldos;
use App\Support\EstadoCuenta;
use App\Support\MovimientosBanco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivableController extends Controller {
    public function index(Request $r) {
        $rows = Invoice::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            // Una factura anulada ya no se cobra: el chequeo contable tampoco la cuenta
            ->where('saldo_pendiente','>',0)->where('estado','!=','anulado')->orderBy('fecha_emision')->get()
            ->map(function($i) {
                // Carbon 3: $hoy->diffInDays($pasado) da NEGATIVO y toda la cartera caía en "0-30".
                // Se usa el mismo cálculo del estado de cuenta (emisión → hoy, nunca negativo).
                $dias = EstadoCuenta::dias(optional($i->fecha_emision)->toDateString());
                return ['id'=>$i->id,'numero'=>$i->numero,'cliente'=>$i->contact?->razon_social,
                    'contact_id'=>$i->contact_id,
                    'fecha'=>optional($i->fecha_emision)->format('Y-m-d'),'total'=>(float)$i->importe_total,
                    'saldo'=>(float)$i->saldo_pendiente,'dias'=>$dias,
                    'tramo'=>EstadoCuenta::tramo($dias)];
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
                // El cruce de saldos no es dinero: se cobra contra una compra del mismo cliente (CruceSaldos)
                $cruces = array_values(array_filter($r->pagos, fn($p) => CruceSaldos::esCruce($p['tipo'])));
                $normales = array_values(array_filter($r->pagos, fn($p) => ! CruceSaldos::esCruce($p['tipo'])));
                if ($normales) {
                    $service = app(RegistrarPagos::class);
                    // Se salda la CxC en que nació la factura (normal o de partes relacionadas)
                    $service->handle($invoice, $normales, Cuentas::codigo(Cuentas::cxcDe($invoice)), 'Cobro factura '.$invoice->numero, RegistrarPagos::COBRO);
                    $invoice->decrement('saldo_pendiente', array_sum(array_column($normales, 'valor')));
                    // Registrar en invoice_payments para compatibilidad
                    foreach ($normales as $p) {
                        InvoicePayment::create([
                            'invoice_id'=>$invoice->id, 'fecha'=>now()->toDateString(),
                            'monto'=>$p['valor'], 'forma_pago'=>$p['tipo'],
                            'bank_id'=>$p['bank_id'] ?? null,
                        ]);
                    }
                }
                foreach ($cruces as $p) {
                    CruceSaldos::cobrar($invoice, $p['documento_cruce'] ?? null, (float)$p['valor']);
                }
                return ['ok'=>true,'saldo'=>(float)$invoice->fresh()->saldo_pendiente];
            });
        }
        // Formato antiguo: monto + forma_pago
        $d = $r->validate(['monto'=>['required','numeric','min:0.01'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque,cruce'],
            'bank_id'=>['nullable','exists:banks,id'],
            'documento_cruce'=>['nullable','integer']]);
        if ($d['monto'] > (float)$invoice->saldo_pendiente + 0.001)
            throw ValidationException::withMessages(['monto'=>['El cobro supera el saldo pendiente.']]);
        return DB::transaction(function() use ($invoice,$d) {
            // Un cruce siempre necesita el documento contra el que se cruza (antes asentaba CxC contra CxC sin contraparte)
            if ($d['forma_pago'] === 'cruce') {
                CruceSaldos::cobrar($invoice, $d['documento_cruce'] ?? null, (float)$d['monto']);
                return ['ok'=>true,'saldo'=>(float)$invoice->fresh()->saldo_pendiente];
            }
            InvoicePayment::create(['monto'=>$d['monto'],'forma_pago'=>$d['forma_pago'],'bank_id'=>$d['bank_id'] ?? null,
                'invoice_id'=>$invoice->id,'fecha'=>now()->toDateString()]);
            $invoice->decrement('saldo_pendiente', $d['monto']);
            // Cobro de venta: Debe a dónde entra el dinero / Haber la CxC en que nació la factura
            $destino = match($d['forma_pago']) {
                'efectivo' => 'caja',
                default    => 'bancos',
            };
            SimpleEntry::make($invoice->company_id, 'Cobro factura '.$invoice->numero, [
                Cuentas::linea($destino, $d['monto'], 0, $invoice->numero),
                Cuentas::linea(Cuentas::cxcDe($invoice), 0, $d['monto'], $invoice->numero),
            ], $invoice);
            // Por banco (transferencia, cheque): el ingreso queda listo para conciliar
            if ($destino === 'bancos') {
                MovimientosBanco::registrar($invoice->company_id, $d['bank_id'] ?? null, MovimientosBanco::COBRO,
                    (float)$d['monto'], 'Cobro factura '.$invoice->numero, null, $invoice);
            }
            return ['ok'=>true,'saldo'=>(float)$invoice->fresh()->saldo_pendiente];
        });
    }
}

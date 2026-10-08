<?php
namespace App\Http\Controllers;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Services\SimpleEntry;
use App\Services\RegistrarPagos;
use App\Support\Cuentas;
use App\Support\CruceSaldos;
use App\Support\MovimientosBanco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayableController extends Controller {
    public function index(Request $r) {
        $rows = Purchase::with('contact:id,razon_social')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->where('saldo_pendiente','>',0)->orderBy('fecha_emision')->get()
            ->map(fn($p)=>['id'=>$p->id,'numero'=>$p->numero,'proveedor'=>$p->contact?->razon_social,
                'contact_id'=>$p->contact_id,
                'fecha'=>optional($p->fecha_emision)->format('Y-m-d'),
                'total'=>(float)$p->importe_total,'saldo'=>(float)$p->saldo_pendiente]);
        return ['cartera'=>$rows,'total'=>round($rows->sum('saldo'),2)];
    }
    public function pay(Request $r, Purchase $purchase) {
        // Soporta formato antiguo (monto + forma_pago) y nuevo (pagos array)
        if ($r->has('pagos') && is_array($r->pagos)) {
            $r->validate(['pagos'=>['required','array','min:1'],
                'pagos.*.tipo'=>['required','string'],
                'pagos.*.valor'=>['required','numeric','min:0.01']]);
            $total = array_sum(array_column($r->pagos, 'valor'));
            if ($total > (float)$purchase->saldo_pendiente + 0.001)
                throw ValidationException::withMessages(['pagos'=>['El pago supera el saldo pendiente.']]);
            return DB::transaction(function() use ($purchase, $r) {
                // El cruce de saldos no es dinero: se paga contra una factura del mismo proveedor (CruceSaldos)
                $cruces = array_values(array_filter($r->pagos, fn($p) => CruceSaldos::esCruce($p['tipo'])));
                $normales = array_values(array_filter($r->pagos, fn($p) => ! CruceSaldos::esCruce($p['tipo'])));
                if ($normales) {
                    $service = app(RegistrarPagos::class);
                    // Se salda la CxP en que nació la compra (normal o de partes relacionadas)
                    $service->handle($purchase, $normales, Cuentas::codigo(Cuentas::cxpDe($purchase)), 'Pago compra '.$purchase->numero, RegistrarPagos::PAGO);
                    $purchase->decrement('saldo_pendiente', array_sum(array_column($normales, 'valor')));
                    // Registrar en purchase_payments para compatibilidad
                    foreach ($normales as $p) {
                        PurchasePayment::create([
                            'purchase_id'=>$purchase->id, 'fecha'=>now()->toDateString(),
                            'monto'=>$p['valor'], 'forma_pago'=>$p['tipo'],
                            'bank_id'=>$p['bank_id'] ?? null,
                            'cheque_numero'=>$p['documento'] ?? null,
                        ]);
                    }
                }
                foreach ($cruces as $p) {
                    CruceSaldos::pagar($purchase, $p['documento_cruce'] ?? null, (float)$p['valor']);
                }
                return ['ok'=>true,'saldo'=>(float)$purchase->fresh()->saldo_pendiente];
            });
        }
        // Formato antiguo: monto + forma_pago
        $d = $r->validate(['monto'=>['required','numeric','min:0.01'],
            'forma_pago'=>['required','in:efectivo,transferencia,cheque,cruce'],
            'bank_id'=>['nullable','exists:banks,id'],'cheque_numero'=>['nullable','string'],
            'documento_cruce'=>['nullable','integer']]);
        if ($d['monto'] > (float)$purchase->saldo_pendiente + 0.001)
            throw ValidationException::withMessages(['monto'=>['El pago supera el saldo pendiente.']]);
        return DB::transaction(function() use ($purchase,$d) {
            // Un cruce siempre necesita el documento contra el que se cruza (antes asentaba CxP contra CxC sin contraparte)
            if ($d['forma_pago'] === 'cruce') {
                CruceSaldos::pagar($purchase, $d['documento_cruce'] ?? null, (float)$d['monto']);
                return ['ok'=>true,'saldo'=>(float)$purchase->fresh()->saldo_pendiente];
            }
            PurchasePayment::create(['monto'=>$d['monto'],'forma_pago'=>$d['forma_pago'],'bank_id'=>$d['bank_id'] ?? null,
                'cheque_numero'=>$d['cheque_numero'] ?? null,'purchase_id'=>$purchase->id,'fecha'=>now()->toDateString()]);
            $purchase->decrement('saldo_pendiente', $d['monto']);
            // Pago a proveedor: Debe la CxP en que nació la compra / Haber de dónde sale el dinero
            $origen = $d['forma_pago'] === 'efectivo' ? 'caja' : 'bancos';
            SimpleEntry::make($purchase->company_id, 'Pago compra '.$purchase->numero, [
                Cuentas::linea(Cuentas::cxpDe($purchase), $d['monto'], 0, $purchase->numero),
                Cuentas::linea($origen, 0, $d['monto'], $purchase->numero),
            ], $purchase);
            // Por banco (transferencia, cheque): el egreso queda listo para conciliar con el número de cheque
            if ($origen === 'bancos') {
                MovimientosBanco::registrar($purchase->company_id, $d['bank_id'] ?? null, MovimientosBanco::PAGO,
                    (float)$d['monto'], 'Pago compra '.$purchase->numero, $d['cheque_numero'] ?? null, $purchase);
            }
            return ['ok'=>true,'saldo'=>(float)$purchase->fresh()->saldo_pendiente];
        });
    }
    /**
     * Pago a varios proveedores con un solo comprobante de pago.
     *
     * Formato nuevo: `pagos` = compras [{purchase_id, monto}] y `formas` = formas de pago [{tipo, valor, bank_id, documento}]
     * cuya suma debe ser igual al total. Formato antiguo: `pagos` + `forma_pago` (+ `bank_id`), una sola forma por el total.
     * Cada compra recibe sus propios PurchasePayment; un solo asiento (Debe cada CxP / Haber cada forma). Sin cruce de saldos.
     */
    public function payMultiple(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'forma_pago'=>['required_without:formas','nullable','in:efectivo,transferencia,cheque,cruce'],
            'bank_id'=>['nullable','exists:banks,id'],
            'formas'=>['required_without:forma_pago','nullable','array','min:1'],
            'formas.*.tipo'=>['required','string'],
            'formas.*.valor'=>['required','numeric','min:0.01'],
            'formas.*.bank_id'=>['nullable','integer'],
            'formas.*.documento'=>['nullable','string','max:100'],
            'pagos'=>['required','array','min:1'],
            'pagos.*.purchase_id'=>['required','exists:purchases,id'],
            'pagos.*.monto'=>['required','numeric','min:0.01'],
        ]);
        // El cruce necesita un documento contrario por cada pago: se registra en el pago individual de cada compra
        $cruceMsg = 'Para cruzar saldos paga cada compra por separado con la forma Cruce de saldos e indica la factura contra la que se cruza.';
        if (($d['forma_pago'] ?? null) === 'cruce')
            throw ValidationException::withMessages(['forma_pago'=>[$cruceMsg]]);
        foreach ($d['formas'] ?? [] as $f) {
            if (CruceSaldos::esCruce($f['tipo']))
                throw ValidationException::withMessages(['formas'=>[$cruceMsg]]);
        }
        return DB::transaction(function() use ($d) {
            // Una compra repetida en la lista se suma: se valida contra su saldo una sola vez
            $montos = [];
            foreach ($d['pagos'] as $p) {
                $montos[$p['purchase_id']] = ($montos[$p['purchase_id']] ?? 0) + (float)$p['monto'];
            }
            $compras = [];
            foreach ($montos as $purchaseId => $monto) {
                $purchase = Purchase::where('company_id', $d['company_id'])->whereKey($purchaseId)->lockForUpdate()->first();
                if (! $purchase)
                    throw ValidationException::withMessages(['pagos'=>['Una de las compras no pertenece a esta empresa.']]);
                if (round($monto, 2) > (float)$purchase->saldo_pendiente + 0.001)
                    throw ValidationException::withMessages(['pagos'=>["El pago a {$purchase->numero} supera su saldo."]]);
                $compras[$purchaseId] = ['compra'=>$purchase, 'monto'=>round($monto, 2)];
            }
            // Formato antiguo: una sola forma por el total (cheque = cheque de banco)
            $formas = $d['formas'] ?? [[
                'tipo'=>['efectivo'=>'efectivo','transferencia'=>'transferencia','cheque'=>'cheque_banco'][$d['forma_pago']],
                'valor'=>array_sum(array_column($compras, 'monto')), 'bank_id'=>$d['bank_id'] ?? null,
            ]];
            $res = app(RegistrarPagos::class)->pagarVarios((int)$d['company_id'], $compras, $formas,
                'Pago múltiple a proveedores ('.count($compras).' facturas)');
            // Cada compra: sus pagos (uno por forma que la cubrió) y su saldo
            foreach ($compras as $purchaseId => $c) {
                foreach ($res['reparto'][$purchaseId] as $tramo) {
                    PurchasePayment::create([
                        'purchase_id'=>$purchaseId, 'fecha'=>now()->toDateString(), 'monto'=>$tramo['monto'],
                        'forma_pago'=>$tramo['tipo'], 'bank_id'=>$tramo['bank_id'], 'cheque_numero'=>$tramo['documento'],
                    ]);
                }
                $c['compra']->decrement('saldo_pendiente', $c['monto']);
            }
            return ['ok'=>true,'pagado'=>round($res['total'],2),'facturas'=>count($compras),'asiento'=>$res['asiento']->numero];
        });
    }
}

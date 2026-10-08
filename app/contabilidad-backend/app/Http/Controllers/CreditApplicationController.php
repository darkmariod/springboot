<?php
namespace App\Http\Controllers;
use App\Models\Advance;
use App\Models\CreditApplication;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\PaymentSplit;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Services\SimpleEntry;
use App\Support\Cuentas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditApplicationController extends Controller {
    public function available(Request $r) {
        // Solo anticipos de CLIENTE: los entregados a proveedores se aplican a compras (availableSupplier)
        $anticipos = Advance::where('company_id',$r->company_id)
            ->where('contact_id',$r->contact_id)->where('tipo','cliente')->where('saldo','>',0)->get()
            ->map(fn($a)=>['tipo'=>'anticipo','id'=>$a->id,'fecha'=>$a->fecha->format('Y-m-d'),
                'disponible'=>(float)$a->saldo,'detalle'=>$a->nota ?? 'Anticipo']);
        $notas = CreditNote::where('company_id',$r->company_id)
            ->where('contact_id',$r->contact_id)->where('saldo_disponible','>',0)->get()
            ->map(fn($n)=>['tipo'=>'nota','id'=>$n->id,'fecha'=>$n->fecha->format('Y-m-d'),
                'disponible'=>(float)$n->saldo_disponible,'detalle'=>$n->motivo]);
        $todos = $anticipos->concat($notas);
        return ['saldos'=>$todos->values(),'total'=>round($todos->sum('disponible'),2)];
    }
    /**
     * Usa un saldo a favor (anticipo o nota de crédito) para pagar una factura.
     * Una nota de crédito ya bajó el saldo de SU factura al emitirse (CreditNoteController::store): lo único que queda
     * disponible es lo que esa factura no debía. Aquí solo se descuenta ese sobrante, nunca el total de la nota otra vez;
     * y la nota solo se usa en facturas de su mismo cliente.
     */
    public function apply(Request $r, Invoice $invoice) {
        $d = $r->validate([
            'tipo'=>['required','in:anticipo,nota'],
            'id'=>['required','integer'],
            'monto'=>['required','numeric','min:0.01'],
        ]);
        if (! $invoice->esFactura() || $invoice->estado === 'anulado')
            throw ValidationException::withMessages(['monto'=>['Solo se puede usar un saldo a favor en una factura vigente.']]);

        return DB::transaction(function() use ($invoice,$d) {
            // Se vuelven a leer con candado: dos usos a la vez no pueden gastar el mismo saldo dos veces
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $origen = $d['tipo']==='anticipo'
                ? Advance::whereKey($d['id'])->lockForUpdate()->firstOrFail()
                : CreditNote::whereKey($d['id'])->lockForUpdate()->firstOrFail();
            if ((int)$origen->company_id !== (int)$invoice->company_id)
                throw ValidationException::withMessages(['id'=>['Ese saldo es de otra empresa.']]);
            if ($d['tipo']==='anticipo' && $origen->tipo !== 'cliente')
                throw ValidationException::withMessages(['id'=>['Ese anticipo se entregó a un proveedor: se aplica a una compra, no a una factura.']]);
            if ($d['tipo']==='nota' && $origen->tipo === 'anulado')
                throw ValidationException::withMessages(['id'=>['Esa nota de crédito está anulada.']]);
            if ($d['tipo']==='nota' && (int)$origen->contact_id !== (int)$invoice->contact_id)
                throw ValidationException::withMessages(['id'=>['Esa nota de crédito es de otro cliente: solo se usa en facturas de su mismo cliente.']]);
            $campoSaldo = $d['tipo']==='anticipo' ? 'saldo' : 'saldo_disponible';

            if ($d['monto'] > (float)$origen->$campoSaldo + 0.001)
                throw ValidationException::withMessages(['monto'=>['El monto supera el saldo disponible.']]);
            if ($d['monto'] > (float)$invoice->saldo_pendiente + 0.001)
                throw ValidationException::withMessages(['monto'=>['El monto supera el saldo de la factura.']]);

            CreditApplication::create([
                'invoice_id'=>$invoice->id,
                'origen_type'=>$origen->getMorphClass(),
                'origen_id'=>$origen->getKey(),
                'monto'=>$d['monto'],'fecha'=>now()->toDateString(),
            ]);
            $origen->decrement($campoSaldo, $d['monto']);
            $invoice->decrement('saldo_pendiente', $d['monto']);
            // La nota de crédito ya acreditó CxC cuando se emitió (CreditNoteController::store):
            // aplicarla solo baja el saldo de la factura y el saldo disponible de la nota.
            // El anticipo sí necesita asiento: al recibirlo quedó como pasivo (Anticipos de clientes)
            // y al aplicarlo se cancela contra la CxC en que nació la factura (normal o de partes relacionadas).
            $cxcFactura = Cuentas::cxcDe($invoice);
            if ($d['tipo']==='anticipo') {
                SimpleEntry::make($invoice->company_id, 'Uso de anticipo en factura '.$invoice->numero, [
                    Cuentas::linea('anticipos_clientes', $d['monto'], 0, $invoice->numero),
                    Cuentas::linea($cxcFactura, 0, $d['monto'], $invoice->numero),
                ], $invoice);
            } else {
                // Si la nota acreditó una CxC distinta a la de la factura (p. ej. el cliente pasó a ser parte
                // relacionada después de emitirla, o al revés), se pasa el saldo de una cuenta a la otra:
                // Debe la CxC de la nota (deshace su crédito) / Haber la CxC de la factura (cancela lo que debe).
                $cxcNota = Cuentas::cxcDe($origen);
                if ($cxcNota !== $cxcFactura) {
                    SimpleEntry::make($invoice->company_id, 'Aplicación de nota de crédito a factura '.$invoice->numero, [
                        Cuentas::linea($cxcNota, $d['monto'], 0, 'NC-'.$origen->getKey()),
                        Cuentas::linea($cxcFactura, 0, $d['monto'], $invoice->numero),
                    ], $invoice);
                }
            }
            return ['ok'=>true,'saldo_factura'=>(float)$invoice->fresh()->saldo_pendiente];
        });
    }
    /** Anticipos abiertos entregados a un proveedor: lo que se puede usar para pagar sus compras. */
    public function availableSupplier(Request $r) {
        $r->validate(['company_id'=>['required','exists:companies,id'],'contact_id'=>['required','exists:contacts,id']]);
        $anticipos = Advance::where('company_id',$r->company_id)->where('contact_id',$r->contact_id)
            ->where('tipo','proveedor')->where('saldo','>',0)->orderBy('fecha')->orderBy('id')->get()
            ->map(fn($a)=>['tipo'=>'anticipo','id'=>$a->id,'fecha'=>$a->fecha->format('Y-m-d'),
                'disponible'=>(float)$a->saldo,'detalle'=>$a->nota ?? 'Anticipo a proveedor']);
        return ['saldos'=>$anticipos->values(),'total'=>round($anticipos->sum('disponible'),2)];
    }

    /**
     * Usa un anticipo entregado al proveedor para pagar (en parte o todo) una compra suya:
     * Debe la CxP en que nació la compra / Haber Anticipos a proveedores. Baja el saldo de la compra
     * y el del anticipo, nunca por encima de ninguno.
     */
    public function applyPurchase(Request $r, Purchase $purchase) {
        $d = $r->validate([
            'id'=>['required','integer'],
            'monto'=>['required','numeric','min:0.01'],
        ]);
        return DB::transaction(function() use ($purchase,$d) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $anticipo = Advance::where('company_id',$purchase->company_id)->where('tipo','proveedor')
                ->whereKey($d['id'])->lockForUpdate()->first();
            if (! $anticipo || (int)$anticipo->contact_id !== (int)$purchase->contact_id)
                throw ValidationException::withMessages(['id'=>['Elige un anticipo entregado a este proveedor.']]);
            $monto = round((float)$d['monto'], 2);
            if ($monto > (float)$anticipo->saldo + 0.001)
                throw ValidationException::withMessages(['monto'=>['El monto supera el saldo disponible del anticipo.']]);
            if ($monto > (float)$purchase->saldo_pendiente + 0.001)
                throw ValidationException::withMessages(['monto'=>['El monto supera el saldo de la compra.']]);

            $anticipo->decrement('saldo', $monto);
            $purchase->decrement('saldo_pendiente', $monto);
            // Rastro de pagos de la compra (como el cruce de saldos): qué anticipo se usó queda en el documento del reparto
            PurchasePayment::create(['purchase_id'=>$purchase->id,'fecha'=>now()->toDateString(),'monto'=>$monto,'forma_pago'=>'anticipo']);
            PaymentSplit::create(['company_id'=>$purchase->company_id,'pagable_type'=>$purchase->getMorphClass(),'pagable_id'=>$purchase->getKey(),
                'tipo'=>'anticipo','fecha'=>now()->toDateString(),'valor'=>$monto,
                'documento'=>'ANT-'.$anticipo->id,'detalle'=>'Anticipo a proveedor']);
            SimpleEntry::make($purchase->company_id, 'Uso de anticipo en compra '.$purchase->numero, [
                Cuentas::linea(Cuentas::cxpDe($purchase), $monto, 0, $purchase->numero),
                Cuentas::linea('anticipos_proveedores', 0, $monto, $purchase->numero),
            ], $purchase);
            return ['ok'=>true,'saldo_compra'=>(float)$purchase->fresh()->saldo_pendiente,'saldo_anticipo'=>(float)$anticipo->fresh()->saldo];
        });
    }
}

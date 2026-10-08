<?php
namespace App\Services;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Support\Cuentas;
use App\Support\CostosInventario;

/**
 * Asiento de la factura de venta.
 *   Venta:  Debe CxC (o, si se cobra al instante, Caja / Bancos / Tarjetas por liquidar)
 *           / Haber Ventas e IVA por pagar. Si el cliente es parte relacionada, la CxC es
 *           1.1.09 (Cuentas por cobrar relacionadas) en lugar de 1.1.03.
 *   Costo:  si la factura sacó bienes del inventario, en el MISMO asiento va
 *           Debe Costo de ventas / Haber Inventario por cantidad x costo de cada salida del kárdex.
 *           Una factura de solo servicios no lleva líneas de costo.
 * Por eso quien emite la factura registra las salidas de inventario ANTES de llamar a esta clase.
 */
class GenerateInvoiceJournalEntry {
    /** Dónde entra el dinero cuando la factura no queda a crédito (así su saldo pendiente es 0 y CxC no queda abierta). */
    private const COBRO_AL_INSTANTE = ['efectivo' => 'caja', 'transferencia' => 'bancos', 'tarjeta' => 'tarjetas_por_liquidar'];

    public function handle(Invoice $inv): JournalEntry {
        $cid = $inv->company_id;
        $costo = round(CostosInventario::egresosDeFactura($inv->id)->sum(fn ($m) => CostosInventario::valor($m)), 2);

        $lineas = [
            ['account_id'=>Cuentas::cuenta($cid, self::COBRO_AL_INSTANTE[$inv->forma_pago] ?? Cuentas::cxcPara($inv->contact))->id,'debe'=>$inv->importe_total,'haber'=>0,'referencia'=>$inv->numero],
            ['account_id'=>Cuentas::cuenta($cid,'ventas')->id,'debe'=>0,'haber'=>$inv->total_sin_impuestos,'referencia'=>$inv->numero],
            ['account_id'=>Cuentas::cuenta($cid,'iva_por_pagar')->id,'debe'=>0,'haber'=>$inv->total_impuesto,'referencia'=>$inv->numero],
        ];
        if ($costo > 0) {
            $lineas[] = ['account_id'=>Cuentas::cuenta($cid,'costo_ventas')->id,'debe'=>$costo,'haber'=>0,'referencia'=>$inv->numero];
            $lineas[] = ['account_id'=>Cuentas::cuenta($cid,'inventario')->id,'debe'=>0,'haber'=>$costo,'referencia'=>$inv->numero];
        }

        $e = JournalEntry::create(['company_id'=>$cid,
            'numero'=>'AS-'.str_pad((string)(JournalEntry::where('company_id',$cid)->count()+1),6,'0',STR_PAD_LEFT),
            'fecha'=>$inv->fecha_emision ?? now(),'concepto'=>'Venta factura '.$inv->numero,
            'origen_type'=>$inv->getMorphClass(),'origen_id'=>$inv->id,
            'total_debe'=>round(array_sum(array_column($lineas,'debe')),2),
            'total_haber'=>round(array_sum(array_column($lineas,'haber')),2),'estado'=>'pendiente']);
        $e->lines()->createMany($lineas);
        return $e;
    }
}

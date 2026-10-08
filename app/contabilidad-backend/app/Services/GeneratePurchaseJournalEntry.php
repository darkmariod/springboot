<?php
namespace App\Services;
use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Support\Cuentas;
use App\Support\CostosInventario;

/**
 * Asiento de la compra. La mercadería (bienes que llevan stock) no es gasto: va a
 * 1.1.05 Inventario al costo neto de la línea, el mismo valor que entra al kárdex.
 * Los servicios y gastos (y lo que no lleva stock) siguen en 5.1.01 Compras.
 * Una compra mixta se reparte línea por línea; el IVA no cambia.
 *
 * Datos del proveedor que cambian el asiento (solo hacia adelante):
 *  - cuenta contable por defecto: los servicios y gastos se asientan en ESA cuenta en lugar de 5.1.01;
 *  - parte relacionada: la cuenta por pagar es 2.1.11 en lugar de 2.1.01.
 */
class GeneratePurchaseJournalEntry {
    public function handle(Purchase $p): JournalEntry {
        $cid = $p->company_id;
        $proveedor = $p->contact;
        $neto = round((float) $p->total_sin_impuestos, 2);
        // Lo que va a inventario nunca puede pasar de la base de la compra: el resto es gasto,
        // así el asiento cuadra aunque los items no sumen exactamente el total.
        $inventario = min($this->baseInventario($p), $neto);
        $compras = round($neto - $inventario, 2);

        $e = JournalEntry::create(['company_id'=>$cid,
            'numero'=>'AS-'.str_pad((string)(JournalEntry::where('company_id',$cid)->count()+1),6,'0',STR_PAD_LEFT),
            'fecha'=>$p->fecha_emision ?? now(),'concepto'=>'Compra factura '.$p->numero,
            'origen_type'=>$p->getMorphClass(),'origen_id'=>$p->id,
            'total_debe'=>$p->importe_total,'total_haber'=>$p->importe_total,'estado'=>'pendiente']);

        $lineas = [];
        if ($inventario > 0) {
            $lineas[] = ['account_id'=>Cuentas::cuenta($cid,'inventario')->id,'debe'=>$inventario,'haber'=>0,'referencia'=>$p->numero];
        }
        if ($compras > 0 || $inventario <= 0) {
            $lineas[] = ['account_id'=>$this->cuentaDeGasto($proveedor, $cid)->id,'debe'=>$compras,'haber'=>0,'referencia'=>$p->numero];
        }
        $lineas[] = ['account_id'=>Cuentas::cuenta($cid,'credito_tributario_iva')->id,'debe'=>$p->total_impuesto,'haber'=>0,'referencia'=>$p->numero];
        $lineas[] = ['account_id'=>Cuentas::cuenta($cid,Cuentas::cxpPara($proveedor))->id,'debe'=>0,'haber'=>$p->importe_total,'referencia'=>$p->numero];
        $e->lines()->createMany($lineas);
        return $e;
    }

    /** Dónde se asientan los servicios y gastos: la cuenta por defecto del proveedor (si es de esta empresa) o 5.1.01 Compras. */
    private function cuentaDeGasto(?Contact $proveedor, int $companyId): Account {
        if ($proveedor?->cuenta_contable_id) {
            $propia = Account::where('company_id', $companyId)->find($proveedor->cuenta_contable_id);
            if ($propia) return $propia;
        }
        return Cuentas::cuenta($companyId, 'compras');
    }

    /** Base de las líneas que son bienes con stock (el mismo criterio con que se ingresan al kárdex). */
    private function baseInventario(Purchase $p): float {
        $total = 0.0;
        foreach ((array) $p->items as $item) {
            $codigo = trim((string) ($item['codigo_principal'] ?? ''));
            if ($codigo === '' || (float) ($item['cantidad'] ?? 0) <= 0) continue;
            $producto = Product::where('company_id', $p->company_id)->where('codigo', $codigo)->first();
            if (! $producto || $producto->tipo === 'servicio') continue;
            $total += max(0, CostosInventario::baseCompra($item));
        }
        return round($total, 2);
    }
}

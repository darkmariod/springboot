<?php
namespace App\Services;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
class GenerateInvoiceJournalEntry {
    public function handle(Invoice $inv): JournalEntry {
        $cid = $inv->company_id;
        $cxc = $this->cuenta($cid,'1.1.03'); $ventas = $this->cuenta($cid,'4.1.01'); $iva = $this->cuenta($cid,'2.1.02');
        $e = JournalEntry::create(['company_id'=>$cid,
            'numero'=>'AS-'.str_pad((string)(JournalEntry::where('company_id',$cid)->count()+1),6,'0',STR_PAD_LEFT),
            'fecha'=>$inv->fecha_emision ?? now(),'concepto'=>'Venta factura '.$inv->numero,
            'origen_type'=>$inv->getMorphClass(),'origen_id'=>$inv->id,
            'total_debe'=>$inv->importe_total,'total_haber'=>$inv->importe_total,'estado'=>'pendiente']);
        $e->lines()->createMany([
            ['account_id'=>$cxc->id,'debe'=>$inv->importe_total,'haber'=>0,'referencia'=>$inv->numero],
            ['account_id'=>$ventas->id,'debe'=>0,'haber'=>$inv->total_sin_impuestos,'referencia'=>$inv->numero],
            ['account_id'=>$iva->id,'debe'=>0,'haber'=>$inv->total_impuesto,'referencia'=>$inv->numero],
        ]);
        return $e;
    }
    private function cuenta($cid,$cod){ return Account::firstOrCreate(['company_id'=>$cid,'codigo'=>$cod],['nombre'=>$cod,'tipo'=>'activo']); }
}

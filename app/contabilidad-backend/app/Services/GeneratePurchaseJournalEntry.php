<?php
namespace App\Services;
use App\Models\Account;
use App\Models\Purchase;
use App\Models\JournalEntry;
class GeneratePurchaseJournalEntry {
    public function handle(Purchase $p): JournalEntry {
        $cid = $p->company_id;
        $compras = $this->cuenta($cid,'5.1.01','gasto'); $ivaCred = $this->cuenta($cid,'1.1.04','activo'); $cxp = $this->cuenta($cid,'2.1.01','pasivo');
        $e = JournalEntry::create(['company_id'=>$cid,
            'numero'=>'AS-'.str_pad((string)(JournalEntry::where('company_id',$cid)->count()+1),6,'0',STR_PAD_LEFT),
            'fecha'=>$p->fecha_emision ?? now(),'concepto'=>'Compra factura '.$p->numero,
            'origen_type'=>$p->getMorphClass(),'origen_id'=>$p->id,
            'total_debe'=>$p->importe_total,'total_haber'=>$p->importe_total,'estado'=>'pendiente']);
        $e->lines()->createMany([
            ['account_id'=>$compras->id,'debe'=>$p->total_sin_impuestos,'haber'=>0,'referencia'=>$p->numero],
            ['account_id'=>$ivaCred->id,'debe'=>$p->total_impuesto,'haber'=>0,'referencia'=>$p->numero],
            ['account_id'=>$cxp->id,'debe'=>0,'haber'=>$p->importe_total,'referencia'=>$p->numero],
        ]);
        return $e;
    }
    private function cuenta($cid,$cod,$tipo){ return Account::firstOrCreate(['company_id'=>$cid,'codigo'=>$cod],['nombre'=>$cod,'tipo'=>$tipo]); }
}

<?php
namespace App\Services;
use App\Models\Account;
use App\Models\JournalEntry;
class SimpleEntry {
    /** $lineas: [['codigo','nombre','tipo','debe','haber','ref'], ...] */
    public static function make(int $companyId, string $concepto, array $lineas, $origen=null): JournalEntry {
        $debe = round(array_sum(array_column($lineas,'debe')),2);
        $haber = round(array_sum(array_column($lineas,'haber')),2);
        $e = JournalEntry::create(['company_id'=>$companyId,
            'numero'=>'AS-'.str_pad((string)(JournalEntry::where('company_id',$companyId)->count()+1),6,'0',STR_PAD_LEFT),
            'fecha'=>now(),'concepto'=>$concepto,
            'origen_type'=>$origen?->getMorphClass(),'origen_id'=>$origen?->getKey(),
            'total_debe'=>$debe,'total_haber'=>$haber,'estado'=>'pendiente']);
        foreach ($lineas as $l) {
            $acc = Account::firstOrCreate(['company_id'=>$companyId,'codigo'=>$l['codigo']],
                ['nombre'=>$l['nombre'],'tipo'=>$l['tipo']]);
            $e->lines()->create(['account_id'=>$acc->id,'debe'=>$l['debe'],'haber'=>$l['haber'],'referencia'=>$l['ref'] ?? null]);
        }
        return $e;
    }
}

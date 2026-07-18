<?php
namespace App\Services;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\WarehouseStock;
class RegisterInventoryMovement {
    public function handle(Product $p, string $tipo, float $cant, float $costo, string $concepto, string $fecha, ?int $warehouseId = null): InventoryMovement {
        $cantPrev=(float)$p->stock; $promPrev=(float)$p->costo_promedio; $valorPrev=round($cantPrev*$promPrev,2);
        if ($tipo==='ingreso') {
            $nuevoValor=round($valorPrev+$cant*$costo,2); $nuevaCant=round($cantPrev+$cant,2);
            $nuevoProm=$nuevaCant>0?round($nuevoValor/$nuevaCant,4):0;
        } elseif ($tipo==='egreso') {
            $costo=$promPrev; $nuevaCant=round($cantPrev-$cant,2); $nuevoProm=$promPrev; $nuevoValor=round($nuevaCant*$nuevoProm,2);
        } else { $nuevaCant=round($cantPrev+$cant,2); $nuevoProm=$promPrev; $nuevoValor=round($nuevaCant*$nuevoProm,2); $costo=$promPrev; }
        $mov=InventoryMovement::create(['company_id'=>$p->company_id,'product_id'=>$p->id,'fecha'=>$fecha,'tipo'=>$tipo,
            'concepto'=>$concepto,'cantidad'=>$cant,'costo_unitario'=>round($costo,4),'saldo_cantidad'=>$nuevaCant,
            'saldo_costo_promedio'=>$nuevoProm,'saldo_valor'=>$nuevoValor,'warehouse_id'=>$warehouseId]);
        $p->update(['stock'=>$nuevaCant,'costo_promedio'=>$nuevoProm]);

        // Actualizar stock por bodega si se especifica
        if ($warehouseId) {
            $ws = WarehouseStock::firstOrCreate(
                ['warehouse_id'=>$warehouseId, 'product_id'=>$p->id],
                ['stock'=>0]
            );
            $ws->update(['stock'=>round($tipo==='egreso' ? (float)$ws->stock - $cant : (float)$ws->stock + $cant, 2)]);
        }

        return $mov;
    }
}

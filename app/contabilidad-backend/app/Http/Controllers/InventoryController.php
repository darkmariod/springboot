<?php
namespace App\Http\Controllers;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\Request;
class InventoryController extends Controller {
    public function stock(Request $r) {
        $items = Product::where('tipo','!=','servicio')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->orderBy('descripcion')->get()
            ->map(fn($p)=>['id'=>$p->id,'codigo'=>$p->codigo,'descripcion'=>$p->descripcion,
                'stock'=>(float)$p->stock,'costo_promedio'=>(float)$p->costo_promedio,
                'valor'=>round((float)$p->stock*(float)$p->costo_promedio,2)]);
        return ['items'=>$items,'valor_total'=>round($items->sum('valor'),2)];
    }
    public function kardex(Product $product) {
        return ['producto'=>['codigo'=>$product->codigo,'descripcion'=>$product->descripcion,'stock'=>(float)$product->stock],
            'movimientos'=>InventoryMovement::where('product_id',$product->id)->orderBy('fecha')->orderBy('id')->get()];
    }
    public function reorder(Request $r) {
        return Product::where('company_id', $r->company_id)
            ->where('tipo', '!=', 'servicio')
            ->whereColumn('stock', '<', 'stock_minimo')
            ->orderBy('descripcion')->get()
            ->map(fn($p) => ['id'=>$p->id, 'codigo'=>$p->codigo, 'descripcion'=>$p->descripcion,
                'stock'=>(float)$p->stock, 'minimo'=>(float)$p->stock_minimo,
                'maximo'=>(float)$p->stock_maximo,
                'sugerido'=>max(0, (float)$p->stock_maximo - (float)$p->stock)]);
    }
}

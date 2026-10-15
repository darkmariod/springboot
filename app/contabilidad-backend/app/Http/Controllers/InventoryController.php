<?php
namespace App\Http\Controllers;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\OrigenMovimiento;
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
    /** Kárdex del producto. Cada movimiento trae su origen (origen_tipo, origen_label, origen_numero, origen_detalle): ver OrigenMovimiento. */
    public function kardex(Product $product) {
        return ['producto'=>['codigo'=>$product->codigo,'descripcion'=>$product->descripcion,'stock'=>(float)$product->stock],
            'movimientos'=>OrigenMovimiento::anotar(InventoryMovement::where('product_id',$product->id)->orderBy('fecha')->orderBy('id')->get())];
    }

    /**
     * Reporte de movimientos de inventario con su ORIGEN (compra, venta, nota de crédito, anulación, ajuste, toma física,
     * transferencia, fraccionamiento / conversión...). Filtros opcionales: desde, hasta, product_id, warehouse_id y
     * origen (la clave de OrigenMovimiento::TIPOS). En orden cronológico, como el kárdex. Tope de 5000 filas: si hay más,
     * `resumen.truncado` lo dice y se acotan las fechas.
     */
    public function movimientos(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','integer','exists:companies,id'],
            'desde'=>['nullable','date'], 'hasta'=>['nullable','date'],
            'product_id'=>['nullable','integer'], 'warehouse_id'=>['nullable','integer'],
            'origen'=>['nullable','string','in:'.implode(',', array_keys(OrigenMovimiento::TIPOS))],
        ]);
        $tope = 5000;

        $filas = InventoryMovement::where('company_id',$d['company_id'])
            ->when($d['desde'] ?? null, fn($q,$f)=>$q->whereDate('fecha','>=',$f))
            ->when($d['hasta'] ?? null, fn($q,$f)=>$q->whereDate('fecha','<=',$f))
            ->when($d['product_id'] ?? null, fn($q,$id)=>$q->where('product_id',$id))
            ->when($d['warehouse_id'] ?? null, fn($q,$id)=>$q->where('warehouse_id',$id))
            ->orderBy('fecha')->orderBy('id')->get();
        $filas = OrigenMovimiento::anotar($filas);
        if (!empty($d['origen'])) $filas = $filas->where('origen_tipo',$d['origen'])->values();
        $truncado = $filas->count() > $tope;
        $filas = $filas->take($tope);

        $productos = Product::whereIn('id',$filas->pluck('product_id')->unique())->get(['id','codigo','descripcion'])->keyBy('id');
        $bodegas = Warehouse::whereIn('id',$filas->pluck('warehouse_id')->filter()->unique())->pluck('nombre','id');

        $movimientos = $filas->map(fn($m)=>[
            'id'=>$m->id, 'fecha'=>substr((string)$m->fecha,0,10), 'tipo'=>$m->tipo, 'concepto'=>$m->concepto,
            'product_id'=>$m->product_id, 'codigo'=>$productos[$m->product_id]->codigo ?? null, 'descripcion'=>$productos[$m->product_id]->descripcion ?? null,
            'warehouse_id'=>$m->warehouse_id, 'bodega'=>$bodegas[$m->warehouse_id] ?? null,
            'cantidad'=>(float)$m->cantidad, 'costo_unitario'=>(float)$m->costo_unitario,
            'valor'=>round((float)$m->cantidad*(float)$m->costo_unitario,2), 'saldo_cantidad'=>(float)$m->saldo_cantidad,
            'origen_tipo'=>$m->origen_tipo, 'origen_label'=>$m->origen_label, 'origen_numero'=>$m->origen_numero, 'origen_detalle'=>$m->origen_detalle,
        ])->values();

        return [
            'movimientos'=>$movimientos,
            'resumen'=>['movimientos'=>$movimientos->count(),'truncado'=>$truncado,
                'valor_ingresos'=>round($movimientos->where('tipo','ingreso')->sum('valor'),2),
                'valor_egresos'=>round($movimientos->where('tipo','egreso')->sum('valor'),2)],
            'origenes'=>collect(OrigenMovimiento::TIPOS)->map(fn($label,$tipo)=>['tipo'=>$tipo,'label'=>$label])->values(),
        ];
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

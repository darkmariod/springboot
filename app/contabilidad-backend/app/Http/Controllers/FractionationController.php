<?php
namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\RegisterInventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FractionationController extends Controller
{
    /**
     * Fraccionar: dividir N unidades padre en M unidades hijo.
     * Ejemplo: 1 caja → 12 unidades.
     */
    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id'     => ['required', 'exists:companies,id'],
            'product_id'     => ['required', 'exists:products,id'],
            'quantity'       => ['required', 'numeric', 'min:0.01'],
            'fraction_unit'  => ['required', 'string', 'max:20'],
            'fraction_qty'   => ['required', 'numeric', 'min:1'],
        ]);

        $product = Product::findOrFail($d['product_id']);

        if ((float) $product->stock < $d['quantity']) {
            return response()->json([
                'error' => "Stock insuficiente. Disponible: {$product->stock} {$product->unidad_base}",
            ], 422);
        }

        $totalFraction = $d['quantity'] * $d['fraction_qty'];

        return DB::transaction(function () use ($d, $product, $totalFraction) {
            // Egreso del producto padre
            app(RegisterInventoryMovement::class)->handle(
                $product,
                'egreso',
                $d['quantity'],
                (float) $product->costo_promedio,
                "Fraccionamiento: {$d['quantity']} {$product->unidad_base} → {$totalFraction} {$d['fraction_unit']}",
                now()->toDateString()
            );

            return response()->json([
                'ok'              => true,
                'producto'        => $product->codigo,
                'unidad_origen'   => $product->unidad_base ?? 'UND',
                'cantidad_origen' => $d['quantity'],
                'unidad_destino'  => $d['fraction_unit'],
                'cantidad_destino'=> $totalFraction],
            );
        });
    }
}

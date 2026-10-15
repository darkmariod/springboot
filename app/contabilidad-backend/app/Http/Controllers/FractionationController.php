<?php
namespace App\Http\Controllers;

use App\Models\StockTransformation;
use App\Support\TransformacionesStock;
use App\Support\TransformacionInvalida;
use Illuminate\Http\Request;

class FractionationController extends Controller
{
    /**
     * Fraccionar = abrir una presentación grande y vender sus unidades sueltas: el producto PADRE (lo que se compra)
     * sale del inventario y el producto HIJO (lo que se vende) entra con `quantity x units_per_parent` unidades.
     * Ejemplo: 1 caja de $24 → 12 botellas a $2 cada una. El valor del inventario no cambia.
     *
     * Deja un documento FRA-000001 (contador propio, no el secuencial de las facturas) y ningún asiento.
     * 422 en español si es el mismo producto, no hay stock, es un servicio, un combo o maneja series.
     */
    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id'       => ['required', 'exists:companies,id'],
            'product_id'       => ['required', 'integer', 'exists:products,id'],
            'child_product_id' => ['required', 'integer', 'exists:products,id', 'different:product_id'],
            'quantity'         => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'units_per_parent' => ['required', 'numeric', 'min:0.0001', 'max:100000000'],
            'warehouse_id'     => ['nullable', 'integer', 'exists:warehouses,id'],
            'motivo'           => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $resultado = TransformacionesStock::ejecutar(
                (int) $d['company_id'],
                StockTransformation::FRACCIONAMIENTO,
                (int) $d['product_id'],
                (int) $d['child_product_id'],
                (float) $d['quantity'],
                (float) $d['quantity'] * (float) $d['units_per_parent'],
                isset($d['warehouse_id']) ? (int) $d['warehouse_id'] : null,
                $d['motivo'] ?? null,
            );
        } catch (TransformacionInvalida $e) {
            return response()->json(['message' => $e->getMessage()] + $e->datos, 422);
        }

        return response()->json(TransformacionesStock::respuesta($resultado), 201);
    }
}

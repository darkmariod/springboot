<?php
namespace App\Http\Controllers;

use App\Models\StockTransformation;
use App\Support\TransformacionesStock;
use App\Support\TransformacionInvalida;
use Illuminate\Http\Request;

class ArticleConversionController extends Controller
{
    /**
     * Convertir = un producto pasa a ser otro (un kit se desarma en piezas, un código mal registrado se corrige).
     * El producto de origen sale a su costo promedio y el de destino entra con ese mismo valor total repartido entre la
     * cantidad resultante: el valor del inventario no cambia. Para revertir se hace la conversión al revés.
     *
     * Deja un documento CONV-000001 (contador propio) y ningún asiento: antes creaba un asiento vacío en borrador y
     * gastaba el secuencial de la empresa, que es el de las facturas.
     */
    public function store(Request $r)
    {
        $d = $r->validate([
            'company_id'      => ['required', 'exists:companies,id'],
            'product_from_id' => ['required', 'integer', 'exists:products,id'],
            'product_to_id'   => ['required', 'integer', 'exists:products,id', 'different:product_from_id'],
            'quantity_from'   => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'quantity_to'     => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'warehouse_id'    => ['nullable', 'integer', 'exists:warehouses,id'],
            'motivo'          => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $resultado = TransformacionesStock::ejecutar(
                (int) $d['company_id'],
                StockTransformation::CONVERSION,
                (int) $d['product_from_id'],
                (int) $d['product_to_id'],
                (float) $d['quantity_from'],
                (float) $d['quantity_to'],
                isset($d['warehouse_id']) ? (int) $d['warehouse_id'] : null,
                $d['motivo'] ?? null,
            );
        } catch (TransformacionInvalida $e) {
            return response()->json(['message' => $e->getMessage()] + $e->datos, 422);
        }

        return response()->json(TransformacionesStock::respuesta($resultado), 201);
    }
}

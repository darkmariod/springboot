<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\RegisterInventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Transacciones de inventario: Ajuste por conteo y Transferencia entre bodegas.
 */
class InventoryTransactionController extends Controller
{
    /**
     * Ajuste de inventario: corregir stock por conteo físico.
     * El sistema calcula la diferencia y registra el movimiento.
     */
    public function ajuste(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'stock_fisico' => ['required', 'numeric', 'min:0'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $product = Product::find($d['product_id']);
        $stockActual = (float)$product->stock;

        // Buscar stock en la bodega específica
        $ws = WarehouseStock::where('warehouse_id', $d['warehouse_id'])
            ->where('product_id', $d['product_id'])->first();
        $stockBodega = $ws ? (float)$ws->stock : 0;

        $diferencia = round($d['stock_fisico'] - $stockBodega, 2);

        if ($diferencia == 0) {
            return response()->json(['mensaje' => 'El stock físico coincide con el registrado. No hay ajuste.']);
        }

        $tipo = $diferencia > 0 ? 'ingreso' : 'egreso';
        $cant = abs($diferencia);

        $movimiento = app(RegisterInventoryMovement::class)->handle(
            $product,
            $tipo,
            $cant,
            (float)$product->costo_promedio,
            'Ajuste inventario: ' . $d['motivo'],
            now()->toDateString(),
            $d['warehouse_id']
        );

        return response()->json([
            'ok' => true,
            'producto' => $product->codigo,
            'stock_anterior' => $stockBodega,
            'stock_fisico' => $d['stock_fisico'],
            'diferencia' => $diferencia,
            'tipo_movimiento' => $tipo,
            'cantidad' => $cant,
            'movimiento_id' => $movimiento->id,
        ]);
    }

    /**
     * Transferencia de mercadería entre bodegas.
     * Baja stock en origen y sube en destino.
     */
    public function transferencia(Request $r)
    {
        $d = $r->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_origen_id' => ['required', 'exists:warehouses,id'],
            'warehouse_destino_id' => ['required', 'exists:warehouses,id', 'different:warehouse_origen_id'],
            'cantidad' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::find($d['product_id']);

        // Verificar stock en origen
        $wsOrigen = WarehouseStock::where('warehouse_id', $d['warehouse_origen_id'])
            ->where('product_id', $d['product_id'])->first();

        if (!$wsOrigen || (float)$wsOrigen->stock < $d['cantidad']) {
            return response()->json([
                'error' => 'Stock insuficiente en bodega origen. Disponible: ' . ($wsOrigen ? $wsOrigen->stock : 0),
            ], 422);
        }

        return DB::transaction(function () use ($d, $product, $wsOrigen) {
            // Egreso de origen
            $movOrigen = app(RegisterInventoryMovement::class)->handle(
                $product, 'egreso', $d['cantidad'], (float)$product->costo_promedio,
                'Transferencia salida: ' . ($d['motivo'] ?? ''),
                now()->toDateString(), $d['warehouse_origen_id']
            );

            // Ingreso en destino
            $movDestino = app(RegisterInventoryMovement::class)->handle(
                $product, 'ingreso', $d['cantidad'], (float)$product->costo_promedio,
                'Transferencia entrada: ' . ($d['motivo'] ?? ''),
                now()->toDateString(), $d['warehouse_destino_id']
            );

            $origen = Warehouse::find($d['warehouse_origen_id']);
            $destino = Warehouse::find($d['warehouse_destino_id']);

            return response()->json([
                'ok' => true,
                'producto' => $product->codigo,
                'cantidad' => $d['cantidad'],
                'origen' => $origen->nombre,
                'destino' => $destino->nombre,
                'movimiento_salida' => $movOrigen->id,
                'movimiento_entrada' => $movDestino->id,
            ]);
        });
    }

    /**
     * Kardex por bodega: movimientos de un producto en una bodega específica.
     */
    public function kardexBodega(Request $r, Product $product)
    {
        $r->validate(['warehouse_id' => ['required', 'exists:warehouses,id']]);

        $movimientos = \App\Models\InventoryMovement::where('product_id', $product->id)
            ->where('warehouse_id', $r->warehouse_id)
            ->orderBy('fecha')->orderBy('id')->get();

        return [
            'producto' => ['codigo' => $product->codigo, 'descripcion' => $product->descripcion],
            'bodega_id' => $r->warehouse_id,
            'movimientos' => $movimientos,
        ];
    }
}

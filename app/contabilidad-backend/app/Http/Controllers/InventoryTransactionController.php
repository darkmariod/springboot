<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\GenerateAdjustmentJournalEntry;
use App\Services\RegisterInventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Transacciones de inventario: Ajuste por conteo y Transferencia entre bodegas.
 */
class InventoryTransactionController extends Controller
{
    /**
     * Stock de un producto en una bodega (el mismo número contra el que el ajuste calcula su diferencia)
     * y si el producto maneja series: la pantalla de ajuste lo necesita para pedir las series correctas.
     */
    public function stockBodega(Request $r)
    {
        $d = $r->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
        ]);

        $ws = WarehouseStock::where('warehouse_id', $d['warehouse_id'])->where('product_id', $d['product_id'])->first();

        return [
            'stock_bodega' => $ws ? (float) $ws->stock : 0,
            'maneja_series' => (bool) Product::whereKey($d['product_id'])->value('maneja_series'),
        ];
    }

    /**
     * Ajuste de inventario: corregir stock por conteo físico.
     * El sistema calcula la diferencia y registra el movimiento.
     *
     * Producto con series: se envían en `series`.
     *   Faltante (egreso)  -> exactamente tantas series DISPONIBLES como unidades faltan; se dan de baja.
     *   Sobrante (ingreso) -> exactamente tantas series NUEVAS como unidades sobran; se crean.
     * Así el stock y las series disponibles nunca se separan. Si no cuadran, 422 con el motivo.
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

        $series = [];
        if ($product->maneja_series) {
            try {
                $series = $this->validarSeriesDelAjuste($product, $tipo, $cant, $r->input('series'));
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Movimiento, series y asiento van en la misma transacción: si el asiento falla,
        // el kárdex no se mueve solo — es justo lo que la contadora pidió evitar.
        try {
            [$movimiento, $asiento] = DB::transaction(function () use ($product, $tipo, $cant, $d, $series) {
                $servicio = app(RegisterInventoryMovement::class);
                $mov = $servicio->handle(
                    $product,
                    $tipo,
                    $cant,
                    (float)$product->costo_promedio,
                    'Ajuste inventario: ' . $d['motivo'],
                    now()->toDateString(),
                    $d['warehouse_id'],
                    // Faltante: las series salen del inventario como dañadas (no se vendieron)
                    $tipo === 'egreso' ? $series : [],
                    null,
                    null,
                    'danado'
                );
                // Sobrante: las series nuevas se crean junto con el ingreso
                if ($tipo === 'ingreso' && $series) {
                    $servicio->registrarSeriesNuevas($product, $series);
                }

                $as = app(GenerateAdjustmentJournalEntry::class)->handle($mov);

                return [$mov, $as];
            });
        } catch (\RuntimeException $e) {
            // Stock insuficiente, serie tomada por otra operación, etc.: un aviso, no un error 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'producto' => $product->codigo,
            'stock_anterior' => $stockBodega,
            'stock_fisico' => $d['stock_fisico'],
            'diferencia' => $diferencia,
            'tipo_movimiento' => $tipo,
            'cantidad' => $cant,
            'movimiento_id' => $movimiento->id,
            'asiento' => $asiento->numero,
        ]);
    }

    /**
     * Series que acompañan un ajuste de producto con series. Devuelve la lista limpia o lanza
     * InvalidArgumentException con el motivo, en español, para devolverlo como 422.
     */
    private function validarSeriesDelAjuste(Product $product, string $tipo, float $cant, mixed $enviadas): array
    {
        if (abs($cant - round($cant)) > 0.0005) {
            throw new \InvalidArgumentException("El producto {$product->codigo} maneja series: la diferencia debe ser un número entero de unidades.");
        }
        $n = (int) round($cant);

        $series = collect(is_array($enviadas) ? $enviadas : [])
            ->map(fn ($s) => trim((string) $s))->filter(fn ($s) => $s !== '')->values();

        if ($series->count() !== $n) {
            $que = $tipo === 'egreso' ? 'disponibles para dar de baja' : 'nuevas para el ingreso';
            throw new \InvalidArgumentException(
                "El producto {$product->codigo} maneja series: indica exactamente {$n} series {$que} (se indicaron {$series->count()})."
            );
        }

        $repetidas = $series->duplicates()->unique()->values();
        if ($repetidas->isNotEmpty()) {
            throw new \InvalidArgumentException('Hay series repetidas en la lista: '.$repetidas->implode(', ').'.');
        }

        if ($tipo === 'egreso') {
            foreach ($series as $serie) {
                $disponible = ProductSerie::where('company_id', $product->company_id)->where('product_id', $product->id)
                    ->where('serie', $serie)->where('estado', 'disponible')->exists();
                if (! $disponible) {
                    throw new \InvalidArgumentException("La serie {$serie} no existe o no está disponible para este producto.");
                }
            }
        } else {
            $existentes = ProductSerie::where('company_id', $product->company_id)->whereIn('serie', $series->all())->pluck('serie');
            if ($existentes->isNotEmpty()) {
                throw new \InvalidArgumentException('Estas series ya existen en el sistema: '.$existentes->implode(', ').'.');
            }
        }

        return $series->all();
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

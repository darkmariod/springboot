<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

class RegisterInventoryMovement
{
    public function handle(Product $p, string $tipo, float $cant, float $costo, string $concepto, string $fecha, ?int $warehouseId = null, array $series = [], ?int $invoiceId = null): InventoryMovement
    {
        if (! in_array($tipo, ['ingreso', 'egreso'], true)) {
            throw new \InvalidArgumentException("Tipo de movimiento inválido: {$tipo}");
        }

        return DB::transaction(function () use ($p, $tipo, $cant, $costo, $concepto, $fecha, $warehouseId, $series, $invoiceId) {
            $p = Product::whereKey($p->id)->lockForUpdate()->firstOrFail();

            if (in_array($tipo, ['egreso', 'salida'], true)) {
                $stockActual = (float) $p->stock;
                if ($stockActual < $cant) {
                    throw new \InvalidArgumentException(
                        "Stock insuficiente para {$p->codigo}: disponible {$stockActual}, solicitado {$cant}"
                    );
                }
            }

            $cantPrev = (float) $p->stock;
            $promPrev = (float) $p->costo_promedio;

            if ($tipo === 'ingreso') {
                $nuevoValor = round($cantPrev * $promPrev + $cant * $costo, 2);
                $nuevaCant = round($cantPrev + $cant, 2);
                $nuevoProm = $nuevaCant > 0 ? round($nuevoValor / $nuevaCant, 4) : 0;
            } else {
                if (round($cantPrev - $cant, 2) < 0) {
                    throw new \RuntimeException("Stock insuficiente de {$p->codigo}: hay {$cantPrev}, se piden {$cant}.");
                }
                $costo = $promPrev;
                $nuevaCant = round($cantPrev - $cant, 2);
                $nuevoProm = $promPrev;
                $nuevoValor = round($nuevaCant * $nuevoProm, 2);
            }

            if ($p->maneja_series) {
                if ($tipo === 'egreso') {
                    if (empty($series)) {
                        throw new \RuntimeException("El producto {$p->codigo} maneja series; indique las series a vender.");
                    }
                    foreach ($series as $serie) {
                        $existe = ProductSerie::where('company_id', $p->company_id)
                            ->where('product_id', $p->id)
                            ->where('serie', trim($serie))
                            ->where('estado', 'disponible')
                            ->first();
                        if (! $existe) {
                            throw new \RuntimeException("Serie {$serie} no existe o ya fue vendida.");
                        }
                    }
                    foreach ($series as $serie) {
                        ProductSerie::where('company_id', $p->company_id)
                            ->where('product_id', $p->id)
                            ->where('serie', trim($serie))
                            ->where('estado', 'disponible')
                            ->update(['estado' => 'vendida', 'invoice_id' => $invoiceId]);
                    }
                } elseif (! empty($series)) {
                    foreach ($series as $serie) {
                        ProductSerie::where('company_id', $p->company_id)
                            ->where('product_id', $p->id)
                            ->where('serie', trim($serie))
                            ->update(['estado' => 'disponible', 'invoice_id' => null]);
                    }
                }
            }

            $mov = InventoryMovement::create([
                'company_id' => $p->company_id,
                'product_id' => $p->id,
                'fecha' => $fecha,
                'tipo' => $tipo,
                'concepto' => $concepto,
                'cantidad' => $cant,
                'costo_unitario' => round($costo, 4),
                'saldo_cantidad' => $nuevaCant,
                'saldo_costo_promedio' => $nuevoProm,
                'saldo_valor' => $nuevoValor,
                'warehouse_id' => $warehouseId,
            ]);

            $p->update(['stock' => $nuevaCant, 'costo_promedio' => $nuevoProm]);

            if ($warehouseId) {
                $ws = WarehouseStock::firstOrCreate(
                    ['warehouse_id' => $warehouseId, 'product_id' => $p->id],
                    ['stock' => 0]
                );
                $ws->update(['stock' => round($tipo === 'egreso' ? (float) $ws->stock - $cant : (float) $ws->stock + $cant, 2)]);
            }

            return $mov;
        });
    }
}

<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

/**
 * Único punto por donde se mueven las existencias.
 *
 * El kárdex se reconstruye siempre en orden cronológico (fecha, luego id).
 * Eso importa porque una factura de compra puede llegar con días de retraso:
 * si el saldo se calculara con el stock del momento de la carga, la columna
 * de saldos quedaría descuadrada aunque el stock final fuera correcto.
 */
class RegisterInventoryMovement
{
    /** Decimales fijos en todo el módulo: mezclarlos hace que el saldo se desvíe. */
    public const DEC_CANTIDAD = 4;   // permite kilos, metros, litros
    public const DEC_COSTO    = 4;
    public const DEC_VALOR    = 2;

    public function handle(
        Product $p,
        string $tipo,
        float $cant,
        float $costo,
        string $concepto,
        string $fecha,
        ?int $warehouseId = null,
        array $series = [],
        ?int $invoiceId = null
    ): InventoryMovement {
        if (! in_array($tipo, ['ingreso', 'egreso'], true)) {
            throw new \InvalidArgumentException("Tipo de movimiento inválido: {$tipo}");
        }

        $cant = round($cant, self::DEC_CANTIDAD);
        if ($cant <= 0) {
            throw new \InvalidArgumentException('La cantidad del movimiento debe ser mayor a cero.');
        }

        // Sin bodega explícita se usa la de la empresa. Antes, las ventas no la
        // informaban: descontaban del stock total pero no del stock por bodega.
        $warehouseId = $warehouseId ?: \App\Models\Warehouse::where('company_id', $p->company_id)
            ->orderByDesc('por_defecto')->value('id');

        return $this->conReintentos(fn () => DB::transaction(function () use ($p, $tipo, $cant, $costo, $concepto, $fecha, $warehouseId, $series, $invoiceId) {
            $p = Product::whereKey($p->id)->lockForUpdate()->firstOrFail();

            if ($tipo === 'egreso' && round((float) $p->stock - $cant, self::DEC_CANTIDAD) < 0) {
                throw new \RuntimeException(
                    "Stock insuficiente de {$p->codigo}: hay {$p->stock}, se piden {$cant}."
                );
            }

            $this->moverSeries($p, $tipo, $series, $invoiceId);

            // Los saldos se escriben en la reconstrucción de abajo.
            $mov = InventoryMovement::create([
                'company_id'           => $p->company_id,
                'product_id'           => $p->id,
                'fecha'                => $fecha,
                'tipo'                 => $tipo,
                'concepto'             => $concepto,
                'cantidad'             => $cant,
                'costo_unitario'       => round($costo, self::DEC_COSTO),
                'saldo_cantidad'       => 0,
                'saldo_costo_promedio' => 0,
                'saldo_valor'          => 0,
                'warehouse_id'         => $warehouseId,
                'invoice_id'           => $invoiceId,
            ]);

            // reconstruirKardex también rehace el stock por bodega desde el kárdex,
            // así que acá no se vuelve a sumar el delta.
            $this->reconstruirKardex($p);

            return $mov->fresh();
        }));
    }

    /**
     * Dos cajas facturando al mismo tiempo chocan contra el bloqueo de SQLite,
     * que responde al instante en vez de esperar. Sin esto la segunda venta se
     * pierde, así que se reintenta con una espera que va creciendo.
     */
    private function conReintentos(callable $fn, int $intentos = 8)
    {
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (\Illuminate\Database\QueryException $e) {
                if ($i >= $intentos || ! str_contains($e->getMessage(), 'database is locked')) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000) * $i);
            }
        }
    }

    /**
     * Recorre todos los movimientos del producto en orden de fecha y reescribe
     * los saldos. Deja el stock y el costo promedio del producto en el último.
     */
    public function reconstruirKardex(Product $p): void
    {
        $movs = InventoryMovement::where('product_id', $p->id)
            ->orderBy('fecha')->orderBy('id')->get();

        $cant = 0.0;
        $valor = 0.0;
        $prom = 0.0;

        foreach ($movs as $m) {
            if ($m->tipo === 'ingreso') {
                $valor = round($valor + (float) $m->cantidad * (float) $m->costo_unitario, self::DEC_VALOR);
                $cant  = round($cant + (float) $m->cantidad, self::DEC_CANTIDAD);
                $prom  = $cant > 0 ? round($valor / $cant, self::DEC_COSTO) : 0.0;
            } else {
                // La salida se valora al promedio vigente en ese punto del kárdex.
                $cant  = round($cant - (float) $m->cantidad, self::DEC_CANTIDAD);
                $valor = round($cant * $prom, self::DEC_VALOR);
                $m->costo_unitario = $prom;
            }

            $m->saldo_cantidad       = $cant;
            $m->saldo_costo_promedio = $prom;
            $m->saldo_valor          = $valor;
            $m->saveQuietly();   // sin auditar: es recálculo del sistema, no un cambio del usuario
        }

        $p->forceFill(['stock' => $cant, 'costo_promedio' => $prom])->saveQuietly();

        $this->reconstruirBodegas($p, $movs);
    }

    /** El stock de cada bodega también sale del kárdex, no de sumas sueltas. */
    private function reconstruirBodegas(Product $p, $movs): void
    {
        $porBodega = [];
        foreach ($movs as $m) {
            if (! $m->warehouse_id) {
                continue;
            }
            $porBodega[$m->warehouse_id] = round(
                ($porBodega[$m->warehouse_id] ?? 0)
                + ($m->tipo === 'egreso' ? -(float) $m->cantidad : (float) $m->cantidad),
                self::DEC_CANTIDAD
            );
        }

        WarehouseStock::where('product_id', $p->id)->delete();
        foreach ($porBodega as $warehouseId => $cantidad) {
            if (abs($cantidad) < 0.0005) {
                continue;
            }
            WarehouseStock::create([
                'warehouse_id' => $warehouseId,
                'product_id'   => $p->id,
                'stock'        => $cantidad,
            ]);
        }
    }

    private function moverSeries(Product $p, string $tipo, array $series, ?int $invoiceId): void
    {
        if (! $p->maneja_series) {
            return;
        }

        if ($tipo === 'egreso') {
            if (empty($series)) {
                throw new \RuntimeException("El producto {$p->codigo} maneja series; indique las series a vender.");
            }
            foreach ($series as $serie) {
                $existe = ProductSerie::where('company_id', $p->company_id)
                    ->where('product_id', $p->id)->where('serie', trim($serie))
                    ->where('estado', 'disponible')->exists();
                if (! $existe) {
                    throw new \RuntimeException("Serie {$serie} no existe o ya fue vendida.");
                }
            }
            foreach ($series as $serie) {
                ProductSerie::where('company_id', $p->company_id)
                    ->where('product_id', $p->id)->where('serie', trim($serie))
                    ->where('estado', 'disponible')
                    ->update(['estado' => 'vendida', 'invoice_id' => $invoiceId]);
            }

            return;
        }

        foreach ($series as $serie) {
            ProductSerie::where('company_id', $p->company_id)
                ->where('product_id', $p->id)->where('serie', trim($serie))
                ->update(['estado' => 'disponible', 'invoice_id' => null]);
        }
    }

}

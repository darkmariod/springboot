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
    use \App\Support\RetryOnLock;

    /** Decimales fijos en todo el módulo: mezclarlos hace que el saldo se desvíe. */
    public const DEC_CANTIDAD = 4;   // permite kilos, metros, litros
    public const DEC_COSTO    = 4;
    public const DEC_VALOR    = 2;

    /**
     * Estado de salida con el que una transferencia entre bodegas retira las series de la bodega de origen: se validan
     * contra esa bodega pero siguen disponibles (el ingreso de destino las cambia de bodega).
     */
    public const SALIDA_TRANSFERENCIA = 'transferencia';

    public function handle(
        Product $p,
        string $tipo,
        float $cant,
        float $costo,
        string $concepto,
        string $fecha,
        ?int $warehouseId = null,
        array $series = [],
        ?int $invoiceId = null,
        ?int $purchaseId = null,
        string $estadoSerieSalida = 'vendida',
        ?\Illuminate\Database\Eloquent\Model $origen = null
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
        $warehouseId = $warehouseId ?: ProductSerie::bodegaPrincipalId($p->company_id);

        return $this->conReintentos(fn () => DB::transaction(function () use ($p, $tipo, $cant, $costo, $concepto, $fecha, $warehouseId, $series, $invoiceId, $purchaseId, $estadoSerieSalida, $origen) {
            $p = Product::whereKey($p->id)->lockForUpdate()->firstOrFail();

            if ($tipo === 'egreso' && round((float) $p->stock - $cant, self::DEC_CANTIDAD) < 0) {
                throw new \RuntimeException(
                    "Stock insuficiente de {$p->codigo}: hay {$p->stock}, se piden {$cant}."
                );
            }

            $this->moverSeries($p, $tipo, $cant, $series, $invoiceId, $estadoSerieSalida, $warehouseId);

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
                'purchase_id'          => $purchaseId,
                // Fraccionamiento / conversión: el documento interno que lo originó
                'origen_type'          => $origen?->getMorphClass(),
                'origen_id'            => $origen?->getKey(),
            ]);

            // reconstruirKardex también rehace el stock por bodega desde el kárdex,
            // así que aquí no se vuelve a sumar el delta.
            $this->reconstruirKardex($p);

            return $mov->fresh();
        }));
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

    /**
     * Da de alta series NUEVAS de un producto que ya entró por el kárdex (una compra, el sobrante de un ajuste). Es el
     * complemento del ingreso: el stock lo mueve handle(), aquí solo se crean las series (en la bodega a la que entró el
     * stock) para que stock y series disponibles no se separen. Si alguna ya existe o se repite, no se crea ninguna.
     */
    public function registrarSeriesNuevas(Product $p, array $series, ?int $purchaseId = null, ?int $warehouseId = null): void
    {
        $series = array_values(array_filter(array_map('trim', $series), fn ($s) => $s !== ''));

        $repetidas = array_keys(array_filter(array_count_values($series), fn ($n) => $n > 1));
        if ($repetidas) {
            throw new \RuntimeException('Hay series repetidas en la lista: '.implode(', ', $repetidas).'.');
        }
        $existentes = ProductSerie::where('company_id', $p->company_id)->whereIn('serie', $series)->pluck('serie')->all();
        if ($existentes) {
            throw new \RuntimeException('Estas series ya existen en el sistema: '.implode(', ', $existentes).'.');
        }
        $warehouseId = $warehouseId ?: ProductSerie::bodegaPrincipalId($p->company_id);
        foreach ($series as $serie) {
            ProductSerie::create([
                'company_id'   => $p->company_id,
                'product_id'   => $p->id,
                'serie'        => $serie,
                'estado'       => 'disponible',
                'purchase_id'  => $purchaseId,
                'warehouse_id' => $warehouseId,
            ]);
        }
    }

    /**
     * Series de un movimiento de un producto que las maneja.
     *
     * Bodega de la serie (warehouse_id): donde está la unidad; si ya salió (vendida, dañada) queda la bodega de la que salió.
     * Una serie anterior sin bodega cuenta como de la bodega principal (ver ProductSerie::scopeEnBodega).
     *
     * Egreso: tantas series DISPONIBLES de esa bodega como unidades salen. Por defecto se marcan 'vendida' (con la factura);
     *   un ajuste las da de baja con otro estado ('danado') porque no se vendieron; una transferencia solo las valida.
     * Ingreso: las series que regresan (devolución, anulación) vuelven a estar disponibles, en la bodega del ingreso.
     */
    private function moverSeries(Product $p, string $tipo, float $cant, array $series, ?int $invoiceId, string $estadoSalida, ?int $warehouseId): void
    {
        if (! $p->maneja_series) {
            return;
        }
        $series = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $series), fn ($s) => $s !== ''));

        if ($tipo === 'egreso') {
            if (empty($series)) {
                throw new \RuntimeException("El producto {$p->codigo} maneja series; indique las series a vender.");
            }
            if (abs($cant - round($cant)) > 0.0005 || count($series) !== (int) round($cant)) {
                throw new \RuntimeException("El producto {$p->codigo} maneja series: salen {$cant} unidades y se indicaron ".count($series).' series; deben coincidir.');
            }
            $repetidas = array_keys(array_filter(array_count_values($series), fn ($n) => $n > 1));
            if ($repetidas) {
                throw new \RuntimeException('Hay series repetidas en la lista: '.implode(', ', $repetidas).'.');
            }
            foreach ($series as $serie) {
                $fila = ProductSerie::where('company_id', $p->company_id)
                    ->where('product_id', $p->id)->where('serie', $serie)
                    ->where('estado', 'disponible')->first();
                if (! $fila) {
                    throw new \RuntimeException("Serie {$serie} no existe o ya fue vendida.");
                }
                if ($motivo = $fila->motivoFueraDeBodega($warehouseId)) {
                    throw new \RuntimeException($motivo);
                }
            }
            if ($estadoSalida === self::SALIDA_TRANSFERENCIA) {
                return;
            }
            ProductSerie::where('company_id', $p->company_id)->where('product_id', $p->id)
                ->whereIn('serie', $series)->where('estado', 'disponible')
                ->update(['estado' => $estadoSalida, 'invoice_id' => $invoiceId, 'credit_note_id' => null, 'warehouse_id' => $warehouseId]);

            return;
        }

        if ($series) {
            ProductSerie::where('company_id', $p->company_id)->where('product_id', $p->id)
                ->whereIn('serie', $series)
                ->update(['estado' => 'disponible', 'invoice_id' => null, 'warehouse_id' => $warehouseId]);
        }
    }

}

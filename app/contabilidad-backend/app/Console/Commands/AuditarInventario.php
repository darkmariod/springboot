<?php

namespace App\Console\Commands;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSerie;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\RegisterInventoryMovement;
use Illuminate\Console\Command;

/**
 * Revisa que el kárdex cuadre. Se corre antes de una revisión contable o
 * cuando el inventario físico no coincide con el sistema.
 */
class AuditarInventario extends Command
{
    protected $signature = 'inventario:auditar
                            {--empresa= : Auditar solo una empresa}
                            {--corregir : Reconstruir el kárdex de lo que esté descuadrado}';

    protected $description = 'Revisa que el kárdex, el stock y las bodegas cuadren entre sí';

    public function handle(RegisterInventoryMovement $servicio): int
    {
        $dec = RegisterInventoryMovement::DEC_CANTIDAD;
        $productos = Product::when($this->option('empresa'), fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('codigo')->get();

        $problemas = [];

        foreach ($productos as $p) {
            $movs = InventoryMovement::where('product_id', $p->id)
                ->orderBy('fecha')->orderBy('id')->get();
            if ($movs->isEmpty()) {
                continue;
            }

            // 1. El saldo de cada línea debe seguir el orden cronológico
            $acum = 0.0;
            $lineasMal = 0;
            foreach ($movs as $m) {
                $acum = round($acum + ($m->tipo === 'egreso' ? -(float) $m->cantidad : (float) $m->cantidad), $dec);
                if (abs($acum - (float) $m->saldo_cantidad) > 0.0005) {
                    $lineasMal++;
                }
            }
            if ($lineasMal) {
                $problemas[] = [$p, "$lineasMal líneas del kárdex con saldo incorrecto"];
            }

            // 2. El stock del producto debe ser el saldo de la última línea
            if (abs((float) $p->stock - $acum) > 0.0005) {
                $problemas[] = [$p, "el stock dice {$p->stock} y el kárdex termina en {$acum}"];
            }

            // 3. La suma de las bodegas debe dar el stock total
            if (WarehouseStock::where('product_id', $p->id)->exists()) {
                $bodegas = round((float) WarehouseStock::where('product_id', $p->id)->sum('stock'), $dec);
                if (abs($bodegas - (float) $p->stock) > 0.0005) {
                    $problemas[] = [$p, "las bodegas suman {$bodegas} y el stock es {$p->stock}"];
                }
            }

            // 4. Nada en negativo
            if ((float) $p->stock < 0) {
                $problemas[] = [$p, "stock negativo: {$p->stock}"];
            }

            // 6. Movimientos viejos sin bodega: descuadran el stock por bodega
            $sinBodega = $movs->whereNull('warehouse_id')->count();
            if ($sinBodega) {
                $problemas[] = [$p, "{$sinBodega} movimientos sin bodega asignada"];
            }

            // 5. Un producto con series debe tener, en CADA bodega, tantas series disponibles como unidades de stock
            if ($p->maneja_series) {
                foreach ($this->seriesContraStockPorBodega($p) as $detalle) {
                    $problemas[] = [$p, $detalle];
                }
            }
        }

        $this->newLine();
        $this->line('  Productos revisados: '.$productos->count());

        if (empty($problemas)) {
            $this->newLine();
            $this->info('  TODO OK — el kárdex cuadra con el stock y las bodegas.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('  Se encontraron '.count($problemas).' problemas:');
        foreach ($problemas as [$p, $detalle]) {
            $this->line("    {$p->codigo}  {$detalle}");
        }

        if (! $this->option('corregir')) {
            $this->newLine();
            $this->line('  Para reconstruir el kárdex: php artisan inventario:auditar --corregir');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Reconstruyendo el kárdex en orden cronológico...');
        foreach (collect($problemas)->pluck(0)->unique('id') as $p) {
            // Los movimientos anteriores al manejo de bodegas van a la bodega por defecto
            $huerfanos = InventoryMovement::where('product_id', $p->id)->whereNull('warehouse_id');
            if ($huerfanos->exists()) {
                $bodega = \App\Models\Warehouse::where('company_id', $p->company_id)
                    ->orderByDesc('por_defecto')->value('id');
                if ($bodega) {
                    $n = $huerfanos->update(['warehouse_id' => $bodega]);
                    $this->line("    {$p->codigo}: {$n} movimientos asignados a la bodega por defecto");
                }
            }

            $servicio->reconstruirKardex($p);
            $this->line("    {$p->codigo} reconstruido");
        }

        $this->newLine();
        $this->info('  Listo. Vuelve a correr el comando sin --corregir para confirmar.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Series disponibles contra el stock de cada bodega (no contra el stock total: un total que cuadra puede esconder una
     * serie anotada en otra bodega). Una serie sin bodega (de antes de las bodegas en las series) cuenta como de la
     * bodega principal, y el stock anterior a las bodegas (movimientos sin bodega) también está en la principal: la misma
     * regla de ProductSerie::bodegaPrincipalId y de SerieController::store. Devuelve una línea por cada bodega que no cuadra.
     *
     * @return list<string>
     */
    private function seriesContraStockPorBodega(Product $p): array
    {
        $principal = ProductSerie::bodegaPrincipalId((int) $p->company_id);
        $stock = WarehouseStock::where('product_id', $p->id)->pluck('stock', 'warehouse_id')->map(fn ($s) => (float) $s)->all();
        if ($principal) {
            $sinBodega = max(0.0, (float) $p->stock - array_sum($stock));
            $stock[$principal] = ($stock[$principal] ?? 0.0) + $sinBodega;
        }

        $series = [];
        foreach (ProductSerie::where('product_id', $p->id)->where('estado', 'disponible')->get(['warehouse_id']) as $s) {
            $bodega = $s->warehouse_id ?: $principal;
            $series[$bodega] = ($series[$bodega] ?? 0) + 1;
        }

        $bodegas = Warehouse::whereIn('id', array_unique(array_merge(array_keys($stock), array_keys($series))))->get(['id', 'codigo', 'nombre'])->keyBy('id');
        $lineas = [];
        foreach ($bodegas->sortBy('codigo') as $id => $bodega) {
            $disponibles = $series[$id] ?? 0;
            $unidades = round($stock[$id] ?? 0.0, RegisterInventoryMovement::DEC_CANTIDAD);
            if (abs($disponibles - $unidades) > 0.0005) {
                $lineas[] = "bodega {$bodega->codigo} ({$bodega->nombre}): hay {$disponibles} series disponibles y el stock de esa bodega dice "
                    .rtrim(rtrim(number_format($unidades, RegisterInventoryMovement::DEC_CANTIDAD, '.', ''), '0'), '.');
            }
        }

        return $lineas;
    }
}

<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductSerie;

/**
 * Las series de una venta, revisadas ANTES de emitir: la factura se firma y se envía al SRI dentro de la transacción y lo que el
 * SRI ya recibió no se deshace con un rollback, así que un error de series tiene que salir antes.
 * Un producto con series vende exactamente una serie por unidad, y cada serie tiene que estar disponible en la bodega de la
 * línea (la de la línea o, si no trae, la principal; una serie sin bodega cuenta como de la principal).
 */
final class SeriesDeVenta
{
    /** @throws \RuntimeException con el motivo en español (la factura responde 422) */
    public static function validar(int $companyId, array $items): void
    {
        $principal = ProductSerie::bodegaPrincipalId($companyId);
        $vistas = [];

        foreach ($items as $it) {
            $codigo = trim((string) ($it['codigo_principal'] ?? ''));
            $cant = (float) ($it['cantidad'] ?? 0);
            if ($codigo === '' || $cant <= 0) {
                continue;
            }
            $p = Product::where('company_id', $companyId)->where('codigo', $codigo)->first();
            if (! $p || ! $p->maneja_series || $p->tipo === 'servicio' || $p->es_combo) {
                continue;
            }

            $series = SeriesDeCompra::limpiar($it['series'] ?? []);
            if (! $series) {
                throw new \RuntimeException("El producto {$codigo} maneja series; indique las series a vender.");
            }
            if (abs($cant - round($cant)) > 0.0005) {
                throw new \RuntimeException("El producto {$codigo} maneja series: la cantidad debe ser un número entero de unidades.");
            }
            $n = (int) round($cant);
            if (count($series) !== $n) {
                throw new \RuntimeException("El producto {$codigo} maneja series: la cantidad ({$n}) debe coincidir con las series indicadas (".count($series).').');
            }

            $propias = array_keys(array_filter(array_count_values($series), fn ($c) => $c > 1));
            $otras = array_values(array_intersect($series, $vistas));
            $repetidas = array_values(array_unique(array_merge($propias, $otras)));
            if ($repetidas) {
                throw new \RuntimeException('Hay series repetidas en la lista: '.implode(', ', $repetidas).'.');
            }
            array_push($vistas, ...$series);

            $bodega = ($it['warehouse_id'] ?? null) ? (int) $it['warehouse_id'] : $principal;
            foreach ($series as $serie) {
                $fila = ProductSerie::where('company_id', $companyId)->where('product_id', $p->id)
                    ->where('serie', $serie)->where('estado', 'disponible')->first();
                if (! $fila) {
                    throw new \RuntimeException("Serie {$serie} no existe o ya fue vendida.");
                }
                if ($motivo = $fila->motivoFueraDeBodega($bodega)) {
                    throw new \RuntimeException($motivo);
                }
            }
        }
    }
}

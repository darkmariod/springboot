<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductSerie;
use Illuminate\Validation\ValidationException;

/**
 * Las series de una compra. Un producto que maneja series entra por la compra con una serie por unidad: el stock nunca sube
 * sin sus series. Se valida TODO antes de escribir nada.
 *  - validarManual(): la compra a mano trae las series dentro de cada línea (`items.*.series`).
 *  - repartirDelXml(): la factura XML del proveedor no trae series; llegan aparte como un mapa código de producto => series.
 * Las reglas de siempre (número de serie único por empresa, una serie por unidad, enteros) viven aquí y las comparten los dos caminos.
 */
final class SeriesDeCompra
{
    /** Lo que llegó como series (lista, o texto con una serie por línea) -> lista limpia, sin espacios ni vacíos. */
    public static function limpiar(mixed $series): array
    {
        if (is_string($series)) {
            $series = preg_split('/[\r\n,;]+/', $series) ?: [];
        }
        if (! is_array($series)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $series), fn ($s) => $s !== ''));
    }

    /** El mapa código => series que manda la pantalla de importación: llega como arreglo o como texto JSON. */
    public static function mapa(mixed $crudo): array
    {
        if (is_string($crudo)) {
            $crudo = json_decode($crudo, true);
        }
        if (! is_array($crudo)) {
            return [];
        }
        $mapa = [];
        foreach ($crudo as $codigo => $series) {
            $mapa[trim((string) $codigo)] = self::limpiar($series);
        }

        return $mapa;
    }

    /**
     * Compra manual: cada línea de un producto con series trae exactamente tantas series como unidades; una línea de un producto
     * que no las maneja no trae ninguna. Lanza ValidationException (campo `series`) con el motivo en español.
     */
    public static function validarManual(int $companyId, array $items): void
    {
        $errores = [];
        $todas = [];
        $memo = [];

        foreach ($items as $it) {
            $codigo = trim((string) ($it['codigo_principal'] ?? ''));
            $cant = (float) ($it['cantidad'] ?? 0);
            if ($codigo === '' || $cant <= 0) {
                continue;   // no entra al inventario
            }
            $series = self::limpiar($it['series'] ?? []);
            $producto = self::producto($companyId, $codigo, $memo);

            if (! self::manejaSeries($producto)) {
                if ($series) {
                    $errores[] = "El producto {$codigo} no maneja series: quita las series de esa línea o activa «Aplica Serie» en el artículo.";
                }
                continue;
            }
            if (abs($cant - round($cant)) > 0.0005) {
                $errores[] = "El producto {$codigo} maneja series: la cantidad debe ser un número entero de unidades.";
                continue;
            }
            $n = (int) round($cant);
            if (count($series) !== $n) {
                $errores[] = "El producto {$codigo} maneja series: indica exactamente {$n} series, una por unidad (se indicaron ".count($series).').';
                continue;
            }
            array_push($todas, ...$series);
        }
        if ($errores) {
            throw ValidationException::withMessages(['series' => $errores]);
        }

        self::exigirSeriesNuevas($companyId, $todas);
    }

    /**
     * Factura XML: devuelve las líneas con sus `series` repartidas en orden (el mismo producto en dos líneas toma las
     * primeras para la primera línea, y así). Si falta alguna serie lanza SeriesRequeridas con las líneas que las necesitan;
     * si hay repetidas o ya existen, ValidationException. Los productos sin series no cambian.
     */
    public static function repartirDelXml(int $companyId, array $items, array $series): array
    {
        $lineas = [];
        $indices = [];
        $memo = [];
        foreach ($items as $i => $it) {
            $codigo = trim((string) ($it['codigo_principal'] ?? ''));
            $cant = (float) ($it['cantidad'] ?? 0);
            if ($codigo === '' || $cant <= 0) {
                continue;
            }
            $producto = self::producto($companyId, $codigo, $memo);
            if (! self::manejaSeries($producto)) {
                continue;
            }
            $lineas[$codigo] ??= ['codigo' => $codigo, 'descripcion' => $producto->descripcion, 'product_id' => $producto->id, 'cantidad' => 0.0, 'series_recibidas' => 0];
            $lineas[$codigo]['cantidad'] += $cant;
            $indices[$codigo][] = $i;
        }
        if (! $lineas) {
            return $items;
        }

        $errores = [];
        $dadas = [];
        $incompleto = false;
        foreach ($lineas as $codigo => &$l) {
            if (abs($l['cantidad'] - round($l['cantidad'])) > 0.0005) {
                $errores[] = "El producto {$codigo} maneja series: la cantidad de la factura ({$l['cantidad']}) no es un número entero de unidades.";
                continue;
            }
            $l['cantidad'] = (int) round($l['cantidad']);
            $dadas[$codigo] = self::limpiar($series[$codigo] ?? []);
            $l['series_recibidas'] = count($dadas[$codigo]);
            $incompleto = $incompleto || $l['series_recibidas'] !== $l['cantidad'];
        }
        unset($l);
        if ($errores) {
            throw ValidationException::withMessages(['series' => $errores]);
        }

        if ($incompleto) {
            $ninguna = array_sum(array_column($lineas, 'series_recibidas')) === 0;
            throw new SeriesRequeridas(
                $ninguna
                    ? 'Esta factura trae productos con series. Ingresa el número de serie de cada unidad para poder importarla.'
                    : 'Las series no coinciden con las cantidades de la factura: revisa cuántas necesita cada producto.',
                array_values($lineas)
            );
        }

        self::exigirSeriesNuevas($companyId, array_merge(...array_values($dadas)));

        foreach ($indices as $codigo => $posiciones) {
            $cola = $dadas[$codigo];
            foreach ($posiciones as $i) {
                $items[$i]['series'] = array_splice($cola, 0, (int) round((float) $items[$i]['cantidad']));
            }
        }

        return $items;
    }

    /** Todas las series nuevas: ninguna repetida entre sí y ninguna que ya exista en la empresa. */
    private static function exigirSeriesNuevas(int $companyId, array $series): void
    {
        $repetidas = array_keys(array_filter(array_count_values($series), fn ($n) => $n > 1));
        if ($repetidas) {
            throw ValidationException::withMessages(['series' => ['Hay series repetidas en la compra: '.implode(', ', $repetidas).'.']]);
        }
        $existentes = ProductSerie::where('company_id', $companyId)->whereIn('serie', $series)->with('product:id,codigo')->orderBy('serie')->get();
        if ($existentes->isNotEmpty()) {
            $lista = $existentes->map(fn ($s) => $s->serie.' (producto '.($s->product->codigo ?? '—').')')->implode(', ');
            throw ValidationException::withMessages(['series' => ['Estas series ya existen en el sistema: '.$lista.'.']]);
        }
    }

    private static function producto(int $companyId, string $codigo, array &$memo): ?Product
    {
        if (! array_key_exists($codigo, $memo)) {
            $memo[$codigo] = Product::where('company_id', $companyId)->where('codigo', $codigo)->first();
        }

        return $memo[$codigo];
    }

    private static function manejaSeries(?Product $p): bool
    {
        return $p && $p->maneja_series && $p->tipo !== 'servicio';
    }
}

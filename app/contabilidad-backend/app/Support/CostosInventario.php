<?php

namespace App\Support;

use App\Models\InventoryMovement;

/**
 * Los costos que el kárdex y los asientos tienen que compartir.
 *
 * Compras: lo que entra al inventario es el costo NETO de la línea (cantidad x precio
 * menos el descuento). El mismo valor va al kárdex y al Debe de 1.1.05, así el libro
 * mayor y el kárdex no se separan.
 * Ventas: el costo es el que el kárdex puso a cada salida, nunca el promedio de hoy.
 */
final class CostosInventario
{
    /** Costo neto de una línea de compra, a 2 decimales. */
    public static function baseCompra(array $item): float
    {
        $bruto = (float) ($item['cantidad'] ?? 0) * (float) ($item['precio_unitario'] ?? 0);

        // Compra manual: lleva el descuento de la línea.
        if (isset($item['descuento'])) {
            return round($bruto - (float) $item['descuento'], 2);
        }
        // XML del SRI: la base imponible ya viene neta de descuentos.
        if (! empty($item['base_imponible'])) {
            return round((float) $item['base_imponible'], 2);
        }

        return round($bruto, 2);
    }

    /** Costo unitario con el que entra al kárdex: el neto (sin descuento es el precio tal cual). */
    public static function costoUnitarioCompra(array $item): float
    {
        $cant = (float) ($item['cantidad'] ?? 0);
        $precio = (float) ($item['precio_unitario'] ?? 0);
        if ($cant <= 0) {
            return $precio;
        }

        return abs(self::baseCompra($item) - $cant * $precio) < 0.005
            ? $precio
            : round(self::baseCompra($item) / $cant, 4);
    }

    /** Valor (cantidad x costo) de un movimiento del kárdex, a 2 decimales. */
    public static function valor(InventoryMovement $m): float
    {
        return round((float) $m->cantidad * (float) $m->costo_unitario, 2);
    }

    /** Salidas de inventario que dejó una factura. */
    public static function egresosDeFactura(int $invoiceId)
    {
        return InventoryMovement::where('invoice_id', $invoiceId)->where('tipo', 'egreso')->get();
    }

    /**
     * Costo unitario al que salió un producto en una factura (promedio ponderado de sus salidas).
     * Null si esa factura no lo sacó del inventario: ahí se usa el costo promedio vigente.
     */
    public static function costoUnitarioFacturado(int $invoiceId, int $productId): ?float
    {
        $movs = InventoryMovement::where('invoice_id', $invoiceId)
            ->where('product_id', $productId)->where('tipo', 'egreso')->get();
        $cant = (float) $movs->sum('cantidad');
        if ($cant <= 0) {
            return null;
        }

        return round($movs->sum(fn ($m) => (float) $m->cantidad * (float) $m->costo_unitario) / $cant, 4);
    }
}

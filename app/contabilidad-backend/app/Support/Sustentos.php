<?php

namespace App\Support;

use App\Models\Product;

/**
 * Sustento tributario de una compra (tabla 5 del SRI, config/sustentos.php).
 * Un solo lugar para saber qué códigos existen y cuál corresponde cuando nadie lo eligió.
 */
final class Sustentos
{
    /** Sustento cuando la factura no lleva mercadería con stock (servicios y gastos). */
    public const SERVICIOS = '01';

    /** Sustento cuando la factura lleva bienes que entran al inventario. */
    public const INVENTARIO = '06';

    /** @return array<string,string> código => nombre */
    public static function catalogo(): array
    {
        return config('sustentos', []);
    }

    /** @return list<string> */
    public static function codigos(): array
    {
        return array_map('strval', array_keys(self::catalogo()));
    }

    public static function existe(?string $codigo): bool
    {
        return $codigo !== null && $codigo !== '' && array_key_exists($codigo, self::catalogo());
    }

    public static function nombre(?string $codigo): string
    {
        return self::catalogo()[$codigo] ?? 'Sustento no catalogado';
    }

    /**
     * Sustento por defecto de una factura importada: 06 (inventario) si alguna línea es un bien
     * con stock, 01 si todo son servicios o gastos. Se aplica el mismo criterio con que la compra
     * ingresa al kárdex: código presente y cantidad mayor a cero, y un producto que todavía no
     * existe entra como bien.
     */
    public static function predeterminado(int $companyId, array $items): string
    {
        foreach ($items as $item) {
            $codigo = trim((string) ($item['codigo_principal'] ?? ''));
            if ($codigo === '' || (float) ($item['cantidad'] ?? 0) <= 0) {
                continue;
            }
            $tipo = Product::where('company_id', $companyId)->where('codigo', $codigo)->value('tipo');
            if ($tipo !== 'servicio') {
                return self::INVENTARIO;
            }
        }

        return self::SERVICIOS;
    }
}

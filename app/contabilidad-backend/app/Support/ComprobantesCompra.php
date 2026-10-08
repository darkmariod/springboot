<?php

namespace App\Support;

/**
 * Tipo de comprobante de una compra, en el código de la tabla 4 del SRI (ATS).
 *
 * `purchases.tipo_comprobante` guarda el tipo interno ('compra', 'factura', 'nota_venta',
 * 'liquidacion_compra'...); una compra anterior a ese campo lo trae vacío y es una factura.
 */
final class ComprobantesCompra
{
    /** Tipos que se pueden elegir al registrar una compra a mano. */
    public const ELEGIBLES = ['factura', 'nota_venta'];

    private const NOMBRES = [
        '01' => 'Factura',
        '02' => 'Nota de venta',
        '03' => 'Liquidación de compra',
        '04' => 'Nota de crédito',
        '05' => 'Nota de débito',
    ];

    private const POR_TIPO_INTERNO = [
        'compra' => '01',
        'factura' => '01',
        'nota_venta' => '02',
        'liquidacion_compra' => '03',
        'nota_credito' => '04',
        'nota_debito' => '05',
    ];

    /** Código SRI del tipo interno; sin dato (o un tipo desconocido) se asume factura. */
    public static function codigo(?string $tipoInterno): string
    {
        return self::POR_TIPO_INTERNO[$tipoInterno ?? ''] ?? '01';
    }

    public static function nombre(string $codigo): string
    {
        return self::NOMBRES[$codigo] ?? 'Otro comprobante';
    }

    /** Opciones para el formulario de compras: [value, label]. */
    public static function opciones(): array
    {
        return [
            ['value' => 'factura', 'label' => self::NOMBRES['01']],
            ['value' => 'nota_venta', 'label' => self::NOMBRES['02']],
        ];
    }
}

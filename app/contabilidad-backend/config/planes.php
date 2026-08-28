<?php

/*
 * Dos planes, como se acordó para vender:
 *   Básico   — inventario y punto de venta, SIN facturación electrónica.
 *   Completo — todo lo anterior + facturación electrónica del SRI + medios de pago.
 */
return [
    'basico' => [
        'nombre' => 'Básico',
        'precio_anual' => 99,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'reportes',
            'series', 'cartera', 'bancos', 'auditoria',
        ],
    ],

    'completo' => [
        'nombre' => 'Completo',
        'precio_anual' => 150,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'reportes',
            'series', 'cartera', 'bancos', 'auditoria',
            'facturacion_sri', 'import_lote', 'facturacion_masiva',
            'conciliacion', 'conciliacion_tarjetas',
            'conversion_articulos', 'fraccionamiento', 'reservas_stock',
            'contabilidad', 'usuarios', 'nomina', 'sucursales',
        ],
    ],
];

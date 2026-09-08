<?php

/*
 * Tres planes:
 *   Básico   — inventario y punto de venta, SIN facturación electrónica.
 *   Negocio  — suma la facturación del SRI, conciliaciones y usuarios.
 *   Completo — suma contabilidad, nómina y multisucursales.
 */
return [
    'basico' => [
        'nombre' => 'Básico',
        'precio_anual' => 85,
        'precio_semestral' => 55,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'reportes',
            'series', 'cartera', 'bancos', 'auditoria',
        ],
    ],

    'negocio' => [
        'nombre' => 'Negocio',
        'precio_anual' => 145,
        'precio_semestral' => 92,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'reportes',
            'series', 'cartera', 'bancos', 'auditoria',
            'facturacion_sri', 'import_lote', 'facturacion_masiva',
            'conciliacion', 'conciliacion_tarjetas', 'usuarios',
            'conversion_articulos', 'fraccionamiento', 'reservas_stock',
        ],
    ],

    'completo' => [
        'nombre' => 'Completo',
        'precio_anual' => 225,
        'precio_semestral' => 142,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'reportes',
            'series', 'cartera', 'bancos', 'auditoria',
            'facturacion_sri', 'import_lote', 'facturacion_masiva',
            'conciliacion', 'conciliacion_tarjetas', 'usuarios',
            'conversion_articulos', 'fraccionamiento', 'reservas_stock',
            'contabilidad', 'nomina', 'sucursales',
        ],
    ],
];

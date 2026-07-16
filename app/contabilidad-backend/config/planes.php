<?php
return [
    'basico' => [
        'nombre' => 'Básico',
        'precio_anual' => 289,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote',
        ],
    ],
    'pro' => [
        'nombre' => 'PRO',
        'precio_anual' => 489,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote', 'series', 'bancos', 'conciliacion', 'cartera', 'firma_incluida',
        ],
    ],
    'corporativo' => [
        'nombre' => 'Corporativo',
        'precio_anual' => 890,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote', 'series', 'bancos', 'conciliacion', 'cartera', 'firma_incluida',
            'contabilidad', 'nomina', 'usuarios', 'auditoria',
        ],
    ],
];

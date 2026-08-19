<?php

return [
    'emprendedor' => [
        'nombre' => 'Emprendedor',
        'precio_anual' => 49,
        'features' => ['catalogo', 'ventas', 'compras', 'inventario', 'reportes'],
    ],
    'negocio' => [
        'nombre' => 'Negocio',
        'precio_anual' => 99,
        'features' => ['catalogo', 'ventas', 'compras', 'inventario', 'reportes', 'facturacion_sri', 'import_lote', 'facturacion_masiva'],
    ],
    'profesional' => [
        'nombre' => 'Profesional',
        'precio_anual' => 149,
        'features' => ['catalogo', 'ventas', 'compras', 'inventario', 'reportes', 'facturacion_sri', 'import_lote', 'facturacion_masiva', 'series', 'cartera', 'bancos', 'conciliacion', 'conciliacion_tarjetas', 'conversion_articulos', 'fraccionamiento', 'reservas_stock', 'contabilidad', 'usuarios', 'auditoria'],
    ],
    'empresarial' => [
        'nombre' => 'Empresarial',
        'precio_anual' => null,
        'features' => ['catalogo', 'ventas', 'compras', 'inventario', 'reportes', 'facturacion_sri', 'import_lote', 'facturacion_masiva', 'series', 'cartera', 'bancos', 'conciliacion', 'conciliacion_tarjetas', 'conversion_articulos', 'fraccionamiento', 'reservas_stock', 'contabilidad', 'usuarios', 'auditoria', 'nomina', 'sucursales', 'activos_fijos'],
    ],
];

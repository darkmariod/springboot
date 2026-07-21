<?php

/*
 * Planes alineados con los reales de KBS (kbs-erp.com, línea Inventarios).
 * Sus precios son ANUALES y se renuevan cada año; los nuestros son de referencia
 * para saber qué desbloquear en cada nivel.
 */
return [
    // KBS: $289/año. Facturación + inventario. SIN series.
    'emprendedor' => [
        'nombre' => 'Emprendedor',
        'precio_anual' => 289,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote', 'fraccionamiento',
        ],
    ],

    // KBS: $389/año. Acá aparecen las SERIES (garantías) y la firma gestionada.
    'pro' => [
        'nombre' => 'PRO',
        'precio_anual' => 389,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote',
            'series', 'cartera', 'firma_incluida',
            'fraccionamiento', 'conversion_articulos', 'conciliacion_tarjetas', 'facturacion_masiva', 'reservas_stock',
        ],
    ],

    // KBS: $559/año. Contabilidad, nómina, impuestos y conciliación bancaria.
    'business' => [
        'nombre' => 'Business',
        'precio_anual' => 559,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote',
            'series', 'cartera', 'firma_incluida',
            'fraccionamiento', 'conversion_articulos', 'conciliacion_tarjetas', 'facturacion_masiva', 'reservas_stock',
            'bancos', 'conciliacion', 'contabilidad', 'nomina', 'usuarios', 'auditoria',
        ],
    ],

    // KBS: $659/año. Suma multi-sucursal y activos fijos: lo único que todavía no construimos.
    'corporativo' => [
        'nombre' => 'Corporativo',
        'precio_anual' => 659,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri', 'reportes',
            'import_lote',
            'series', 'cartera', 'firma_incluida',
            'fraccionamiento', 'conversion_articulos', 'conciliacion_tarjetas', 'facturacion_masiva', 'reservas_stock',
            'bancos', 'conciliacion', 'contabilidad', 'nomina', 'usuarios', 'auditoria',
            'sucursales', 'activos_fijos',
        ],
    ],
];

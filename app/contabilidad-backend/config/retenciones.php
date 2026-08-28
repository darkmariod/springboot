<?php

/*
 * Códigos de retención en la fuente del Impuesto a la Renta (formulario 103).
 * Son los que exige el SRI en la declaración mensual.
 */
return [
    'renta' => [
        ['codigo' => '303', 'nombre' => 'Honorarios profesionales', 'porcentaje' => 10],
        ['codigo' => '304', 'nombre' => 'Servicios donde predomina el intelecto', 'porcentaje' => 8],
        ['codigo' => '307', 'nombre' => 'Servicios donde predomina la mano de obra', 'porcentaje' => 2],
        ['codigo' => '308', 'nombre' => 'Servicios entre sociedades', 'porcentaje' => 2],
        ['codigo' => '309', 'nombre' => 'Servicios de publicidad y comunicación', 'porcentaje' => 1.75],
        ['codigo' => '310', 'nombre' => 'Transporte privado de pasajeros o carga', 'porcentaje' => 1],
        ['codigo' => '312', 'nombre' => 'Compra de bienes muebles', 'porcentaje' => 1.75],
        ['codigo' => '319', 'nombre' => 'Arrendamiento mercantil', 'porcentaje' => 1.75],
        ['codigo' => '320', 'nombre' => 'Arrendamiento de bienes inmuebles', 'porcentaje' => 8],
        ['codigo' => '322', 'nombre' => 'Seguros y reaseguros (10% del valor de la prima)', 'porcentaje' => 0.1],
        ['codigo' => '332', 'nombre' => 'Otras retenciones aplicables el 2.75%', 'porcentaje' => 2.75],
        ['codigo' => '340', 'nombre' => 'Otras retenciones aplicables a otros porcentajes', 'porcentaje' => 0],
        ['codigo' => '344', 'nombre' => 'Pagos al exterior', 'porcentaje' => 25],
    ],

    // Retención de IVA: van al formulario 104
    'iva' => [
        ['codigo' => '721', 'nombre' => 'Retención del 30% de IVA en bienes', 'porcentaje' => 30],
        ['codigo' => '723', 'nombre' => 'Retención del 70% de IVA en servicios', 'porcentaje' => 70],
        ['codigo' => '725', 'nombre' => 'Retención del 100% de IVA', 'porcentaje' => 100],
    ],
];

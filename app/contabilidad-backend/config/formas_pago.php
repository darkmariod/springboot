<?php

// Las 10 formas de pago de KVS, con su código SRI y la cuenta contable que mueven.
// 'cuenta' es el CONCEPTO del plan de cuentas central (config/cuentas.php), no un
// código: así un código nunca queda repetido con otro significado.
return [
    'efectivo' => [
        'label' => 'Efectivo', 'sri' => '01',
        'cuenta' => 'caja',
    ],
    'cheque_caja' => [
        'label' => 'Cheque Caja', 'sri' => '20',
        'cuenta' => 'caja',
    ],
    'cheque_banco' => [
        'label' => 'Cheque Banco', 'sri' => '20', 'pide_banco' => true, 'pide_documento' => true,
        'cuenta' => 'bancos',
    ],
    'transferencia' => [
        'label' => 'Transferencia', 'sri' => '20', 'pide_banco' => true,
        'cuenta' => 'bancos',
    ],
    'tarjeta_credito' => [
        'label' => 'Tarjeta Crédito', 'sri' => '19',
        'cuenta' => 'tarjetas_por_liquidar',
    ],
    'comision_tarjeta' => [
        'label' => 'Comisión Tarjeta Crédito', 'sri' => '19',
        'cuenta' => 'comisiones_bancarias',
    ],
    'nota_debito' => [
        'label' => 'Nota de Débito', 'sri' => '20',
        'cuenta' => 'notas_debito',
    ],
    // Un cruce no mueve dinero: cancela una factura contra una compra del MISMO contacto (o al revés).
    // No tiene cuenta propia; lo asienta App\Support\CruceSaldos (Debe CxP / Haber CxC).
    'cruce_saldos' => [
        'label' => 'Cruce de saldos', 'sri' => '20', 'es_cruce' => true,
        'cuenta' => null,
    ],
    'dinero_electronico' => [
        'label' => 'Dinero Electrónico', 'sri' => '17',
        'cuenta' => 'dinero_electronico',
    ],
    'cuenta_contable' => [
        'label' => 'Cuenta Contable', 'sri' => '20', 'pide_cuenta' => true,
        'cuenta' => null, // la elige el usuario
    ],
];

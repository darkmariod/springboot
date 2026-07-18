<?php

// Las 10 formas de pago de KVS, con su código SRI y la cuenta contable que mueven.
return [
    'efectivo' => [
        'label' => 'Efectivo', 'sri' => '01',
        'cuenta' => ['codigo' => '1.1.01', 'nombre' => 'Caja', 'tipo' => 'activo'],
    ],
    'cheque_caja' => [
        'label' => 'Cheque Caja', 'sri' => '20',
        'cuenta' => ['codigo' => '1.1.01', 'nombre' => 'Caja', 'tipo' => 'activo'],
    ],
    'cheque_banco' => [
        'label' => 'Cheque Banco', 'sri' => '20', 'pide_banco' => true, 'pide_documento' => true,
        'cuenta' => ['codigo' => '1.1.02', 'nombre' => 'Bancos', 'tipo' => 'activo'],
    ],
    'transferencia' => [
        'label' => 'Transferencia', 'sri' => '20', 'pide_banco' => true,
        'cuenta' => ['codigo' => '1.1.02', 'nombre' => 'Bancos', 'tipo' => 'activo'],
    ],
    'tarjeta_credito' => [
        'label' => 'Tarjeta Crédito', 'sri' => '19',
        'cuenta' => ['codigo' => '1.1.05', 'nombre' => 'Tarjetas por liquidar', 'tipo' => 'activo'],
    ],
    'comision_tarjeta' => [
        'label' => 'Comisión Tarjeta Crédito', 'sri' => '19',
        'cuenta' => ['codigo' => '5.3.01', 'nombre' => 'Comisiones bancarias', 'tipo' => 'gasto'],
    ],
    'nota_debito' => [
        'label' => 'Nota de Débito', 'sri' => '20',
        'cuenta' => ['codigo' => '2.1.08', 'nombre' => 'Notas de débito', 'tipo' => 'pasivo'],
    ],
    'cruce_saldos' => [
        'label' => 'Cruce Saldos', 'sri' => '20',
        'cuenta' => ['codigo' => '1.1.03', 'nombre' => 'Cuentas por cobrar clientes', 'tipo' => 'activo'],
    ],
    'dinero_electronico' => [
        'label' => 'Dinero Electrónico', 'sri' => '17',
        'cuenta' => ['codigo' => '1.1.07', 'nombre' => 'Dinero electrónico', 'tipo' => 'activo'],
    ],
    'cuenta_contable' => [
        'label' => 'Cuenta Contable', 'sri' => '20', 'pide_cuenta' => true,
        'cuenta' => null, // la elige el usuario
    ],
];

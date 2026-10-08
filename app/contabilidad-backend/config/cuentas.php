<?php

/*
 * Plan de cuentas central de HasReset: un solo lugar donde cada CONCEPTO
 * contable tiene su código, su nombre y su tipo. Un código = un concepto.
 *
 * Los controladores y servicios no escriben códigos a mano: piden el concepto
 * con App\Support\Cuentas (get / codigo / linea) y el seeder siembra este mismo
 * plan. Si hace falta una cuenta nueva, se agrega aquí con un código libre.
 *
 * Reglas de la numeración:
 *   1.1.xx  Activo corriente      2.1.xx  Pasivo corriente
 *   3.1.xx  Patrimonio            4.1.xx  Ingresos
 *   5.1.xx  Costos y compras      5.2.xx  Gastos de personal
 *   5.3.xx  Gastos financieros
 *
 * Solo hacia adelante: los asientos ya registrados no se reprocesan; si una
 * empresa tenía un código con otro significado, esos asientos antiguos se quedan
 * como estaban.
 */
return [

    // Encabezados de grupo: [código, nombre, tipo]
    'grupos' => [
        ['1', 'ACTIVO', 'activo'],
        ['1.1', 'ACTIVO CORRIENTE', 'activo'],
        ['2', 'PASIVO', 'pasivo'],
        ['2.1', 'PASIVO CORRIENTE', 'pasivo'],
        ['3', 'PATRIMONIO', 'patrimonio'],
        ['4', 'INGRESOS', 'ingreso'],
        ['5', 'GASTOS', 'gasto'],
    ],

    // concepto => cuenta
    'cuentas' => [
        // ---- Activo
        'caja' => ['codigo' => '1.1.01', 'nombre' => 'Caja', 'tipo' => 'activo'],
        'bancos' => ['codigo' => '1.1.02', 'nombre' => 'Bancos', 'tipo' => 'activo'],
        'cxc' => ['codigo' => '1.1.03', 'nombre' => 'Cuentas por cobrar clientes', 'tipo' => 'activo'],
        'credito_tributario_iva' => ['codigo' => '1.1.04', 'nombre' => 'Crédito tributario IVA', 'tipo' => 'activo'],
        'inventario' => ['codigo' => '1.1.05', 'nombre' => 'Inventario', 'tipo' => 'activo'],
        'retenciones_anticipadas' => ['codigo' => '1.1.06', 'nombre' => 'Retenciones en la fuente anticipadas', 'tipo' => 'activo'],
        'dinero_electronico' => ['codigo' => '1.1.07', 'nombre' => 'Dinero electrónico', 'tipo' => 'activo'],
        'tarjetas_por_liquidar' => ['codigo' => '1.1.08', 'nombre' => 'Tarjetas por liquidar', 'tipo' => 'activo'],
        // Facturas a un cliente marcado "parte relacionada": debitan esta cuenta en lugar de 'cxc'.
        // Cobros, notas de crédito y cruces siguen la cuenta con que nació la factura (App\Support\Cuentas::cxcDe).
        'cxc_relacionadas' => ['codigo' => '1.1.09', 'nombre' => 'Cuentas por cobrar relacionadas', 'tipo' => 'activo'],
        // Dinero entregado a un proveedor antes de que facture (Advance de tipo 'proveedor'). Al aplicarlo a una
        // compra se cancela contra la CxP en que nació esa compra. El chequeo estricto compara su saldo con los anticipos abiertos.
        'anticipos_proveedores' => ['codigo' => '1.1.10', 'nombre' => 'Anticipos a proveedores', 'tipo' => 'activo'],

        // ---- Pasivo
        'cxp' => ['codigo' => '2.1.01', 'nombre' => 'Cuentas por pagar proveedores', 'tipo' => 'pasivo'],
        'iva_por_pagar' => ['codigo' => '2.1.02', 'nombre' => 'IVA por pagar', 'tipo' => 'pasivo'],
        'anticipos_clientes' => ['codigo' => '2.1.03', 'nombre' => 'Anticipos de clientes', 'tipo' => 'pasivo'],
        'iess_por_pagar' => ['codigo' => '2.1.04', 'nombre' => 'IESS por pagar', 'tipo' => 'pasivo'],
        'beneficios_por_pagar' => ['codigo' => '2.1.05', 'nombre' => 'Beneficios sociales por pagar', 'tipo' => 'pasivo'],
        'descuentos_empleados' => ['codigo' => '2.1.06', 'nombre' => 'Descuentos a empleados', 'tipo' => 'pasivo'],
        'sueldos_por_pagar' => ['codigo' => '2.1.07', 'nombre' => 'Sueldos por pagar', 'tipo' => 'pasivo'],
        'notas_debito' => ['codigo' => '2.1.08', 'nombre' => 'Notas de débito', 'tipo' => 'pasivo'],
        'retencion_iva_por_pagar' => ['codigo' => '2.1.09', 'nombre' => 'Retención IVA por pagar', 'tipo' => 'pasivo'],
        'retencion_renta_por_pagar' => ['codigo' => '2.1.10', 'nombre' => 'Retención renta por pagar', 'tipo' => 'pasivo'],
        // Compras a un proveedor marcado "parte relacionada": acreditan esta cuenta en lugar de 'cxp'.
        // Pagos, retenciones y cruces siguen la cuenta con que nació la compra (App\Support\Cuentas::cxpDe).
        'cxp_relacionadas' => ['codigo' => '2.1.11', 'nombre' => 'Cuentas por pagar relacionadas', 'tipo' => 'pasivo'],

        // ---- Patrimonio
        'capital' => ['codigo' => '3.1.01', 'nombre' => 'Capital', 'tipo' => 'patrimonio'],

        // ---- Ingresos
        'ventas' => ['codigo' => '4.1.01', 'nombre' => 'Ventas', 'tipo' => 'ingreso'],
        // Cuenta de contrapartida de las ventas; se mantiene como "gasto" porque así
        // quedó creada en las empresas que ya están en uso.
        'devoluciones_ventas' => ['codigo' => '4.1.02', 'nombre' => 'Devoluciones y descuentos en ventas', 'tipo' => 'gasto'],
        // Intereses, mora y cargos extra que se cobran con una nota de débito (SRI o interna). Es un ingreso:
        // no confundir con 'notas_debito' (2.1.08), que es el pasivo de la forma de pago "Nota de Débito".
        'ingresos_notas_debito' => ['codigo' => '4.1.03', 'nombre' => 'Otros ingresos por notas de débito', 'tipo' => 'ingreso'],

        // ---- Costos, compras y gastos
        'compras' => ['codigo' => '5.1.01', 'nombre' => 'Compras', 'tipo' => 'gasto'],
        'gastos_generales' => ['codigo' => '5.1.02', 'nombre' => 'Gastos generales', 'tipo' => 'gasto'],
        'faltantes_sobrantes' => ['codigo' => '5.1.03', 'nombre' => 'Faltantes y sobrantes de inventario', 'tipo' => 'gasto'],
        // Costo de lo vendido: sale del kárdex (cantidad x costo promedio) en el mismo asiento de la factura.
        // La mercadería comprada entra a 1.1.05 Inventario; recién al venderla pasa a esta cuenta.
        'costo_ventas' => ['codigo' => '5.1.04', 'nombre' => 'Costo de ventas', 'tipo' => 'gasto'],
        'sueldos' => ['codigo' => '5.2.01', 'nombre' => 'Sueldos y salarios', 'tipo' => 'gasto'],
        'aporte_patronal' => ['codigo' => '5.2.02', 'nombre' => 'Aporte patronal IESS', 'tipo' => 'gasto'],
        'beneficios_sociales' => ['codigo' => '5.2.03', 'nombre' => 'Beneficios sociales', 'tipo' => 'gasto'],
        'comisiones_bancarias' => ['codigo' => '5.3.01', 'nombre' => 'Comisiones bancarias', 'tipo' => 'gasto'],
    ],
];

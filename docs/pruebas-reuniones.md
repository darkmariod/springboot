# Pruebas de las reuniones

Este documento sirve para llegar a una reunión sabiendo que **todo lo que nos pidieron funciona hoy**.
Cada pedido del consultor tributario, de la contadora anterior, del competidor (Perseo) y de la plataforma tiene al menos una prueba automática.
La prueba actúa como lo haría una persona: crea la factura, la compra o el asiento, y comprueba los números.

No hace falta saber programar para usarlo. Con un solo comando se corren todas las pruebas y sale un informe en español.

## Cómo correrlas antes de una reunión

Abra una terminal en la carpeta `app/contabilidad-backend` y escriba:

```bash
bash scripts/pruebas-reuniones.sh
```

Dura unos 5 segundos. Al final aparece una tabla con una fila por comprobación (`Pedido | Reunión | Qué se probó | Resultado`), un resumen de R1 a R27 y una de estas dos líneas:

- **LISTO PARA LA REUNIÓN**: todo pasa.
- **HAY PEDIDOS QUE FALLAN: R…**: la lista dice cuáles. Cada falla trae debajo el motivo.

Si un pedido aparece como **PENDIENTE**, significa que hoy no se cumple y está anotado como hallazgo. El informe lo muestra aparte, en amarillo, para que no pase desapercibido.

### Otras formas de correrlas

| Qué quiere correr | Comando (desde `app/contabilidad-backend`) |
| --- | --- |
| Todo, con informe en español | `bash scripts/pruebas-reuniones.sh` |
| Solo las pruebas del servidor | `php artisan test --group=reuniones` |
| Lo mismo, por suite | `php artisan test --testsuite=Reuniones` |
| Un solo pedido, por ejemplo R6 | `php artisan test --group=R6` |
| Solo lo que se ve en pantalla (menú, roles, atajos) | `node ../contabilidad-vue/scripts/verificar-reuniones.mjs` |
| Todas las pruebas del sistema | `php artisan test` |

Las pruebas no usan internet ni mandan correos de verdad: el SRI y el correo son simulados, y la base de datos es temporal.

## Fuentes

| Sigla | Qué es |
| --- | --- |
| CONS-10jul | El consultor tributario le muestra a Javier Soliz cómo trabajan otros sistemas (3 grabaciones, 10 de julio) |
| CONS-4sep | Primera demostración de HasReset al consultor y a Javier (4 de septiembre) |
| CONS-18sep | Segunda demostración (18 de septiembre) |
| CONT | La contadora anterior |
| COMP | Funciones del competidor Perseo |
| PLAT | Requisitos para vender a varios clientes (agregados el 30 de octubre de 2026) |
| Mario | Explicaciones de Mario (7 de octubre) |

## Matriz: qué pidieron y qué prueba lo verifica

Las pruebas del servidor están en `app/contabilidad-backend/tests/Feature/Reuniones/`.
La columna «Prueba» indica `Clase::método`. Las pruebas de pantalla están en `app/contabilidad-vue/scripts/verificar-reuniones.mjs`.

| Pedido | Reunión | Qué pidieron | Prueba que lo verifica | Cómo se ve en pantalla |
| --- | --- | --- | --- | --- |
| **R1** Importar compras del SRI | CONS-10jul, CONS-18sep | «Bajo el TXT del mes y que me deje solo las facturas; las retenciones y las notas de crédito no son compras.» «Y el XML, uno por uno.» | `Reunion10JulTest::test_R1_el_txt_del_mes_deja_pendientes_las_facturas_y_omite_retenciones_y_notas_de_credito`<br>`Reunion10JulTest::test_R1_el_xml_de_una_factura_de_compra_se_importa_uno_por_uno_y_no_se_repite` | Compras → Importar del SRI (lote) (TXT); Compras → Compras → importar XML |
| **R2** Contactos | CONS-10jul, CONS-18sep | «Pongo la cédula o el RUC y me trae el nombre del SRI.» Parte relacionada, clase de contribuyente y cuenta contable por defecto del proveedor. | `Reunion10JulTest::test_R2_el_cliente_se_autocompleta_con_la_cedula_o_el_ruc_desde_el_sri`<br>`Reunion10JulTest::test_R2_la_parte_relacionada_va_a_cuentas_relacionadas_y_la_clase_de_contribuyente_se_guarda`<br>`Reunion10JulTest::test_R2_la_cuenta_contable_por_defecto_del_proveedor_recibe_sus_servicios` | Catálogo → Clientes (formulario del cliente); Compras → Proveedores |
| **R3** Sustento tributario | CONS-10jul, CONS-18sep | «Sustento del 01 al 13, un reporte de compras por sustento, y que el XML importado respete el sustento.» | `Reunion10JulTest::test_R3_los_sustentos_van_del_01_al_13_y_el_xml_importado_respeta_el_elegido`<br>`Reunion10JulTest::test_R3_el_reporte_de_compras_por_sustento_agrupa_y_suma` | Compras → Registro de Compras (campo sustento); Compras → Reportes de compras |
| **R4** Retención al comprar | CONS-10jul, CONS-18sep | «Retener renta e IVA al registrar la compra, con su asiento y el saldo de la compra.» | `Reunion10JulTest::test_R4_la_retencion_de_renta_e_iva_deja_su_asiento_y_baja_el_saldo_de_la_compra` | Compras → Compras → Retención |
| **R5** Pagos | CONS-10jul, CONS-18sep | «Cheque con banco, pago a varios proveedores, anticipo a proveedor aplicado y cruce de saldos.» | `Reunion10JulTest::test_R5_el_pago_con_cheque_de_un_banco_deja_el_movimiento_del_banco`<br>`Reunion10JulTest::test_R5_un_solo_pago_cancela_las_compras_de_varios_proveedores`<br>`Reunion10JulTest::test_R5_el_anticipo_a_un_proveedor_se_aplica_a_su_compra`<br>`Reunion10JulTest::test_R5_el_cruce_de_saldos_cancela_la_factura_contra_la_compra_del_mismo_contacto` | Compras → Cuentas por pagar; Ventas → Anticipos; Ventas → Cuentas por cobrar (cruce) |
| **R6** Estado de cuenta | CONS-10jul, CONS-18sep | «Documento por documento y por cliente: factura de 100, nota de crédito de 10, retención de 1, el cliente debe 89.» | `Reunion10JulTest::test_R6_el_estado_de_cuenta_muestra_100_menos_10_menos_1_igual_89`<br>`Reunion10JulTest::test_R6_el_estado_de_cuenta_de_proveedores_muestra_lo_retenido_lo_pagado_y_lo_que_se_debe` | Ventas → Estado de cuenta de clientes; Compras → Estado de cuenta de proveedores |
| **R7** Notas de crédito y débito | CONS-18sep, CONS-10jul | «La nota de crédito me obliga a elegir la factura.» «Hay notas del SRI e internas.» «La de débito sube el saldo y las veo juntas.» | `Reunion18SepTest::test_R7_la_nota_de_credito_obliga_a_elegir_la_factura_y_sin_ella_da_422`<br>`Reunion18SepTest::test_R7_existen_notas_del_SRI_e_internas_la_interna_no_tiene_documento_SRI`<br>`Reunion18SepTest::test_R7_la_nota_de_debito_sube_el_saldo_y_las_notas_se_listan_juntas` | Ventas → Notas de crédito y débito (dos pestañas) |
| **R8** RIDE | CONS-18sep | «Que traiga los datos del emisor y, si está autorizado, el número y la fecha de autorización; si no, una leyenda, nunca un número inventado.» | `Reunion18SepTest::test_R8_el_ride_de_un_documento_autorizado_trae_el_emisor_el_numero_y_la_fecha_de_autorizacion`<br>`Reunion18SepTest::test_R8_el_ride_de_un_documento_no_autorizado_lleva_la_leyenda_y_ningun_numero_inventado` | Ventas → Facturas → ícono del PDF (RIDE) |
| **R9** Anulación | CONS-18sep | «La factura queda anulada, se conserva la secuencia y avisa que hay que anularla también en el portal del SRI.» | `Reunion18SepTest::test_R9_anular_una_factura_autorizada_conserva_la_secuencia_y_avisa_del_portal_del_SRI`<br>`Reunion18SepTest::test_R9_anular_una_factura_que_no_se_autorizo_dice_que_no_hace_falta_accion_en_el_SRI` | Ventas → Facturas → Anular |
| **R10** Documentos pendientes | CONS-10jul | «Autorizar todo: que reenvíe los firmados y diga cómo le fue a cada uno; sin certificado, que avise sin romper.» | `Reunion10JulTest::test_R10_autorizar_todo_reenvia_los_firmados_y_reporta_por_documento`<br>`Reunion10JulTest::test_R10_sin_certificado_avisa_sin_romper` | EDocuments → Documentos SRI |
| **R11** Cotizaciones y facturación masiva | CONS-18sep | «Varias cotizaciones en una sola factura, también a otro cliente; la facturación masiva responde.» | `Reunion18SepTest::test_R11_varias_cotizaciones_se_convierten_en_una_sola_factura`<br>`Reunion18SepTest::test_R11_las_cotizaciones_se_facturan_a_otro_cliente_y_la_facturacion_masiva_responde` | Ventas → Cotizaciones; Ventas → Facturas → Facturación masiva |
| **R12** Menú por módulos | CONS-4sep, CONS-18sep | «Bienes y Servicios separados, notas de crédito y débito juntas, reportes dentro de cada módulo, facturación masiva dentro de Facturas, flechas atrás y adelante.» | **Pantalla (Node):** 9 verificaciones R12 en `verificar-reuniones.mjs` | El menú de la izquierda y las flechas de la barra superior |
| **R13** Asientos | CONS-18sep, CONS-10jul | «Asientos manuales, corregir un asiento mal parametrizado y contabilizar un mes.» | `Reunion10JulTest::test_R13_un_asiento_manual_cuadrado_se_crea_y_uno_descuadrado_se_rechaza`<br>`Reunion10JulTest::test_R13_se_corrige_un_asiento_mal_parametrizado_desmayorizando_y_editando`<br>`Reunion10JulTest::test_R13_contabilizar_un_mes_mayoriza_solo_lo_cuadrado_y_el_resto_sale_en_omitidos` | Contabilidad → Libro diario |
| **R14** Períodos y estados financieros | CONS-10jul | «Un mes cerrado no admite facturar ni comprar; reabrir solo el administrador; estados financieros por período; código siguiente en el plan de cuentas; cierre del ejercicio.» | `Reunion10JulTest::test_R14_un_mes_cerrado_no_admite_facturar_ni_comprar`<br>`Reunion10JulTest::test_R14_solo_el_administrador_reabre_un_mes_cerrado`<br>`Reunion10JulTest::test_R14_los_estados_financieros_por_periodo_traen_detalle_y_el_balance_cuadra`<br>`Reunion10JulTest::test_R14_el_plan_de_cuentas_sugiere_el_codigo_siguiente`<br>`Reunion10JulTest::test_R14_el_cierre_del_ejercicio_deja_un_solo_asiento_con_el_resultado` | Contabilidad → Períodos contables; Contabilidad → Estados financieros; Catálogo → Plan de cuentas |
| **R15** Series por unidad | CONS-4sep, CONS-18sep | «Una compra de N unidades pide N series, se busca por un pedazo de serie y las series pertenecen a una bodega.» | `Reunion4SepTest::test_R15_una_compra_de_N_unidades_exige_N_series`<br>`Reunion4SepTest::test_R15_la_busqueda_por_un_pedazo_de_la_serie_trae_su_historial`<br>`Reunion4SepTest::test_R15_las_series_pertenecen_a_una_bodega` | Inventario → Garantías por serie; Compras (columna de series) |
| **R16** Toma física y kárdex | CONS-18sep | «Toma física con varios productos en un solo documento y un solo asiento; el kárdex dice el origen de cada movimiento.» | `Reunion4SepTest::test_R16_la_toma_fisica_masiva_junta_varios_productos_en_un_documento_con_un_solo_asiento`<br>`Reunion4SepTest::test_R16_el_kardex_dice_el_origen_de_cada_movimiento` | Inventario → Toma física; Inventario → Inventario y kardex |
| **R17** Caja, bancos y tarjetas | CONS-10jul, CONS-18sep | «Comprobante de caja con número y asiento, depósito que no pase de la caja, cheque posfechado, conciliación con el archivo del banco y liquidación de tarjetas en cero.» | `Reunion18SepTest::test_R17_cada_movimiento_de_caja_sale_con_comprobante_numerado_y_asiento`<br>`Reunion18SepTest::test_R17_el_deposito_a_banco_se_rechaza_si_pasa_de_lo_que_hay_en_caja_y_el_limite_exacto_se_permite`<br>`Reunion18SepTest::test_R17_un_cheque_posfechado_se_asienta_hoy_pero_no_cuenta_en_el_banco_hasta_su_fecha`<br>`Reunion18SepTest::test_R17_la_conciliacion_con_el_archivo_del_banco_propone_parejas_y_al_aceptar_solo_marca_conciliado`<br>`Reunion18SepTest::test_R17_la_liquidacion_de_tarjetas_deja_la_cuenta_por_liquidar_en_cero` | Caja y Bancos → Caja, Bancos, Conciliación bancaria, Conciliación Tarjetas |
| **R18** Nómina | CONS-10jul, CONS-18sep | «Anticipo, préstamo en cuotas, consumo facturado al empleado que se descuenta en el rol, permisos, vacaciones y rol de pagos.» | `NominaReunionesTest::test_R18_el_anticipo_se_entrega_con_su_asiento_y_el_rol_lo_descuenta_solo`<br>`NominaReunionesTest::test_R18_el_prestamo_a_tres_cuotas_descuenta_la_cuota_cada_mes_hasta_pagarse`<br>`NominaReunionesTest::test_R18_el_consumo_facturado_al_empleado_se_descuenta_solo_en_el_rol`<br>`NominaReunionesTest::test_R18_los_permisos_y_las_vacaciones_llevan_su_saldo_y_el_permiso_sin_sueldo_se_descuenta`<br>`NominaReunionesTest::test_R18_el_rol_de_pagos_sale_impreso_con_el_neto` | Nómina → Empleados; Nómina → Rol de pagos |
| **R19** Activos fijos | CONS-10jul | «Depreciación mensual automática y baja del activo.» | `ActivosFijosReunionesTest::test_R19_los_activos_se_deprecian_cada_mes_en_linea_recta_con_un_asiento_por_mes`<br>`ActivosFijosReunionesTest::test_R19_la_baja_de_un_activo_deprecia_lo_pendiente_y_manda_el_valor_en_libros_a_perdida` | Contabilidad → Activos fijos |
| **R20** Inventario contable | CONT | «El ajuste de inventario genera su asiento; la mercadería va a Inventario, no a gasto, y la venta registra el costo.» | `ContadoraAnteriorTest::test_R20_el_ajuste_de_inventario_genera_su_asiento_automatico`<br>`ContadoraAnteriorTest::test_R20_la_mercaderia_va_a_inventario_y_la_venta_registra_el_costo` | Inventario → Ajuste Inventario; Contabilidad → Libro diario |
| **R21** Fraccionamiento | Mario (7 de octubre) | «Una caja de 24 dólares con 12 unidades da 12 unidades de 2 dólares y el valor del inventario no cambia.» | `ExplicacionesDeMarioTest::test_R21_una_caja_de_24_dolares_con_12_unidades_da_12_unidades_de_2_dolares_y_el_inventario_no_cambia` | Inventario → Fraccionamiento |
| **R22** Correo del comprobante | COMP | «Como Perseo: al autorizarse, el cliente recibe el RIDE y el XML por correo, una sola vez; sin certificado, nada sale solo.» | `CompetidorPerseoTest::test_R22_al_autorizarse_el_comprobante_el_cliente_recibe_el_RIDE_y_el_XML_una_sola_vez`<br>`CompetidorPerseoTest::test_R22_sin_certificado_no_se_envia_nada_automatico` | EDocuments → Configuración de firma (interruptor de envío automático); Ventas → Facturas (columna Correo) |
| **R23** Roles | CONS-4sep, CONS-18sep | «El cajero solo vende y no crea usuarios ni ve la contabilidad; el contador no administra.» | `Reunion4SepTest::test_R23_el_cajero_solo_vende_y_no_crea_usuarios_ni_ve_contabilidad`<br>`Reunion4SepTest::test_R23_el_contador_lleva_la_contabilidad_pero_no_administra`<br>**Pantalla (Node):** 3 verificaciones R23 | Administración → Usuarios y roles; el menú cambia según el rol |
| **R24** Atajos y ventanas | CONS-4sep, CONS-18sep | «Atajos de teclado y varias ventanas abiertas a la vez.» | **Pantalla (Node):** 3 verificaciones R24 en `verificar-reuniones.mjs` | Barra de atajos de la pantalla de Módulos; pestañas de trabajo |
| **R25** Aislamiento | PLAT | «Un cliente de HasReset no ve ni toca los datos de otro.» | `PlataformaTest::test_R25_un_cliente_no_ve_los_datos_de_otro_cliente`<br>`PlataformaTest::test_R25_un_cliente_no_puede_tocar_los_datos_de_otro_cliente` | En todas las pantallas; Administración → Mi empresa |
| **R26** Planes | PLAT | «El administrador de un cliente no se cambia el plan; el plan vencido bloquea funciones.» | `PlataformaTest::test_R26_el_administrador_de_un_cliente_no_se_cambia_el_plan_pero_el_superadmin_si`<br>`PlataformaTest::test_R26_el_plan_vencido_bloquea_las_funciones_hasta_renovar`<br>`PlataformaTest::test_R26_cada_plan_abre_solo_los_modulos_que_incluye` | Administración → Empresas (solo la plataforma); Administración → Mi empresa |
| **R27** Seguridad de acceso | PLAT | «Usuario desactivado no entra, el token se revoca al cerrar sesión y el alta de una empresa nueva funciona y deja solo a su administrador.» | `PlataformaAccesoTest::test_R27_un_usuario_desactivado_no_entra_y_si_estaba_conectado_pierde_la_sesion`<br>`PlataformaAccesoTest::test_R27_al_cerrar_sesion_el_token_se_revoca_en_el_servidor`<br>`PlataformaTest::test_R27_el_alta_de_una_empresa_nueva_funciona_y_deja_solo_a_su_administrador` | Pantalla de ingreso; Administración → Usuarios y roles; Administración → Empresas → Nueva empresa |

## Cómo está armado

- **65 pruebas del servidor** (en 10 archivos) y **15 verificaciones de pantalla**. Cada prueba lleva el grupo `reuniones` y el grupo de su pedido (`R1`… `R27`), y su nombre empieza por el pedido.
- Cada prueba tiene, sobre su nombre, tres líneas: `Pedido por:` (quién lo pidió), `Pedido:` (lo que dijeron) y `Se prueba:` (lo que se comprueba). El informe lee esas líneas.
- Las pruebas que mueven dinero terminan con el chequeo contable estricto (`contable:chequeo --estricto`): cartera, inventario y libro mayor tienen que cuadrar.
- Las frases entre comillas del pedido son el resumen de lo que se dijo en cada reunión, tal como quedó en el documento de seguimiento (`app/odd/tasks/pulir-demo-hasreset.md`).

## Qué hacer si algo falla

1. Corra el pedido solo, por ejemplo `php artisan test --group=R14`. El mensaje dice qué valor esperaba y cuál obtuvo.
2. Si el cambio fue intencional, actualice la prueba; si no, arregle la aplicación. No se anota una prueba como correcta si el comportamiento cambió sin querer.
3. Si el pedido ya no se cumple y no se puede arreglar antes de la reunión, marque esa prueba con `$this->markTestSkipped('PENDIENTE: motivo claro')`. El informe la muestra como PENDIENTE y no como error.

## Notas para tener presentes en la reunión

- **El correo automático viene apagado por defecto.** La empresa lo enciende en EDocuments → Configuración de firma. La prueba R22 lo enciende primero, como lo haría el administrador.
- **El contador sí ve «Auditoría» y «Reportes de ventas y compras»**, que en el menú aparecen dentro del grupo Administración. Es de solo lectura. No ve usuarios, empresas, sucursales, firma ni puntos de emisión.
- **Los textos del RIDE en PDF se leen con `pdftotext`** si está instalado en el equipo. Si no, la prueba igual comprueba los datos del RIDE y que el archivo sea un PDF.

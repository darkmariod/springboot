# PROMPT — Los 3 comprobantes que faltan (guía, nota de débito, liquidación)

Objetivo: completar los comprobantes que faltan para cerrar el plan Profesional.
Tu librería (`liberias_sri/`) YA los soporta. Estos son los tipos y bloques exactos.

## Patrón a copiar (ya funciona)
`app/Http/Controllers/CreditNoteController.php` arma así y funciona:
```php
$data = [
    'infoTributaria'  => ['codDoc' => '04'],           // el resto lo completa EmitirSriDocument
    'infoNotaCredito' => [ /* campos propios */ ],
    'detalles'        => [ /* ítems */ ],
    'infoAdicional'   => [ /* opcional */ ],
];
app(\App\Actions\EmitirSriDocument::class)->execute($modelo, 'notaCredito', $company, $data);
```
`EmitirSriDocument` ya completa `infoTributaria` (ambiente, ruc, estab, ptoEmi, secuencial, dirMatriz)
y hace firmar → enviar → autorizar. **Solo tenés que armar el bloque `info*` y los `detalles`.**

Tipos que acepta `generarXml` (confirmado en la librería):
`factura`, `comprobanteRetencion`, `guiaRemision`, `notaDebito`, `notaCredito`, `liquidacionCompra`.

---

## 1) Liquidación de compra — tipo `liquidacionCompra`, codDoc `03`
El emisor le compra a alguien que no emite factura. El "proveedor" ocupa el lugar del cliente.

Bloque `infoLiquidacionCompra`:
```
fechaEmision, dirEstablecimiento, obligadoContabilidad,
tipoIdentificacionProveedor, razonSocialProveedor, identificacionProveedor, direccionProveedor,
totalSinImpuestos, totalDescuento,
totalConImpuestos: [ { codigo, codigoPorcentaje, baseImponible, valor } ],
importeTotal, moneda: 'DOLAR',
pagos: [ { formaPago, total } ]
```
`detalles`: `[ { codigoPrincipal, descripcion, cantidad, precioUnitario, descuento,
precioTotalSinImpuesto, impuestos:[{codigo, codigoPorcentaje, tarifa, baseImponible, valor}] } ]`

Controlador: `LiquidacionCompraController@emitir` → `execute($compra, 'liquidacionCompra', $company, $data)`.

---

## 2) Nota de débito — tipo `notaDebito`, codDoc `05`
Cobra un valor extra sobre un comprobante ya emitido (intereses, gastos). Referencia al documento original.

Bloque `infoNotaDebito`:
```
fechaEmision,
tipoIdentificacionComprador, razonSocialComprador, identificacionComprador,
obligadoContabilidad,
codDocModificado: '01', numDocModificado: 'EEE-PPP-SSSSSSSSS', fechaEmisionDocSustento,
totalSinImpuestos,
impuestos: [ { codigo, codigoPorcentaje, tarifa, baseImponible, valor } ],
valorTotal,
pagos: [ { formaPago, total } ]
```
`motivos`: `[ { razon, valor } ]`  ← qué se cobra y cuánto.

Controlador: `NotaDebitoController@emitir` → `execute($factura, 'notaDebito', $company, $data)`.

---

## 3) Guía de remisión — tipo `guiaRemision`, codDoc `06`
Respalda el traslado de mercadería. Es el que más campos nuevos tiene (transportista + destinatarios).

Bloque `infoGuiaRemision`:
```
dirEstablecimiento, dirPartida,
razonSocialTransportista, tipoIdentificacionTransportista, rucTransportista,
obligadoContabilidad,
fechaIniTransporte, fechaFinTransporte, placa
```
`destinatarios`: `[ {
  identificacionDestinatario, razonSocialDestinatario, dirDestinatario, motivoTraslado,
  codDocSustento: '01', numDocSustento: 'EEE-PPP-SSSSSSSSS', numAutDocSustento, fechaEmisionDocSustento,
  detalles: [ { codigoInterno, descripcion, cantidad } ]
} ]`

Controlador: `GuiaRemisionController@emitir` → `execute($guia, 'guiaRemision', $company, $data)`.

---

## Pasos por cada comprobante (los 3 iguales)
1. **Controlador** con `emitir()` que arma `$data` (bloque de arriba) y llama a `EmitirSriDocument`.
2. **Ruta** en `routes/api.php` (grupo autenticado): `Route::post('...', [XController::class,'emitir'])`.
3. **Vista Vue** copiando `CreditNotes.vue` (formulario + lista + botón Emitir).
4. **Registrar** en `modules.ts` (Ventas/Compras) y en `componentMap` de `MainLayout.vue`.
5. Si depende de plan, envolver la ruta con `feature:...` (ver gating).

## Verificación (obligatoria)
Emitir uno de cada tipo en **PRUEBAS** y confirmar **AUTORIZADO** por el SRI.
Si el SRI responde "ERROR SECUENCIAL REGISTRADO", subí el secuencial de la empresa y reintentá
(ya nos pasó en la prueba: no es falla, es que ese número ya se usó).

## Nota importante
Confirmá el nombre EXACTO de cada campo contra los métodos `generarGuiaXml`, `generarNotaDebitoXml`
y `generarLiquidacionCompraXml` de la librería (`liberias_sri/FacturacionElectronica.php`): esos
métodos definen qué claves lee. Los nombres de arriba siguen el estándar del SRI; si la librería usa
una variante, mandá la que la librería espera.

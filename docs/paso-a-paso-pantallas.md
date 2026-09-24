# Pantalla por pantalla — qué llenar y qué decir

Guía para practicar antes de la llamada. Cada pantalla tiene tres partes:
**dónde está**, **qué escribir** (con valores de ejemplo listos para teclear) y **qué decir**.

Practica con los valores de ejemplo. En la llamada, cambias los datos por los del cliente.

> **Orden importante:** primero la empresa, después clientes y artículos, después que entre
> mercadería, y recién ahí facturar. Si facturas antes de que entre stock, el sistema te frena.

---

## 0 · Entrar

**URL:** `http://108.174.152.179:8080/app/`

| Campo | Valor |
|---|---|
| Usuario | `admin@demo.com` |
| Contraseña | la tuya |

Arriba a la izquierda dice **Módulos**: es el lanzador. La barra lateral izquierda tiene todos
los módulos agrupados. Cada módulo se abre en su propia pestaña, así puedes tener varias abiertas.

---

## 1 · La empresa

**Administración → Empresas → botón Editar**

Este es el primer paso siempre. Sin RUC no se puede armar la clave de acceso de la factura.

| Campo | Ejemplo para practicar | En la llamada |
|---|---|---|
| RUC | `0601234567001` | El RUC del cliente (13 dígitos) |
| Razón social | `EMPRESA DE PRUEBA S.A.` | Como sale en su RUC |
| Nombre comercial | `EMPRESA DE PRUEBA` | Como le dicen al negocio |
| Dirección matriz | `Av. Daniel León Borja y Carabobo, Riobamba` | Dirección del local |
| Teléfonos | `032960000` | Su número |
| Régimen | dejar vacío | Solo si es RIMPE |
| Obligado a llevar contabilidad | NO | Lo dice su RUC |
| Ambiente | **1 = Pruebas** | Pruebas hasta que él diga |
| Correo de envío | `facturas@suempresa.com` | Desde dónde salen las facturas |
| Nota al pie | dejar vacío | Un mensaje que sale al pie del RIDE |

**Guardar.**

> "Estos datos salen impresos en cada factura y son los que el SRI valida. Se ponen una sola vez."

**Logo:** botón *Cambiar* → subir un PNG o JPG. Sale en el RIDE. Si el cliente no tiene logo a mano,
sáltalo — no bloquea nada.

---

## 2 · La firma electrónica

**EDocuments → Configuración de firma**

Solo si el cliente ya tiene su `.p12`. Si no lo tiene, salta esta pantalla y dilo claro.

| Campo | Qué poner |
|---|---|
| Archivo firma | El `.p12` del cliente |
| Clave firma | La clave que le dieron con el certificado |
| Ambiente | Pruebas |

El sistema **abre el archivo con esa clave antes de guardarlo**. Si la clave está mal, te avisa ahí
mismo. Muestra el titular y la fecha de vencimiento.

> "Este es tu certificado del Banco Central o Security Data. Sin esto el SRI no recibe nada.
> El sistema verifica que la clave abra el archivo antes de aceptarlo — no te deja facturar a ciegas."

**Sin firma cargada**, las facturas se generan y se quedan en el sistema sin enviarse. Sirve para
mostrar todo el flujo sin tocar el SRI.

---

## 3 · Un cliente

**Catálogo → Clientes → botón `+`** (arriba a la derecha del listado)

Antes de crear, **busca**: escribe la cédula en el filtro de arriba. Si ya existe, no lo dupliques.

Pestaña **General**:

| Campo | Ejemplo | Nota |
|---|---|---|
| Tipo ID | `Cédula` | Elegir primero: define cuántos dígitos acepta |
| Identificación | `1803553062` | Cédula válida, pasa la verificación |
| Razón social | `MARIA FERNANDA GUAMAN LEMA` | Se autocompleta desde el SRI si el SRI responde |
| Nombre comercial | dejar vacío | |
| Dirección | `Av. 11 de Noviembre, Riobamba` | |
| Tipo contacto | `Cliente` | Puede ser cliente y proveedor a la vez |

Pestaña **Contacto**:

| Campo | Ejemplo |
|---|---|
| Teléfono | `0987654321` |
| Email | `cliente@ejemplo.com` |

**Guardar.**

> "Cuando escribo el RUC, el sistema consulta el padrón del SRI y trae la razón social solo.
> Y si la cédula está mal formada, no me deja guardar — mejor que reclame acá y no el SRI."

**Si el SRI no responde** (pasa seguido): el sistema dice *"Carga los datos a mano"*. Di:
*"ahorita el SRI está caído, lo lleno a mano"*. Todo contador ha vivido eso.

Para un cliente con RUC: Tipo ID `RUC`, identificación `0992339930001`, razón social
`DISTRIBUIDORA ANDINA CIA LTDA`.

---

## 4 · Los artículos

**Catálogo → Productos y servicios → botón `+`**

Vas a crear **dos**, y la diferencia entre ellos es lo más importante que vas a explicar.

### 4a · Un servicio (mano de obra)

| Campo | Valor |
|---|---|
| Código | `MANT-PC` |
| Nombre | `Mantenimiento preventivo de computadora` |
| Tipo | **Servicio (sin stock)** |
| Aplica Serie/Lote | `Ninguno` |
| Porcentaje IVA | `15%` |
| Precio venta | `25.00` |

### 4b · Un bien con serie (repuesto)

| Campo | Valor |
|---|---|
| Código | `MEM-8GB` |
| Nombre | `Memoria RAM DDR4 8GB` |
| Tipo | **Bien (lleva stock)** |
| Aplica Serie/Lote | **Serie** |
| Porcentaje IVA | `15%` |
| Precio venta | `32.00` |
| Es combo | NO |
| Días garantía | `90` |

**Guardar** cada uno.

> "Servicio es mi tiempo: no hay nada en bodega, no se agota. Bien es algo físico que compré y tengo
> guardado. Este campo es el que más se equivoca la gente: si pongo la mano de obra como bien,
> el sistema va a querer descontarla de bodega."

El campo **Estado** de abajo te muestra stock y costo promedio. Ahora dice `Stock: 0`. Es normal:
nada entra por esta pantalla.

---

## 5 · Que entre mercadería

**Compras → Registro de Compras → botón `+`**

Un bien no se puede vender si nunca entró. Vas a registrar una compra al proveedor.

Cabecera:

| Campo | Ejemplo |
|---|---|
| Proveedor | buscar y elegir uno (si no hay, créalo en Compras → Proveedores) |
| Fecha emisión | hoy |
| Establecimiento | `001` |
| Punto emisión | `001` |
| No. Comprobante | `000000123` |
| Bodega | `Bodega General` |

Grilla de ítems (escribes el código y se completa solo):

| Código | Cant. | Costo s/IVA |
|---|---|---|
| `MEM-8GB` | `3` | `18.00` |

**Guardar.**

Luego registra una **segunda compra** con otra fecha y otro costo — esto es a propósito:

| Código | Cant. | Costo s/IVA |
|---|---|---|
| `MEM-8GB` | `2` | `23.00` |

> "Compré 3 a $18 y después 2 a $23. Ahora tengo 5 memorias que en total me costaron $100.
> El sistema no guarda dos precios: guarda un costo promedio de $20. Eso es el
> **costo promedio ponderado**, y es lo que exige el SRI."

Vuelve a **Productos** y mira el Estado de `MEM-8GB`: `Stock: 5 · Costo prom.: $20.00`.

---

## 6 · Las series

**Inventario → Garantías por serie**

`MEM-8GB` maneja serie. Tienes 5 en stock, así que necesitas 5 series.

| Campo | Valor |
|---|---|
| Producto | `MEM-8GB` |
| Cuadro "Registrar series" | pega esto, una por línea: |

```
SN-A001
SN-A002
SN-A003
SN-B001
SN-B002
```

**Registrar series.** La tabla las lista como *disponible*.

> "Cada unidad tiene su número. Con esto sé a qué proveedor le compré esta memoria exacta y a
> qué cliente se la vendí. Eso es lo que resuelve un reclamo de garantía."

Si registras menos series que stock, sale un aviso amarillo. Muéstralo si quieres:
*"el sistema no me deja tener unidades sin identificar"*.

---

## 7 · Facturar

**Ventas → Punto de Venta**

| Campo | Qué hacer |
|---|---|
| Buscar por ID | escribe `1803553062` y Enter → carga el cliente |
| Punto emisión | `001-001` |
| Fecha | hoy |
| Vendedor | tu nombre |
| Caja | la que salga |

Agregar artículos en el cuadro **"Escanear código de barras o serie..."**:

1. Escribe `MANT-PC` → Enter. Aparece la línea con $25.
2. Escribe `MEM-8GB` → Enter. Como maneja serie, se abre **"Series por Artículo"**:
   marca `SN-A001` → **Aplicar**.

Forma de pago: **Efectivo**. Botón **Emitir Factura**.

Totales que deben salir:

```
Subtotal 15%    $57.00
IVA 15%          $8.55
TOTAL           $65.55
```

> "Mientras se emite, el sistema armó el XML, lo firmó con el certificado y lo mandó al SRI."
> (Si no hay firma: *"sin firma se genera y se queda acá; con la firma del cliente sale al SRI solo."*)

---

## 8 · Ver e imprimir la factura

**Ventas → Facturas**

La factura aparece en la lista: `001-001-000000001 · PRUEBA · $65.55 · emitida`.

Clic en el **ojito** (columna de la derecha) → se abre el RIDE. Botón **Imprimir / PDF**.

> "Así la recibe tu cliente, con tu logo. Y por correo se le va el XML y el PDF."

Estado SRI:
- `generado` = sin firma, no se envió
- `AUTORIZADO` = el SRI la aceptó, tiene número de autorización

---

## 9 · El kárdex — lo que pasó por debajo

**Inventario → Inventario y kárdex** → clic en la fila de `MEM-8GB`

Se abre su kárdex:

```
FECHA        TIPO      CANT   COSTO U.   SALDO   PROMEDIO
(compra 1)   ingreso   3      $18        3       $18
(compra 2)   ingreso   2      $23        5       $20
(venta)      egreso    1      $20        4       $20
```

> "Esto es el kárdex: el cuaderno de vida del producto. Una línea por cada entrada y cada salida.
> Fíjate: la venta descontó sola. Y el mantenimiento no aparece — es servicio, no toca bodega."

Ahora abre **Garantías por serie**: `SN-A001` dice *vendida*, las otras cuatro *disponible*.

---

## 10 · El asiento contable

**Contabilidad → Libro diario**

Ahí está el asiento de la venta:

```
1.1.03   Cuentas por cobrar clientes    debe $65.55
4.1.01   Ventas                                        haber $57.00
2.1.02   IVA por pagar                                 haber  $8.55
```

> "El cliente me debe $65.55. De eso, $57 son míos y $8.55 no son míos: son del SRI, los estoy
> guardando. Esto se escribió solo cuando emití la factura."

**Contabilidad → Libro mayor** es la misma información agrupada por cuenta.

> "El diario responde *qué pasó el martes*. El mayor responde *cuánto me deben en total*."

---

## 11 · Anular

**Ventas → Facturas → clic en la fila → botón Anular**

Confirma. La factura queda marcada *anulada*, no desaparece.

Ahora muestra las tres cosas que pasaron:

1. **Inventario y kárdex** → `MEM-8GB`: hay una línea nueva de *ingreso* "Anulación". Stock vuelve a 5.
2. **Garantías por serie**: `SN-A001` volvió a *disponible*.
3. **Libro diario**: hay un asiento nuevo "Reversión por anulación". El original **sigue ahí**.

> "El sistema no borra nada. Crea un contra-asiento que invierte el debe y el haber, y deja los dos
> en el libro diario. Si mañana viene una auditoría, se ve la venta, se ve la anulación y se ve
> quién la hizo."

**Si te preguntan por qué no se puede editar una factura:**

> "Porque una factura no se edita. Se anula y se emite una nueva, o se reversa con nota de crédito.
> Si el sistema me dejara editarla, el kárdex y el libro diario quedarían mintiendo."

---

## 12 · La auditoría

**Administración → Auditoría**

Todo lo que hiciste está ahí: creaste un cliente, dos artículos, una compra, una factura, la anulaste.
Quién, qué, cuándo.

> "Si mañana falta mercadería o alguien cambió un precio, hay de dónde agarrarse."

---

## Orden resumido para la llamada

```
Empresas → (Firma) → Clientes → Productos (servicio + bien) → Registro de Compras (×2)
→ Garantías por serie → Punto de Venta → Facturas (ver / imprimir) → Kárdex → Libro diario
→ Anular → Kárdex otra vez → Libro diario otra vez → Auditoría
```

Unos 25 minutos si no te enredas. Practícalo dos veces seguidas antes de la llamada:
la primera para aprender dónde está cada cosa, la segunda para que te salga sin pensar.

---

## Si algo sale mal en vivo

| Pasa esto | Haz esto |
|---|---|
| "Antes de facturar completa los datos de tu empresa" | Te saltaste la pantalla 1. Ve a Empresas. |
| "Stock insuficiente" | Te saltaste la compra (pantalla 5). |
| "El producto maneja series; indique las series a vender" | No marcaste la serie en el POS (pantalla 7, paso 2). |
| "Serie no existe o ya fue vendida" | No registraste las series (pantalla 6). |
| El SRI no autocompleta el cliente | El SRI está caído. Llena a mano y sigue. |
| La factura sale sin logo | No subiste logo en Empresas. No bloquea nada. |

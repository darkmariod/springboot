# Guion de demostración — HasReset

**Duración:** 25 minutos. **Regla de oro:** no muestres módulos, muestra el negocio del cliente.

---

## 0 · Cinco minutos antes (solo tú)

| Revisar | Cómo |
|---|---|
| El sistema abre | `http://108.174.152.179:8080/app/` |
| Sesión iniciada | `admin@demo.com` |
| Está vacío | El resumen debe decir $0.00 y "Aún no hay facturas" |
| Internet | La firma al SRI necesita conexión |

Ten a mano un producto real del cliente (nombre, precio, cuánto tiene en bodega) y su RUC.
Si se lo preguntas en vivo, la demo se siente hecha para él.

---

## 1 · Apertura · 1 min

> "Esto es un sistema de facturación electrónica e inventario para el SRI.
> Lo que vas a ver no es una presentación: está vacío, y lo vamos a llenar
> con datos de tu negocio ahora mismo."

**Por qué funciona:** un sistema vacío que se llena en vivo demuestra que es real.
Uno lleno de datos de mentira demuestra lo contrario.

---

## 2 · La empresa · 3 min

**Módulos → Administración → Empresas**

Llena el RUC, la razón social y la dirección **del cliente**, delante de él.

> "Estos datos son los que van a salir impresos en cada factura y los que
> el SRI valida. Se ponen una sola vez."

---

## 3 · La firma electrónica · 2 min

**Módulos → EDocuments → Configuración de firma**

> "Este es el archivo .p12 que compraste en el Banco Central o en Security Data.
> Es tu firma. Sin esto el SRI no recibe nada, y el sistema te avisa si la clave
> está mal o si la firma ya venció — no te deja facturar a ciegas."

**Si preguntan cuánto cuesta:** el certificado lo compra él, dura 1 o 2 años, cuesta entre $25 y $50. No lo vendes tú.

---

## 4 · Un cliente y un producto · 3 min

**Catálogo → Clientes** → crea uno con el RUC o cédula real.

> "El sistema valida la cédula y el RUC antes de guardar. Si está mal, el SRI
> devuelve la factura y pierdes el día. Mejor que reclame acá."

**Catálogo → Productos y servicios** → crea el producto real del cliente,
con precio y stock inicial.

---

## 5 · La factura · 5 min — *este es el momento fuerte*

**Ventas → Punto de Venta** → selecciona el cliente, agrega el producto, cobra.

Mientras se envía, explica qué está pasando:

> "Ahora mismo el sistema armó el XML, lo firmó con tu certificado, se lo mandó
> al SRI y está esperando la respuesta."

**Ventas → Facturas** → muestra el estado **AUTORIZADO** y el número de autorización.

> "Ese número es la autorización del SRI. Con eso la factura ya existe legalmente."

Dale a **Imprimir**. Muestra el RIDE con el logo del cliente.

> "Así la recibe tu cliente, con tu logo. Y por correo se le va el XML y el PDF."

**Si preguntan:** hoy está en ambiente de PRUEBAS. Se cambia a PRODUCCIÓN con un clic, cuando él diga.

---

## 6 · Inventario y kárdex · 5 min — *el que cierra la venta*

**Inventario → Inventario y kardex** → busca el producto que acaban de vender.

> "Fíjate: la venta ya descontó el stock. No hay que registrar nada aparte."

Abre el kárdex del producto y explícalo línea por línea:

> "Esto es el kárdex. Es el cuaderno de vida del producto: una línea por cada
> entrada y cada salida. Cuándo, cuánto, a qué costo, y con cuánto quedaste.
>
> Esta columna es el **costo promedio ponderado**. Si compraste 100 a $10 y
> después 100 a $20, no tienes dos precios: tienes 200 unidades que te costaron
> $3.000, o sea $15 cada una. Ese es el costo al que se valora cada venta.
> Es el método que exige el SRI, y es de donde sale tu utilidad real."

**Registra una compra con fecha atrasada** (Compras → Registro de Compras, fecha de la semana pasada).
Vuelve al kárdex.

> "Mira lo que acaba de pasar. Cargué una compra con fecha vieja, y el sistema
> reordenó el kárdex por fecha y **recalculó el costo de las ventas posteriores**.
> Eso pasa todo el tiempo en la vida real: la factura del proveedor llega tarde.
> Si el sistema no lo corrige solo, tu costo de ventas queda mal y declaras mal."

**Esa frase es la que vende.** Es el detalle que ningún competidor muestra.

---

## 7 · Anular una factura · 4 min — *el que te da credibilidad*

**Ventas → Facturas** → clic en la fila → botón **Anular**.

No muestres el botón: muestra lo que pasa por debajo. Eso es lo que ella quiere saber.

**Primero el stock.** Vuelve al kárdex del producto.

> "Fíjate: el stock volvió solo. Estaba en 38, volvió a 40."

**Después el libro diario.** Contabilidad → Libro diario. Ahí están los dos asientos:

```
AS-000001   Venta factura 001-001-000000001                    debe 80.21   haber 80.21
AS-000002   Reversión por anulación factura 001-001-000000001  debe 80.21   haber 80.21
```

**La frase completa, apréndetela:**

> "El sistema no borra nada. Crea un **contra-asiento** que invierte el debe y el haber
> del original, y deja los dos en el libro diario. En el kárdex hace lo mismo: la salida
> se queda y entra un ingreso que la compensa.
>
> Así, si mañana viene una auditoría, se ve la venta, se ve la anulación y se ve quién
> la hizo. Un sistema que borra el asiento te deja sin cómo explicar el hueco."

**Borrar es fácil. Reversar es lo correcto.** Esa es la diferencia entre un sistema serio y uno hecho a la ligera.

---

### La pregunta que viene después

Te va a preguntar: *"¿y si ya está autorizada por el SRI?"*

Ahí el sistema **no te deja anular**. Sale este mensaje:

> *"Una factura autorizada por el SRI se reversa con Nota de Crédito, no se anula."*

Y está bien que no te deje:

> "Una vez que el SRI autorizó, ese comprobante ya existe legalmente. Anularlo por dentro
> sería mentirle a mi propia contabilidad mientras el SRI lo sigue teniendo registrado.
>
> Antes de la autorización, se anula. Después, se reversa con Nota de Crédito.
> El sistema no me deja equivocarme en eso."

---

### Si te pregunta por qué no se puede EDITAR una factura

Esta es tu mejor respuesta del día. No te disculpes: es una decisión, no una falta.

> "Porque una factura no se edita. Se anula y se emite una nueva, o se reversa con nota
> de crédito. Si el sistema me dejara editarla, el kárdex y el libro diario quedarían
> mintiendo."

Lo mismo aplica a notas de crédito, retenciones y guías de remisión: **se anulan, no se editan ni se borran.**

Los datos maestros sí tienen las cuatro operaciones completas: clientes, productos, bodegas,
sucursales, bancos, plan de cuentas, proveedores, empleados, series, puntos de emisión,
usuarios y cotizaciones.

**Preparación:** deja una factura emitida SIN autorizar para poder anularla en vivo.

---

## 8 · La auditoría · 2 min

**Administración → Auditoría**

> "Todo lo que pasa acá queda registrado: quién, qué, cuándo. Si mañana falta
> mercadería o alguien cambió un precio, hay de dónde agarrarse."

**Si hay un contador presente**, muestra esto desde la terminal:

```bash
docker exec contable-backend php artisan inventario:auditar
```

> "Este comando revisa producto por producto que el kárdex cuadre con el stock
> y con las bodegas, que nada esté en negativo, y que ningún movimiento haya
> quedado sin bodega. Si algo se descuadra, lo reconstruye."

---

## 9 · Cierre · 3 min

> "Son tres planes, todos con soporte:
>
> **Básico $85 al año** — inventario, punto de venta y reportes. Sin facturación al SRI.
> **Negocio $145** — todo lo anterior más facturación electrónica, conciliaciones y usuarios con permisos.
> **Completo $225** — más contabilidad, nómina y multisucursales.
>
> También hay pago semestral: $55, $92 y $142."

Cierra con una pregunta concreta, no con "¿qué te parece?":

> "¿Con cuántos productos arrancarías?"

---

## Preguntas que te van a hacer

| Pregunta | Respuesta |
|---|---|
| ¿Y si se cae el internet? | El sistema guarda la factura y la reenvía al SRI cuando vuelva la conexión. |
| ¿Mis datos dónde quedan? | En tu propio servidor. No los comparto con nadie. |
| ¿Puedo probarlo antes? | Sí, te lo dejo funcionando con tus productos por unos días. |
| ¿Y si necesito algo distinto? | Se puede. Lo conversamos y te digo si entra o cuánto cuesta. |
| ¿Quién me capacita? | Yo. Una sesión al entregar, y quedas con el manual. |
| ¿Sirve para dos locales? | Sí, en el plan Completo. Cada local factura con su propio establecimiento. |
| ¿Puedo editar una factura? | No, y a propósito. Se anula o se reversa con nota de crédito. Editar dejaría mintiendo al kárdex y al libro diario. |
| ¿Y si me equivoqué en el precio? | La anulas y emites una nueva. Queda el rastro de las dos. |
| ¿Se puede borrar algo del sistema? | Los datos maestros sí (un cliente, un producto). Los documentos contables no: se reversan. |

---

## Lo que NO debes hacer

- **No abras módulos vacíos** (Nómina, Centros de costo) si el cliente no los necesita. Un módulo vacío mata la credibilidad.
- **No prometas fechas** que no puedes cumplir.
- **No digas "eso lo agrego rapidito"**. Di "lo reviso y te confirmo mañana".
- **No hables de precios** hasta el final. Primero que vea el valor.

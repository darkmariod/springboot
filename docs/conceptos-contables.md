# Cómo funciona el sistema por dentro — guía de estudio

Todo lo de acá está tomado de una factura real del sistema: **001-001-000000002**,
un mantenimiento de computadora. Los números no son inventados.

---

## Parte 1 — El flujo completo, desde cero

### Paso 1 · Crear los artículos

**Catálogo → Productos y servicios → botón `+`**

Se crearon dos, y la diferencia entre ellos es lo más importante de entender:

| Código | Descripción | Tipo | ¿Lleva stock? | ¿Serie? |
|---|---|---|---|---|
| `MANT-PC` | Mantenimiento preventivo de computadora | **servicio** | No | No |
| `MEM-8GB` | Memoria RAM DDR4 8GB | **bien** | Sí | Sí |

> **Servicio** es tu tiempo y tu trabajo. No hay nada en bodega, no se agota,
> no tiene costo de compra. Facturas mano de obra.
>
> **Bien** es algo físico que compraste y tienes guardado. Se cuenta, se agota,
> tiene un costo.

Poner mal este campo es el error más común: si marcas la mano de obra como "bien",
el sistema va a querer descontarla de bodega y te va a frenar la venta.

### Paso 2 · Que entre mercadería

Los servicios no necesitan este paso. Los bienes sí: **algo no se puede vender si nunca entró.**

**Compras → Registro de Compras** (o Inventario → Ajuste Inventario para la carga inicial)

Se hicieron dos compras a propósito, a distinto precio:

- 1 de septiembre: **3 unidades a $18**
- 3 de septiembre: **2 unidades a $23**

### Paso 3 · Registrar las series

**Inventario → Garantías por serie**

Como `MEM-8GB` maneja serie, cada unidad necesita la suya: `SN-A001`, `SN-A002`,
`SN-A003`, `SN-B001`, `SN-B002`. Cinco unidades, cinco series.

### Paso 4 · Facturar

**Ventas → Punto de Venta** → cliente, artículos, cobrar.

| Artículo | Cant | P. Unitario | Total |
|---|---|---|---|
| MANT-PC — Mantenimiento preventivo | 1 | $25.00 | $25.00 |
| MEM-8GB — Memoria RAM DDR4 8GB | 1 | $32.00 | $32.00 |
| | | Subtotal | **$57.00** |
| | | IVA 15% | **$8.55** |
| | | **TOTAL** | **$65.55** |

### Paso 5 · Lo que el sistema hizo solo

Esto es lo que no ves, y es lo que hay que entender:

1. **Descontó la memoria** del stock: de 5 quedó en 4.
2. **Marcó la serie `SN-A001` como vendida.** Las otras cuatro siguen disponibles.
3. **NO tocó el mantenimiento** — es un servicio, no hay nada que descontar.
4. **Escribió el asiento contable** en el libro diario.
5. **Generó el XML** de la factura electrónica.

---

## Parte 2 — Los términos, uno por uno

### Movimiento

Cada vez que algo entra o sale de bodega, el sistema escribe un **movimiento**.
Solo existen dos clases:

- **Ingreso** — entra. Una compra, una devolución de un cliente, un ajuste a favor.
- **Egreso** — sale. Una venta, una baja por daño.

Nada más. Todo el inventario del sistema son ingresos y egresos.

### Kárdex

El **kárdex** es la lista completa de movimientos de un producto, en orden de fecha.
Es el cuaderno de vida del artículo. Este es el real de `MEM-8GB`:

```
FECHA        TIPO      CANT   COSTO U.   SALDO   PROMEDIO   VALOR
2026-09-01   ingreso   3      $18        3       $18        $54
2026-09-03   ingreso   2      $23        5       $20        $100
2026-09-05   egreso    1      $20        4       $20        $80
```

Se lee de arriba abajo, como una historia. Cada línea dice *qué pasó* y *con cuánto quedaste*.

**Si el kárdex miente, mienten el costo de ventas, la utilidad y el balance.**
Por eso se dice que es el cerebro del sistema.

### Saldo

La columna **SALDO** es cuánto queda **después** de esa línea. No es el stock de hoy:
es el stock en ese punto de la historia. Por eso el orden por fecha importa tanto.

### Costo promedio ponderado

Mira otra vez la segunda línea del kárdex. Compraste a $18 y después a $23,
pero el promedio quedó en **$20**. La cuenta:

```
(3 × $18) + (2 × $23)  =  $54 + $46  =  $100
$100 ÷ 5 unidades      =  $20 cada una
```

No tienes memorias de $18 y memorias de $23. Tienes **cinco memorias que en total te
costaron $100**, o sea $20 cada una. Ese $20 es el costo al que se valora cada venta.

Es el método que exige el SRI en Ecuador, y es de donde sale tu utilidad real:
vendiste a $32, te costó $20, ganaste $12.

### Valorizar una salida

Cuando vendes, el sistema anota **cuánto te costó a ti** lo que vendiste — no a cuánto
lo vendiste. Ese número es el **costo de ventas**. Ingresos menos costo de ventas = utilidad.
Si está mal, declaras mal y pagas mal el impuesto.

### Debe y Haber

Toda la contabilidad se escribe en dos columnas, y **siempre tienen que sumar igual**.
No es una regla caprichosa: es la forma de detectar errores. Si no cuadra, hay un error.

### Asiento contable

Un **asiento** es una anotación con sus dos columnas cuadradas. El de nuestra factura:

```
AS-000003   Venta factura 001-001-000000002

CUENTA    NOMBRE                          DEBE      HABER
1.1.03    Cuentas por cobrar clientes     $65.55    $0
4.1.01    Ventas                          $0        $57.00
2.1.02    IVA por pagar                   $0        $8.55
          TOTALES                         $65.55    $65.55
```

Leído en cristiano:

> El cliente me debe $65.55 (o ya me pagó). De eso, $57 son míos por la venta,
> y $8.55 no son míos: son del SRI, los estoy guardando para entregarlos.

Ese IVA por pagar es plata que no te pertenece. Muchos negocios pequeños se la
gastan y después no tienen con qué declarar.

### Libro diario

El **libro diario** son todos los asientos, en orden de fecha. Como un diario personal:
qué pasó cada día, uno tras otro.

```
AS-000001   Venta factura 001-001-000000001                    $80.21   $80.21
AS-000002   Reversión por anulación factura 001-001-000000001  $80.21   $80.21
AS-000003   Venta factura 001-001-000000002                    $65.55   $65.55
```

### Libro mayor

El **libro mayor** es exactamente la misma información, pero agrupada **por cuenta**
en vez de por fecha.

```
CUENTA    NOMBRE                          DEBE      HABER     SALDO
1.1.03    Cuentas por cobrar clientes     $145.76   $80.21    $65.55
2.1.02    IVA por pagar                   $10.46    $19.01    $8.55
4.1.01    Ventas                          $69.75    $126.75   $57.00
```

**La diferencia, en una frase:**

> El **diario** responde *"¿qué pasó el martes?"*
> El **mayor** responde *"¿cuánto me deben en total?"* o *"¿cuánto le debo al SRI?"*

Son los mismos datos vistos de dos maneras. El diario es cronológico, el mayor es por tema.
Fíjate que la venta anulada aparece en el diario dos veces (venta y reversión), y en el
mayor ya se cancelaron entre sí. Por eso el saldo de Ventas es $57 y no $126.75.

---

## Parte 3 — Las series

**Inventario → Garantías por serie**

Sirven para cosas con número único: celulares, computadoras, memorias, motores.
Te permiten saber **a qué proveedor le compraste esa unidad exacta y a qué cliente se la vendiste.**
Eso es lo que resuelve un reclamo de garantía.

Las cuatro operaciones:

| Operación | Cómo |
|---|---|
| **Crear** | Eliges el producto, pegas los seriales (uno por línea) y das *Registrar series*. Puedes escanear seguido o pegar desde Excel. |
| **Ver** | La tabla los lista con su estado, la compra de origen y la factura de venta. |
| **Editar** | Botón de advertencia para marcar una unidad como dañada, o la flecha para devolverla a disponible. |
| **Eliminar** | El basurero. **Bloqueado si la serie ya se vendió** — esa unidad está en una factura y borrarla dejaría un hueco. |

También se crean solas al registrar una compra, si escribes los seriales ahí.

**El aviso importante:** si las series disponibles no coinciden con el stock, la pantalla
te avisa. Cada unidad en bodega debe tener su serie. Si tienes 5 en stock y 3 series,
hay dos memorias que nadie sabe cuáles son.

En nuestra factura, `SN-A001` quedó **vendida** y las otras cuatro **disponibles**.

---

## Parte 4 — Eliminar o anular una factura

Esta es la parte que más confunde, así que va despacio.

### Una factura no se elimina. Nunca.

Eliminar significa que desaparece y nadie sabe que existió. Eso, en contabilidad, es un hueco:
tu numeración salta del 5 al 7 y no puedes explicar qué pasó con el 6.
Un sistema que te deja borrar facturas es un sistema mal hecho.

### Lo que sí se hace: anular

**Ventas → Facturas** → clic en la fila → botón **Anular**.

La factura **no desaparece**. Queda ahí, marcada como *anulada*. Y el sistema hace tres cosas:

**1 · Devuelve el stock.** Se escribe un movimiento nuevo de ingreso.
La salida original NO se borra:

```
MEM-8GB   egreso    1   Venta 001-001-000000002       saldo 4
MEM-8GB   ingreso   1   Anulación 001-001-000000002   saldo 5
```

**2 · Libera las series.** `SN-A001` vuelve a *disponible*.

**3 · Crea un contra-asiento.** Un asiento nuevo que invierte el debe y el haber
del original. El original **se queda** en el libro diario:

```
AS-000003   Venta factura 001-001-000000002                    $65.55   $65.55
AS-000004   Reversión por anulación factura 001-001-000000002  $65.55   $65.55
```

Los dos se anulan entre sí en el libro mayor, pero **los dos siguen ahí**.

### Por qué no se borra el asiento

> Si mañana viene una auditoría, se ve la venta, se ve la anulación, y se ve quién la hizo.
> Un sistema que borra el asiento te deja sin cómo explicar el hueco.

**Borrar es fácil. Reversar es lo correcto.**

### Y si el SRI ya la autorizó

Ahí el sistema **no te deja anular**:

> *"Una factura autorizada por el SRI se reversa con Nota de Crédito, no se anula."*

Y está bien que no te deje. Una vez que el SRI autorizó, ese comprobante existe legalmente
en los servidores del SRI. Anularlo por dentro sería mentirle a tu propia contabilidad
mientras el SRI lo sigue teniendo registrado.

**La regla, en una línea:**

> Antes de la autorización → se **anula**.
> Después de la autorización → se reversa con **Nota de Crédito**.

### Nota de crédito, en cristiano

Es un documento que le dice al SRI *"de la factura tal, devuélveme tanto"*.
Se usa cuando el cliente devuelve mercadería, cuando hubo un error de precio,
o cuando le das un descuento después de facturar.

No borra la factura: la corrige, dejando el rastro de las dos.

---

## Qué revisar cuando algo no cuadra

```bash
docker exec contable-backend php artisan inventario:auditar
docker exec contable-backend php artisan contable:chequeo
```

El primero revisa que el kárdex cuadre con el stock y las bodegas.
El segundo, que todos los asientos cuadren y que la ecuación contable dé.

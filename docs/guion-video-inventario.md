# Guion para grabar el video — Inventario y multisucursal

**Sistema listo en** `http://108.174.152.179:8080/app/`
**Duración objetivo:** 8 a 10 minutos.

Todos los números de esta guía son los que están cargados ahora mismo. Si sigues los
pasos en orden, lo que ves en pantalla coincide con lo que dice el guion.

> **Antes de grabar:** cierra todas las pestañas del sistema menos Módulos (`F8` las cierra
> una por una). Así el video arranca limpio.

---

## Lo que está cargado

```
LABORATORIO CLINICO SAN RAFAEL · RUC 0691234567001

5 sucursales, cada una es un establecimiento ante el SRI
   001  Matriz - Daniel Leon Borja
   002  Sucursal Norte
   003  Sucursal Sur
   004  Sucursal La Condamine
   005  Sucursal Terminal

1 bodega: "Bodega Central (compartida)" — en la matriz, las 5 descuentan de ella

REA-001  Reactivo hemoglobina 500 ml  $85   30 lotes registrados, mínimo 10
EX-001   Biometría hemática completa  $8    servicio, IVA 0%
```

---

## Bloque 1 · El escritorio · 40 seg

Entra al sistema. Deja la pantalla de módulos unos segundos sin hacer nada.

> "Así arranca el sistema: los módulos como en un escritorio, sin menús escondidos."

Escribe **`inven`** en el buscador.

> "Y si prefiere no buscar con el mouse, escribe. Son cuarenta y nueve pantallas
> y llega a cualquiera con tres letras."

Enter. Se abre Inventario y kardex.

---

## Bloque 2 · El inventario valorizado · 1 min

Se ve la tabla:

```
Código    Producto                      Stock   Costo prom.   Valor
REA-001   Reactivo hemoglobina 500 ml     25      $57.33     $1433.33
```

Arriba a la derecha: **VALOR DEL INVENTARIO $1433.33**

> "Esto no es una lista de cuántas cosas tengo. Es cuánta plata tengo parada en bodega.
> Veinticinco frascos, a cincuenta y siete con treinta y tres cada uno: mil cuatrocientos
> treinta y tres dólares."

**Pausa ahí.** Ese número es el que le interesa al dueño.

---

## Bloque 3 · El kárdex · 2 min — *el bloque más importante*

Clic en la fila del producto. Se abre el kárdex:

```
FECHA        TIPO      CONCEPTO                                    CANT  SALDO  C.PROM
2026-09-10   ingreso   Compra a proveedor - factura ...004521       +20    20   $55.00
2026-09-18   ingreso   Compra a proveedor - factura ...004698       +10    30   $57.33
2026-09-24   egreso    Venta 003-001-000000001                       -1    29   $57.33
2026-09-24   egreso    Venta 004-001-000000002                       -1    28   $57.33
2026-09-24   egreso    Venta 002-001-000000001                       -1    27   $57.33
2026-09-24   egreso    Venta 001-001-000000001                       -1    26   $57.33
2026-09-24   egreso    Venta 005-001-000000001                       -1    25   $57.33
```

Explícalo por partes, sin apuro.

**Primero las dos compras:**

> "Miren las dos primeras líneas. La primera compra fue de veinte frascos a cincuenta y
> cinco dólares. La segunda, de diez a sesenta y dos.
>
> Pero fíjense en la columna del costo promedio: dice cincuenta y siete con treinta y tres.
> El sistema no guarda dos precios distintos. Guarda que tengo treinta frascos que en
> total me costaron mil setecientos veinte dólares, o sea cincuenta y siete con treinta y
> tres cada uno.
>
> Ese es el **costo promedio ponderado**, que es el método que exige el SRI. Y es de donde
> sale su utilidad real: si usted no sabe a cuánto le costó, no sabe cuánto ganó."

**Después las cinco ventas — aquí está la prueba del multisucursal:**

> "Ahora miren las cinco líneas de abajo. Fíjense en los números de factura:
> **cero cero tres**, **cero cero cuatro**, **cero cero dos**, **cero cero uno**, **cero cero cinco**.
>
> Esas son las cinco sucursales. Cada una facturó con su propio establecimiento ante el SRI,
> y las cinco descontaron del mismo stock: veintinueve, veintiocho, veintisiete, veintiséis,
> veinticinco.
>
> Un solo inventario para los cinco locales. Nadie vende algo que otro ya vendió."

**Ese es el momento que cierra la venta.** No lo apures.

---

## Bloque 4 · Los lotes · 2 min

`F4` → escribe **`garan`** → Enter. Se abre Garantías por serie, con `REA-001` elegido.

```
LOTE-A240001   vendida      001-001-000000001
LOTE-A240002   vendida      002-001-000000001
LOTE-A240003   vendida      003-001-000000001
LOTE-A240004   vendida      004-001-000000002
LOTE-A240005   vendida      005-001-000000001
LOTE-A240006   disponible        —
...
Mostrando 30 de 30 · 25 disponible(s)
```

> "Cada frasco de reactivo entra con su número de lote. Y fíjense en la columna de la
> derecha: el sistema sabe **de qué sucursal salió cada lote**.
>
> Si mañana el proveedor le avisa que el lote A dos cuatro cero cero cero tres salió
> defectuoso, usted lo busca y en dos segundos sabe que se usó en la Sucursal Sur,
> en esa factura, con ese paciente. Sin revisar cuadernos."

Muestra el cuadro de **Registrar series** a la derecha.

> "Y cuando llega mercadería nueva, los lotes se pegan aquí de corrido, uno por línea.
> Se pueden escanear seguidos o pegar desde un Excel."

**Un detalle que conviene mostrar:** los lotes ya vendidos **no se pueden borrar**.

> "El basurero está bloqueado en los lotes vendidos. Ese lote está dentro de una factura;
> borrarlo dejaría un hueco que después nadie puede explicar."

---

## Bloque 5 · Las sucursales · 1 min

`F4` → escribe **`sucur`** → Enter.

```
Establecimiento   Nombre                       Facturas   Estado
001  [Matriz]     Matriz - Daniel Leon Borja       1       Activa
002               Sucursal Norte                   1       Activa
003               Sucursal Sur                     1       Activa
004               Sucursal La Condamine            1       Activa
005               Sucursal Terminal                1       Activa
```

> "Cada sucursal es un establecimiento ante el SRI, con su propio código. Ese código
> es el que sale al inicio del número de cada factura.
>
> Y la bodega es una sola, la central, en la matriz. Los cinco locales facturan contra
> ella. Si uno vende, a los otros cuatro les baja el stock."

---

## Bloque 6 · La prueba dura · 1 min — *si quieren ver que no se rompe*

Este bloque es opcional, pero si preguntan *"¿y si dos venden al mismo tiempo?"*, tienes
la respuesta probada:

> "Probamos las cinco sucursales facturando **en el mismo segundo** el mismo producto.
> Las cinco facturas entraron, el stock bajó de treinta a veinticinco, y el kárdex quedó
> sin un solo saldo repetido.
>
> Y cuando dos sucursales intentaron vender **el mismo frasco**, el sistema rechazó la
> segunda: *'esa serie ya fue vendida'*. No deja vender dos veces la misma unidad."

---

## Bloque 7 · Cierre · 40 seg

> "Eso es el inventario: un solo stock para los cinco locales, con costo promedio
> ponderado, lotes rastreables y control de quién vendió qué.
>
> Todo lo que vieron se escribió solo. Nadie registró nada a mano."

---

## Lo que NO debes decir en este video

**No digas "tiempo real" así, suelto.** El stock es uno solo y siempre está correcto,
pero la pantalla de otra sucursal **no se actualiza sola**: hay que recargar. Si ellos
ponen dos pantallas lado a lado, te agarran.

La forma correcta de decirlo:

> "El stock es uno solo: cuando la otra sucursal consulta, ve el número ya actualizado."

Eso es verdad. *"Se actualiza solo en la pantalla"* todavía no lo es.

**No muestres roles ni permisos todavía.** Los roles existen en la pantalla de usuarios,
pero hoy no limitan lo que cada uno ve. Si en el video dices que el doctor solo ve
exámenes, es falso. Eso está pendiente de construir.

**No abras Nómina ni Centros de costo.** Están vacíos.

---

## Si algo sale mal mientras grabas

| Pasa esto | Es porque |
|---|---|
| "Stock insuficiente" | Te quedaste sin lotes disponibles. Quedan 25. |
| "Serie no existe o ya fue vendida" | Elegiste un lote ya vendido (001 al 005). Usa del 006 en adelante. |
| "Antes de facturar completa los datos de tu empresa" | Alguien vació la empresa. Avísame. |
| La factura no sale AUTORIZADO | No hay certificado cargado. Es lo esperado hoy. |

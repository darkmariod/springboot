# Guía completa — Laboratorio clínico, flujo de punta a punta

**Sistema:** `http://108.174.152.179:8080/app/`

Esta guía recorre el sistema entero en el orden en que se usa. Cada paso trae **qué ver
en pantalla** (con los valores reales que están cargados) y **qué decir** si lo estás grabando.

> **Para las capturas:** en Mac, `Cmd + Shift + 4` y arrastra sobre la zona. En Windows,
> `Win + Shift + S`. Toma la captura en el momento que la guía dice **[CAPTURA]**.

---

## Usuarios cargados

| Correo | Nombre | Rol | Qué ve |
|---|---|---|---|
| `admin@demo.com` | Administrador | admin | Todo |
| `contador@demo.com` | Contadora | contador | Todo menos Empresas, Usuarios, Sucursales y la firma |
| `doctor@demo.com` | Dr. Laboratorio | cajero | Solo Punto de Venta, Facturas, Cotizaciones y Clientes |

Clave de los dos últimos: `demo1234`

---

# PARTE 1 — Cómo queda configurado (lo hace el administrador, una sola vez)

## Paso 1 · Entrar

Abre `http://108.174.152.179:8080/app/` con `admin@demo.com`.

**[CAPTURA 1]** — La pantalla de módulos.

> "Así arranca el sistema. Los módulos como en un escritorio, sin menús escondidos.
> Y si prefiere no buscar con el mouse, escribe: son cuarenta y nueve pantallas y llega
> a cualquiera con tres letras."

Escribe `inven` en el buscador para mostrarlo. Luego `Esc`.

---

## Paso 2 · La empresa

`F4` → escribe `empre` → Enter.

```
RUC             0691234567001
Razón social    LABORATORIO CLINICO SAN RAFAEL
Nombre comercial SAN RAFAEL
Dirección       Av. Daniel Leon Borja y Carabobo, Riobamba
Teléfonos       0988285262
Ambiente        1 = Pruebas
```

**[CAPTURA 2]** — La ficha de la empresa.

> "Estos datos salen impresos en cada factura y son los que el SRI valida.
> Se ponen una sola vez."

**El certificado:** botón **Cargar .p12**. Ahí sube el archivo que le dio el Banco Central
o Security Data, con su clave.

> "El sistema abre el archivo con esa clave antes de aceptarlo. Si la clave está mal,
> le avisa ahí mismo. No le deja facturar a ciegas."

---

## Paso 3 · Las 5 sucursales

`F4` → `sucur` → Enter.

```
Establecimiento   Nombre                        Facturas   Estado
001  [Matriz]     Matriz - Daniel Leon Borja        1       Activa
002               Sucursal Norte                    1       Activa
003               Sucursal Sur                      1       Activa
004               Sucursal La Condamine             1       Activa
005               Sucursal Terminal                 1       Activa
```

**[CAPTURA 3]**

> "Cada sucursal es un establecimiento ante el SRI, con su propio código. Ese código sale
> al inicio del número de cada factura: la Sucursal Sur factura cero cero tres guión cero
> cero uno, y así.
>
> Se agregan con el botón de arriba. Si mañana abren la sexta, toma dos minutos."

---

## Paso 4 · La bodega compartida

`F4` → `bodeg` → Enter.

```
CEN    Bodega Central (compartida)    Por defecto: Sí
```

**[CAPTURA 4]**

> "Una sola bodega para los cinco locales. Cuando cualquiera vende, descuenta de aquí.
> Así ninguno vende algo que otro ya usó."

---

## Paso 5 · Usuarios y roles — *el que pidieron*

`F4` → `usuar` → Enter.

```
Nombre             Correo                Rol
Administrador      admin@demo.com        Administrador (todo)
Contadora          contador@demo.com     Contador (todo menos administración)
Dr. Laboratorio    doctor@demo.com       Cajero / Recepción (solo vende)
```

**[CAPTURA 5]**

> "Tres niveles. El administrador ve todo. El contador lleva la contabilidad pero no puede
> tocar la empresa, ni los usuarios, ni las sucursales. Y el doctor o la recepcionista
> solo atiende y factura: no ve contabilidad, no ve costos, no ve inventario."

**Esto se demuestra en la Parte 4.** No lo explico más aquí — se ve mejor entrando con cada uno.

---

# PARTE 2 — El inventario (el corazón del sistema)

## Paso 6 · Los artículos

`F4` → `produ` → Enter.

```
EX-001   Biometría hemática completa    servicio   $8.00   IVA 0%
REA-001  Reactivo hemoglobina 500 ml    bien      $85.00   con lote
```

Abre `EX-001` y señala dos campos.

**[CAPTURA 6]** — La ficha del examen.

> "Tipo: **servicio**. Y el IVA en **cero por ciento**.
>
> Los servicios de salud van con tarifa cero, como manda la ley. Si el sistema le pusiera
> quince por ciento a una biometría, el SRI le rechaza la factura."

Ahora abre `REA-001`.

**[CAPTURA 7]** — La ficha del reactivo.

> "Este es **bien**: lleva stock, se agota, tiene un costo. Y **aplica serie**, que en un
> laboratorio es el lote.
>
> Esa es la diferencia que casi ningún sistema le resuelve: usted **vende** exámenes pero
> **consume** reactivos. Son dos mundos y el sistema los maneja distinto."

Abajo, en Estado: `Stock: 25 · Costo prom.: $57.33`. También está el **stock mínimo: 10**.

> "Cuando baje de diez, el sistema le avisa. No se entera cuando ya no tiene."

---

## Paso 7 · Cómo entra la mercadería

`F4` → `regis` → Enter (Registro de Compras).

Así entraron los 30 frascos, en dos compras:

```
10 de septiembre   20 frascos a $55.00   factura 001-001-000004521
18 de septiembre   10 frascos a $62.00   factura 001-001-000004698
```

**[CAPTURA 8]**

> "Un bien no se puede vender si nunca entró. Aquí se registra la factura del proveedor,
> y al guardarla el stock sube solo."

---

## Paso 8 · Los lotes

`F4` → `garan` → Enter. Producto: `REA-001`.

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

**[CAPTURA 9]**

> "Cada frasco entra con su número de lote. Y miren la columna de la derecha: el sistema
> sabe **de qué sucursal salió cada lote**.
>
> Si mañana el proveedor le avisa que el lote A-dos-cuatro-cero-cero-cero-tres salió
> defectuoso, usted lo busca y en dos segundos sabe que se usó en la Sucursal Sur,
> en esa factura, con ese paciente."

Muestra el cuadro **Registrar series** a la derecha.

> "Cuando llega mercadería nueva, los lotes se pegan aquí de corrido, uno por línea.
> Se escanean seguidos o se pegan desde un Excel."

**Un detalle que conviene mostrar:** intenta borrar un lote vendido. El basurero está bloqueado.

> "Ese lote está dentro de una factura. Borrarlo dejaría un hueco que nadie puede explicar."

---

## Paso 9 · El kárdex — *el bloque más importante*

`F4` → `inven` → Enter. Clic en la fila del reactivo.

```
FECHA        TIPO      CONCEPTO                              CANT  SALDO  C.PROM
2026-09-10   ingreso   Compra a proveedor ...004521           +20    20   $55.00
2026-09-18   ingreso   Compra a proveedor ...004698           +10    30   $57.33
2026-09-24   egreso    Venta 003-001-000000001                 -1    29   $57.33
2026-09-24   egreso    Venta 004-001-000000002                 -1    28   $57.33
2026-09-24   egreso    Venta 002-001-000000001                 -1    27   $57.33
2026-09-24   egreso    Venta 001-001-000000001                 -1    26   $57.33
2026-09-24   egreso    Venta 005-001-000000001                 -1    25   $57.33
```

**[CAPTURA 10]** — Esta es la captura más importante de todas.

Explícalo en dos partes, sin apuro.

**Las compras:**

> "La primera compra fue de veinte frascos a cincuenta y cinco. La segunda, de diez a
> sesenta y dos. Pero el costo promedio dice **cincuenta y siete con treinta y tres**.
>
> El sistema no guarda dos precios. Guarda que tiene treinta frascos que en total le
> costaron mil setecientos veinte dólares, o sea cincuenta y siete con treinta y tres
> cada uno. Ese es el **costo promedio ponderado**, el método que exige el SRI.
>
> Y es de donde sale su utilidad real: si no sabe a cuánto le costó, no sabe cuánto ganó."

**Las ventas — aquí está la prueba del multisucursal:**

> "Ahora miren los números de factura: cero-cero-tres, cero-cero-cuatro, cero-cero-dos,
> cero-cero-uno, cero-cero-cinco.
>
> Esas son las cinco sucursales. Cada una facturó con su propio establecimiento, y las
> cinco descontaron del mismo stock: veintinueve, veintiocho, veintisiete, veintiséis,
> veinticinco.
>
> **Un solo inventario para los cinco locales.**"

Arriba a la derecha: **VALOR DEL INVENTARIO $1433.33**

> "Y esto no es cuántas cosas tengo. Es cuánta plata tengo parada en bodega."

---

# PARTE 3 — Atender un paciente

## Paso 10 · El paciente

`F4` → `clien` → Enter.

Antes de crear, **busca**: escribe la cédula en el filtro. Si ya existe, no lo dupliques.

Para crear uno nuevo: botón `+` o `F2`.

```
Tipo ID          Cédula
Identificación   1803553062
Razón social     PACIENTE DE PRUEBA
Dirección        Riobamba
Teléfono         0987654321
Email            paciente@ejemplo.com
```

**[CAPTURA 11]**

> "Cuando escribo el RUC, el sistema consulta el padrón del SRI y trae la razón social
> solo. Y si la cédula está mal formada, no me deja guardar: mejor que reclame aquí
> y no el SRI."

**Si el SRI no responde** (pasa seguido), sale *"Carga los datos a mano"*. Dilo de frente:
*"ahorita el SRI está caído, lo lleno a mano"*. Todo contador ha vivido eso.

**También hay un cliente con RUC:** `INDUSTRIAS ANDINAS CIA LTDA`.

> "¿Ustedes atienden empresas, de esas que mandan al personal a exámenes ocupacionales?
> Ese cliente se factura con RUC y queda en cuentas por cobrar. Usted ve en cualquier
> momento cuánto le debe cada empresa y desde cuándo."

---

## Paso 11 · Facturar

`F4` → `punto` → Enter (Punto de Venta).

1. En **Buscar por ID** escribe `1803553062` → Enter. Carga el paciente.
2. Elige el **Punto de Emisión** de la sucursal donde estás.
3. En el cuadro de artículos escribe `EX-001` → Enter.
4. Forma de pago **Efectivo** → **Emitir Factura**.

**[CAPTURA 12]** — El punto de venta con el examen cargado.

```
Subtotal 0%    $8.00
IVA            $0.00
TOTAL          $8.00
```

> "Fíjese: IVA cero. Así sale la factura de un laboratorio."

**Si agregas el reactivo `REA-001`**, se abre el cuadro de lotes: marca uno disponible
(del 006 en adelante) y **Aplicar**.

**[CAPTURA 13]** — El selector de lotes.

> "Como el reactivo lleva lote, el sistema me obliga a decir cuál estoy entregando.
> No me deja facturar sin identificar la unidad."

---

## Paso 12 · Ver e imprimir

`F4` → `factu` → Enter.

**[CAPTURA 14]** — El listado, donde se ven los números de las 5 sucursales.

Clic en el **ojito** de la derecha → se abre el RIDE.

**[CAPTURA 15]** — El RIDE completo.

> "Así la recibe el paciente, con el logo del laboratorio. Y por correo se le va el PDF
> y el XML."

**Sobre el SRI:** hoy está en ambiente de PRUEBAS y sin certificado.

> "Cuando suba su archivo punto-p-doce, la factura sale firmada y autorizada por el SRI
> en segundos. El certificado lo compra usted, cuesta entre veinticinco y cincuenta
> dólares y dura uno o dos años. No se lo vendo yo."

---

## Paso 13 · Anular

`F4` → `factu` → clic en una fila → botón **Anular**.

> "La factura no desaparece: queda marcada como anulada."

Después muestra las tres cosas que pasaron:

1. **Kárdex** → hay una línea nueva de *ingreso* "Anulación". El stock volvió.
2. **Garantías por serie** → el lote volvió a *disponible*.
3. **Libro diario** → hay un asiento nuevo de reversión. **El original sigue ahí.**

**[CAPTURA 16]** — El libro diario con los dos asientos.

> "El sistema no borra nada. Crea un contra-asiento que invierte el debe y el haber
> del original, y deja los dos en el libro diario.
>
> Si mañana viene una auditoría, se ve la venta, se ve la anulación, y se ve quién la hizo.
> Un sistema que borra facturas le deja un hueco en la numeración que después no puede
> explicar."

**Si ya está autorizada por el SRI**, el sistema no deja anular:

> *"Una factura autorizada por el SRI se reversa con Nota de Crédito, no se anula."*

---

# PARTE 4 — Los roles en acción

Esta parte se demuestra **saliendo y entrando con otro usuario**. Es lo que más
impresiona porque se ve, no se explica.

## Paso 14 · Entra como el doctor

Cierra sesión. Entra con `doctor@demo.com` / `demo1234`.

**[CAPTURA 17]** — El escritorio del doctor.

Solo salen **dos módulos: Catálogo y Ventas.**

> "Este es el doctor. No ve Contabilidad. No ve Inventario. No ve costos.
> No ve Administración. Solo lo que necesita para atender."

Entra a **Ventas**.

**[CAPTURA 18]** — Solo Punto de Venta, Facturas y Cotizaciones.

> "Atiende al paciente, factura, imprime. Nada más."

---

## Paso 15 · Entra como la contadora

Cierra sesión. Entra con `contador@demo.com` / `demo1234`.

**[CAPTURA 19]** — El escritorio de la contadora: los diez módulos.

Entra a **Administración**.

**[CAPTURA 20]** — Solo **Auditoría** y **Reportes**.

> "La contadora ve todo lo que necesita para declarar: contabilidad, inventario, compras,
> bancos. Pero dentro de Administración solo tiene Auditoría y Reportes.
>
> No puede cambiar los datos de la empresa, ni crear usuarios, ni tocar las sucursales.
> Eso es del dueño."

**Lo importante, y dilo así:**

> "Y esto no es solo que el menú se vea distinto. El sistema revisa el permiso otra vez
> por dentro. Aunque alguien sepa la dirección exacta de una pantalla, si no le toca,
> le responde que no tiene permiso."

---

## Paso 16 · La auditoría

Entra otra vez como administrador. `F4` → `audit` → Enter.

**[CAPTURA 21]**

> "Todo lo que pasó aquí quedó registrado: quién, qué, cuándo, desde qué equipo.
> Si mañana falta un reactivo o alguien cambió un precio, hay de dónde agarrarse."

---

# Lo que NO debes decir

**"Tiempo real"** así suelto. El stock es uno solo y siempre está correcto, pero la
pantalla de otra sucursal **no se refresca sola**: hay que recargar. Si ponen dos
pantallas lado a lado, te agarran.

La forma correcta:

> "El stock es uno solo: cuando la otra sucursal consulta, ve el número ya actualizado."

**Resultados de laboratorio, historia clínica o conexión con los analizadores.**
Eso es un LIS, es otro sistema. Respuesta honesta:

> "Un LIS es otro sistema, con sus propias reglas. Lo que sí hago es conectarme con el
> que ustedes tengan para que lo facturado cuadre con lo realizado."

**No abras Nómina ni Centros de costo.** Están vacíos y un módulo vacío mata la credibilidad.

---

# Si algo sale mal mientras grabas

| Pasa esto | Es porque |
|---|---|
| "Stock insuficiente" | Te quedaste sin lotes. Quedan 25. |
| "Serie no existe o ya fue vendida" | Usaste un lote del 001 al 005. Usa del 006 en adelante. |
| "Antes de facturar completa los datos de tu empresa" | Alguien vació la empresa. Avísame. |
| "Tu rol no tiene permiso para esta acción" | Entraste como contador o doctor. Sal y entra como admin. |
| La factura no sale AUTORIZADO | No hay certificado cargado. Es lo esperado hoy. |

---

# Orden resumido para grabar

```
PARTE 1 (admin)     Entrar → Empresa → Sucursales → Bodega → Usuarios
PARTE 2 (inventario) Productos → Compras → Lotes → KÁRDEX  ← el bloque clave
PARTE 3 (atender)    Paciente → Facturar → Imprimir → Anular
PARTE 4 (roles)      Entrar como doctor → Entrar como contadora → Auditoría
```

Unos 20 minutos grabando sin cortes. Practícalo una vez antes de grabar en serio:
la primera para ubicar cada pantalla, la segunda para que salga sin titubeos.

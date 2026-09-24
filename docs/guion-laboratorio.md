# Guion de demostración — Laboratorio clínico

**20 minutos.** Sistema listo en `http://108.174.152.179:8080/app/` con
**LABORATORIO CLINICO DEMO S.A.** cargado: 5 exámenes, 5 insumos, 2 clientes, 1 factura.

**La idea que tiene que quedar:** *usted vende exámenes, pero consume insumos.
El sistema separa las dos cosas y le dice cuánto le cuesta de verdad cada examen.*

---

## Antes de entrar (2 min, solo tú)

| Revisar | Cómo |
|---|---|
| El sistema abre | `http://108.174.152.179:8080/app/` |
| La empresa dice | LABORATORIO CLINICO DEMO S.A. |
| Hay 1 factura | Ventas → Facturas |

Pregunta al llegar, antes de abrir nada: **"¿cuántos exámenes distintos manejan?"**
Con ese número adaptas todo lo que digas después.

---

## 1 · Apertura · 1 min

> "Esto es facturación electrónica al SRI con control de inventario.
> Lo tengo cargado con datos de un laboratorio para que vea cómo le quedaría."

**No abras nada todavía.** Deja que vea la pantalla de módulos unos segundos.

> "Cada cuadro es un módulo. Y si prefiere no buscar, escribe."

Escribe **`fact`** en el buscador. Salen Facturas y Facturación Masiva.

> "Cuarenta y nueve pantallas, y llega a cualquiera escribiendo tres letras.
> Sus recepcionistas no van a andar buscando en menús."

---

## 2 · El catálogo · 4 min — *acá está la idea principal*

**Catálogo → Productos y servicios**

Muestra primero los exámenes:

```
EX-001  Biometría hemática completa      $8.00
EX-002  Química sanguínea (6 elementos) $18.00
EX-003  Perfil lipídico                 $15.00
EX-004  Examen general de orina          $6.00
EX-005  Prueba de embarazo en sangre    $10.00
```

Abre `EX-001` y señala dos campos:

> "Tipo: **servicio**. Y el IVA en **0%**.
>
> Los servicios de salud van con tarifa cero, como manda la ley. Si el sistema le
> pusiera 15% a una biometría, el SRI le rechaza la factura. Ya viene configurado."

Ahora baja a los insumos:

```
INS-010  Tubo vacutainer tapa lila       stock 500
INS-011  Jeringuilla 5 ml estéril        stock 300
INS-012  Guantes de nitrilo (caja x100)  stock  24
REA-020  Reactivo hemoglobina 500 ml     stock   6   ← por lote
REA-021  Tiras reactivas de orina x100   stock  10   ← por lote
```

> "Estos son **bienes**. Sí llevan stock, sí se agotan, y sí tienen un costo.
>
> Esa es la diferencia que casi ningún sistema le resuelve: usted **vende** exámenes
> pero **consume** tubos, jeringuillas y reactivos. Son dos mundos distintos y el
> sistema los maneja distinto."

---

## 3 · Los lotes · 3 min — *el que más les pega*

**Inventario → Garantías por serie** → producto `REA-020`

Salen los seis lotes: `LOTE-A2451` a `LOTE-A2456`.

> "Cada frasco de reactivo entra con su número de lote.
>
> Si mañana el proveedor le avisa que el lote A2453 salió defectuoso, usted busca
> ese lote y el sistema le dice en qué pacientes se usó. Sin revisar cuadernos."

**Si te preguntan por la fecha de caducidad:** hoy el sistema rastrea el lote, no la
fecha. Dilo así, sin adornos:

> "El lote sí, la caducidad todavía no. Se puede agregar. ¿Es algo que usted controla hoy?"

Si dice que sí, anótalo. Eso es una venta de mejora, no una excusa.

---

## 4 · Facturar · 4 min

**Ventas → Punto de Venta**

1. En "Buscar por ID" escribe `1803553062` → Enter. Carga PACIENTE DE PRUEBA.
2. En el cuadro de artículos escribe `EX-001` → Enter.
3. Escribe `EX-003` → Enter.
4. Forma de pago **Efectivo** → **Emitir Factura**.

Totales:

```
Subtotal 0%   $23.00
IVA            $0.00
TOTAL         $23.00
```

> "Fíjese: IVA cero. Así sale la factura de un laboratorio."

**Ventas → Facturas** → clic en el ojito → **Imprimir / PDF**.

> "Así la recibe el paciente. Y por correo se le va el PDF y el XML."

**Si preguntan por el SRI:** hoy está en ambiente de PRUEBAS y sin certificado cargado.

> "Cuando usted suba su archivo .p12, la factura sale firmada y autorizada por el SRI
> en segundos. El certificado lo compra usted en el Banco Central o Security Data,
> cuesta entre $25 y $50 y dura uno o dos años. No se lo vendo yo."

---

## 5 · Empresas con convenio · 2 min

> "¿Ustedes atienden empresas? ¿De esas que mandan a todo el personal a exámenes
> ocupacionales?"

Casi siempre dicen que sí. Entonces:

**Catálogo → Clientes** → muestra `INDUSTRIAS ANDINAS CIA LTDA` (RUC).

> "Ese cliente se factura con RUC y queda en cuentas por cobrar. Usted ve en cualquier
> momento cuánto le debe cada empresa y desde cuándo."

**Ventas → Cuentas por cobrar.**

Ese módulo vale oro en un laboratorio: la plata de los convenios se cobra tarde y
casi todos la controlan en un cuaderno.

---

## 6 · Anular · 2 min

**Ventas → Facturas** → clic en la fila → **Anular**

> "La factura no desaparece: queda marcada como anulada. El sistema crea un
> contra-asiento que invierte el original, y los dos quedan en el libro diario.
>
> Un sistema que borra facturas le deja un hueco en la numeración que después no
> puede explicar."

Abre **Contabilidad → Libro diario** y muéstrale los dos asientos, uno debajo del otro.

---

## 7 · Cierre · 2 min

> "Son tres planes, al año:
>
> **Básico $85** — inventario, punto de venta y reportes. Sin facturación al SRI.
> **Negocio $145** — suma facturación electrónica, conciliaciones y usuarios con permisos.
> **Completo $225** — suma contabilidad, nómina y varios locales.
>
> Para un laboratorio que factura al SRI y maneja reactivos por lote, el que le
> sirve es **Negocio, ciento cuarenta y cinco al año**."

Di **"al año"** despacio. Si entiende pago único, pierdes el cliente el año que viene.

Cierra con una pregunta concreta:

> "¿Cuántos exámenes tiene en el catálogo para dejárselo cargado?"

---

## Las preguntas que te van a hacer

| Pregunta | Respuesta |
|---|---|
| ¿Guarda los resultados de los exámenes? | **No.** Esto es facturación, inventario y contabilidad. Si ya tiene un sistema de resultados, conviven sin problema. |
| ¿Se conecta con mis analizadores? | No. Eso es un sistema de laboratorio (LIS), es otra cosa. |
| ¿Maneja historia clínica? | No, y a propósito: eso es información médica con reglas aparte. |
| ¿Lleva la caducidad de los reactivos? | El lote sí, la caducidad todavía no. Se puede agregar — ¿lo controla hoy? |
| ¿Cuántos usuarios? | Los que necesite, cada uno con su permiso. Desde el plan Negocio. |
| ¿Y si tengo dos sucursales? | Sí, en el plan Completo. Cada local factura con su propio establecimiento. |
| ¿Necesito instalar algo? | No. Se abre desde el navegador, desde cualquier computadora o el celular. |
| ¿Dónde quedan mis datos? | En su propio servidor. No los comparto con nadie. |

---

## Lo que NO debes hacer

- **No abras Nómina ni Centros de costo.** Un módulo vacío mata la credibilidad.
- **No prometas resultados de laboratorio ni historia clínica.** Son meses de trabajo
  con normativa de datos de salud. Perderías plata.
- **No hables de precio hasta el bloque 7.** Primero que vea el valor.
- **No digas "eso lo agrego rapidito".** Di "lo reviso y le confirmo mañana".

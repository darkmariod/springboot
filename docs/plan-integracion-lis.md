# Plan — Integración con el LIS del laboratorio

**Estado:** plan, no construido. Para ejecutar **después de cerrar la venta.**

---

## Qué es y qué no es

**NO** vamos a construir un LIS. Un LIS maneja órdenes, valida resultados, lleva control
de calidad, se conecta a los analizadores y guarda datos de salud regulados. Eso es un
producto entero y meses de trabajo.

**SÍ** vamos a conectar HasReset con el LIS que el laboratorio ya tenga, por el lado de
la facturación:

```
   LIS                          HasReset
   ───                          ────────
   "al paciente X se le    →    emite la factura electrónica
    hicieron los exámenes        descuenta los reactivos consumidos
    A, B y C"                    escribe el asiento contable
```

Si el laboratorio no tiene LIS, HasReset funciona igual. La integración es opcional.

**La frase para el cliente:**

> "Un LIS es otro sistema, con sus propias reglas. Lo que yo hago es conectarme con el que
> ustedes tengan para que lo facturado cuadre con lo realizado."

---

## Lo que YA existe y ahorra la mitad del trabajo

Al revisar el código encontré que la parte difícil ya está construida:

**1. Combos con componentes** — `product_components` relaciona un producto con las partes
que consume, cada una con su cantidad. Y `InvoiceEmitter` ya las descuenta al vender:

```php
if ($product->es_combo) {
    foreach ($product->components as $c) {
        // descuenta cada componente del inventario
    }
}
```

**Eso es exactamente la receta de un examen.** Marcar `EX-001 Biometría` como combo y
darle componentes:

```
EX-001  Biometría hemática completa   (servicio, combo)
   ├── 0.02 frascos de REA-001 Reactivo hemoglobina
   ├── 1    tubo vacutainer
   └── 1    jeringuilla
```

Al facturar la biometría, el sistema descuenta solo los insumos. Sin escribir código nuevo.

**2. Emisión completa** — `InvoiceEmitter::emit()` ya recibe cliente, ítems, forma de pago
y punto de emisión, y hace todo: XML, firma, envío al SRI, kárdex, series y asiento.

**3. Control de concurrencia** — el reintento por bloqueo ya está resuelto y probado con
5 sucursales simultáneas.

**Lo que falta es solo la puerta de entrada.**

---

## Las dos formas de conectarse

Se implementan las dos: el archivo primero porque desbloquea a cualquier laboratorio,
la API después para los que tengan un LIS moderno.

### Forma A — Importar un archivo (empezar por aquí)

El LIS exporta un CSV o Excel al final del día, o por cada orden. El laboratorio lo sube
por una pantalla, ve qué se va a facturar, y confirma.

```csv
orden,fecha,identificacion,paciente,codigo_examen,cantidad,precio
10432,2026-09-24,1803553062,MARIA GUAMAN,EX-001,1,8.00
10432,2026-09-24,1803553062,MARIA GUAMAN,EX-003,1,15.00
10433,2026-09-24,0601234567,JUAN PEREZ,EX-001,1,8.00
```

**Ventajas:** funciona con cualquier LIS, incluso uno viejo. No necesita que el LIS
sepa hablar con nosotros. El laboratorio revisa antes de facturar.

**Pantalla nueva:** Ventas → *Importar del LIS*. Sube el archivo, muestra una tabla con
lo que va a pasar (cuántas facturas, a qué pacientes, cuánto suma), y un botón de confirmar.

### Forma B — Endpoint para que el LIS empuje

Para LIS modernos que pueden llamar a una API cuando se valida una orden.

```
POST /api/lis/ordenes
Authorization: Bearer <token del laboratorio>

{
  "orden_externa": "10432",
  "fecha": "2026-09-24",
  "establecimiento": "003",
  "paciente": {
    "identificacion": "1803553062",
    "tipo_identificacion": "05",
    "nombre": "MARIA FERNANDA GUAMAN LEMA"
  },
  "examenes": [
    { "codigo": "EX-001", "cantidad": 1 },
    { "codigo": "EX-003", "cantidad": 1 }
  ],
  "forma_pago": "efectivo"
}
```

Respuesta:

```json
{
  "factura": "003-001-000000042",
  "clave_acceso": "2409...",
  "estado_sri": "AUTORIZADO",
  "total": 23.00
}
```

**Regla que no se puede saltar:** `orden_externa` es único por empresa. Si el LIS manda
la misma orden dos veces —y lo va a hacer, por un reintento o un error de red— se devuelve
la factura que ya existe, **no se emite otra**. Una factura duplicada ante el SRI es un
problema serio.

---

## Lo que hay que construir

| # | Trabajo | Notas |
|---|---|---|
| 1 | Tabla `lis_ordenes` | `orden_externa`, `company_id`, `invoice_id`, `estado`, payload original. Índice único en (company_id, orden_externa). |
| 2 | Mapeo de códigos | El LIS llama al examen `BH` y HasReset `EX-001`. Pantalla simple de equivalencias: código del LIS → código del producto. |
| 3 | `LisOrderImporter` | El servicio: valida, resuelve o crea el paciente, mapea códigos, llama a `InvoiceEmitter::emit()`, guarda el enlace. |
| 4 | Endpoint + token | `POST /api/lis/ordenes` con token por empresa. Rechaza duplicados devolviendo la factura existente. |
| 5 | Pantalla de importación | Subir archivo → vista previa → confirmar. Con el detalle de errores por fila. |
| 6 | Recetas de examen | Marcar los exámenes como combo y cargarles sus insumos. **No requiere código**, es configuración. |
| 7 | Bitácora | Cada orden recibida queda registrada, se haya facturado o no, con el motivo del fallo. |

**Lo que NO entra:** resultados, historia clínica, órdenes hacia el analizador,
control de calidad. Eso es del LIS.

---

## Cómo se prueba

1. **Orden simple** — un paciente, dos exámenes → una factura, totales correctos.
2. **Orden repetida** — la misma `orden_externa` dos veces → **una sola factura**.
3. **Paciente nuevo** — no existe en HasReset → se crea y se factura.
4. **Cédula inválida** — la orden se rechaza con un mensaje claro, no se factura.
5. **Código sin mapear** — se rechaza esa fila, se dice cuál, las demás siguen.
6. **Examen con receta** — al facturar, el kárdex descuenta los insumos del combo.
7. **Carga real** — 50 órdenes seguidas desde varias sucursales, `inventario:auditar`
   y `contable:chequeo` en verde al final.
8. **Sin stock** — si falta un reactivo, decidir con el cliente: ¿se factura igual y
   queda el stock en rojo, o se frena? **Esta pregunta hay que hacérsela antes de codificar.**

---

## Esfuerzo y precio

| Parte | Días |
|---|---|
| Tabla, mapeo de códigos y servicio importador | 2 |
| Endpoint con token y control de duplicados | 1 |
| Pantalla de importación con vista previa | 2 |
| Pruebas y ajuste con el LIS real del cliente | 2 a 4 |
| **Total** | **7 a 9 días** |

Los últimos días son los impredecibles: dependen de qué exporta el LIS de ellos y de
cuánto tarden en darte un archivo de ejemplo.

**Cómo cobrarlo:** es un módulo aparte, no entra en el plan anual. Precio cerrado, 50%
por adelantado, y **no arranca hasta tener un archivo de ejemplo real del LIS de ellos**.

---

## Antes de prometer nada, pregunta esto

1. **¿Qué LIS usan?** Marca y versión.
2. **¿Puede exportar un archivo?** Pide uno de ejemplo, con datos reales de un día.
3. **¿Puede llamar a una dirección web cuando valida una orden?** Si sí, va la Forma B.
4. **¿Quién revisa antes de facturar?** ¿Se factura automático o alguien aprueba?
5. **¿Qué pasa si no hay reactivo?** ¿Se frena la factura o se factura igual?

**Sin respuesta a la 2, no hay presupuesto.** Cotizar una integración sin ver qué exporta
el otro sistema es la forma más rápida de perder plata en este negocio.

---

## Riesgos

**El LIS no exporta nada útil.** Pasa con sistemas viejos o cerrados. Si el proveedor del
LIS no colabora, la integración no se puede hacer y hay que decirlo antes de cobrar.

**Órdenes duplicadas.** Cubierto por el índice único, pero es lo primero que hay que probar.

**Datos de salud.** Nosotros recibimos identificación del paciente y códigos de examen.
**No recibimos resultados ni diagnósticos** — y conviene dejarlo por escrito en el contrato.
Menos datos sensibles, menos responsabilidad.

**Códigos que cambian.** Si el laboratorio agrega un examen en el LIS y no lo mapea, esa
orden se rechaza. Por eso la bitácora del punto 7: para que se vea qué quedó sin facturar.

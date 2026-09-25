# Las 47 pantallas — qué hago en cada una

Sacado del código, no de memoria. Para que no revises una por una.

---

## La regla que explica todo

**No todas las pantallas tienen "crear, editar y borrar", y eso es correcto.**
Hay cuatro clases, y cada una se usa distinto:

| Clase | Cuántas | Qué se hace | Por qué |
|---|---|---|---|
| **Datos maestros** | 13 | Crear, editar y borrar | Son cosas tuyas: clientes, productos, bodegas |
| **Documentos** | 18 | Crear y **anular** | Una factura no se edita ni se borra: se reversa |
| **Procesos** | 9 | Crear un movimiento | Un ajuste hecho no se "edita": se hace otro |
| **Reportes** | 7 | Solo consultar | Muestran el resultado, no lo originan |

**Tu frase cuando te pregunten por qué no se puede editar algo:**

> "Los datos suyos —clientes, productos, bodegas— se editan y se borran cuando quiera.
> Los documentos contables no: se anulan, y queda el rastro. Un sistema que le deja
> borrar una factura le deja un hueco que después no puede explicar."

---

# 1 · DATOS MAESTROS — crear, editar y borrar

Aquí sí hay CRUD completo. `F2` crea, `Ctrl+S` guarda, botón Eliminar borra.

| Pantalla | Dónde | Qué registras |
|---|---|---|
| **Clientes** | Catálogo | Pacientes y empresas. Tipo ID, identificación, razón social, dirección, correo |
| **Productos y servicios** | Catálogo | Exámenes (servicio) y reactivos o repuestos (bien). Código, nombre, tipo, IVA, precio, si lleva lote |
| **Plan de cuentas** | Catálogo | Las cuentas contables. Ya viene cargado; rara vez se toca |
| **Proveedores** | Compras | A quién le compras. Mismos campos que un cliente |
| **Bodegas** | Inventario | Dónde guardas. Código, nombre, cuál es la de por defecto |
| **Garantías por serie** | Inventario | Los lotes o números de serie. Alta masiva, uno por línea |
| **Bancos** | Caja y Bancos | Tus cuentas bancarias |
| **Empleados** | Nómina | El personal |
| **Puntos de emisión** | EDocuments | Las cajas que emiten. Establecimiento y punto |
| **Sucursales** | Administración | Cada local como establecimiento del SRI |
| **Usuarios y roles** | Administración | Quién entra y qué ve |
| **Cotizaciones** | Ventas | Proformas. Se editan mientras no se facturen |
| **Empresas** | Administración | Tus datos y el certificado. Se edita, **no se borra** |

> **Empresas no tiene botón de borrar, a propósito.** Borrar la empresa sería borrar
> toda la contabilidad.

---

# 2 · DOCUMENTOS — se crean y se anulan, nunca se editan

Aquí `F2` crea. **No busques el botón de editar: no existe y no debe existir.**

### Ventas

| Pantalla | Qué es | Cómo se deshace |
|---|---|---|
| **Punto de Venta** | Donde facturas día a día | — |
| **Facturas** | El listado de lo emitido | Botón **Anular** (o Nota de Crédito si ya la autorizó el SRI) |
| **Notas de crédito** | Devoluciones y correcciones | Se anula |
| **Anticipos** | Pagos por adelantado | Se anula |
| **Cuentas por cobrar** | Quién te debe. Aquí registras los cobros | El cobro se reversa |
| **Retenciones recibidas** | Las que te hacen tus clientes | Se anula |
| **Nota de Débito** | Cargos adicionales | Se anula |
| **Guía de Remisión** | Traslado de mercadería | Se anula |
| **Facturación Masiva** | Muchas facturas de una vez | Cada una se anula aparte |

### Compras

| Pantalla | Qué es |
|---|---|
| **Compras** | El listado de lo que compraste |
| **Registro de Compras** | Donde cargas la factura del proveedor. **Esto sube el stock** |
| **Importar del SRI (lote)** | Subes el `.txt` del portal del SRI y trae los comprobantes |
| **Liq. de Compra** | Cuando le compras a quien no emite factura |
| **Cuentas por pagar** | A quién le debes. Aquí registras los pagos |

### Caja, bancos y contabilidad

| Pantalla | Qué es |
|---|---|
| **Caja** | Apertura, cierre y movimientos de caja |
| **Conciliación bancaria** | Cuadrar el estado de cuenta con el sistema |
| **Conciliación Tarjetas** | Cuadrar las liquidaciones de las tarjetas |
| **Libro diario** | Los asientos. Se escriben solos; aquí puedes crear uno manual |
| **Estados financieros** | Balance y resultados. Se generan |
| **Rol de pagos** | La nómina del mes. Se genera |

### EDocuments

| Pantalla | Qué es |
|---|---|
| **Configuración de firma** | Donde subes el `.p12` y su clave. También el correo de envío |
| **Documentos SRI** | El estado de cada comprobante: generado, firmado, enviado, autorizado |

---

# 3 · PROCESOS — cada uso deja un movimiento

No se editan: si te equivocaste, haces otro movimiento que lo corrige. Así queda el rastro.

| Pantalla | Para qué sirve |
|---|---|
| **Ajuste Inventario** | El conteo físico no cuadra, o se dañó mercadería. **Corrige el stock** |
| **Transferencia** | Mover stock de una bodega a otra |
| **Conversión Artículos** | Convertir un artículo en otro (una caja en unidades sueltas) |
| **Fraccionamiento** | Partir una presentación grande en pequeñas |
| **Reservas Stock** | Apartar mercadería para un cliente sin facturarla todavía |

---

# 4 · REPORTES — solo se consultan

No tienen botón de crear porque **no originan nada**: muestran lo que ya pasó.

| Pantalla | Qué muestra |
|---|---|
| **Resumen del negocio** | Ventas del mes, cobrado, por cobrar, pendientes |
| **Inventario y kardex** | Existencias al costo promedio. Clic en un producto abre su kárdex |
| **Reportes de inventario** | Existencias, movimientos, valorización |
| **Libro mayor** | Los asientos agrupados por cuenta |
| **Impuestos** | Formulario 103, ATS |
| **Auditoría** | Quién hizo qué y cuándo |
| **Reportes** | Reportes generales |

> **Inventario y kardex es el que más confunde.** No tiene botón de nuevo porque el stock
> sale de los movimientos. Ahora la pantalla trae tres accesos arriba:
> *Crear un artículo · Registrar una compra · Ajustar existencias.*

---

# El flujo, de principio a fin

Si te pierdes, este es el orden real de uso:

```
CONFIGURAR (una vez)
   Empresas → Sucursales → Bodegas → Puntos de emisión → Usuarios
   EDocuments → Configuración de firma (el .p12)

CARGAR (una vez, y luego cuando haya novedades)
   Catálogo → Clientes
   Catálogo → Productos y servicios
   Compras → Proveedores

QUE ENTRE MERCADERÍA
   Compras → Registro de Compras          ← sube el stock
   Inventario → Garantías por serie        ← los lotes de lo que entró

VENDER
   Ventas → Punto de Venta                 ← baja el stock
   Ventas → Facturas                       ← ver, imprimir, anular

REVISAR
   Inventario → Inventario y kardex
   Contabilidad → Libro diario y mayor
   Administración → Auditoría
```

---

# Dónde hago cada cosa

| Quiero… | Voy a |
|---|---|
| Registrar un paciente o cliente | Catálogo → Clientes |
| Crear un examen o un repuesto | Catálogo → Productos y servicios |
| Que **suba** el stock | Compras → Registro de Compras |
| Que **baje** el stock | Ventas → Punto de Venta |
| **Corregir** el stock | Inventario → Ajuste Inventario |
| Mover stock entre bodegas | Inventario → Transferencia |
| Registrar lotes o series | Inventario → Garantías por serie |
| Ver cuánto me queda y cuánto vale | Inventario → Inventario y kardex |
| Deshacer una factura | Ventas → Facturas → Anular |
| Cobrar una factura | Ventas → Cuentas por cobrar |
| Pagar a un proveedor | Compras → Cuentas por pagar |
| Ver quién hizo qué | Administración → Auditoría |
| Cambiar quién ve qué | Administración → Usuarios y roles |

---

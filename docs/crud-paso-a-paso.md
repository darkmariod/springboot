# CRUD paso a paso — para la demo del lunes

**Sistema:** `http://108.174.152.179:8080/app/`
**Empresa:** LABORATORIO CLINICO SAN RAFAEL (cámbiala arriba si quieres el taller)

---

## Primero: por qué el inventario no tiene botón de "nuevo"

Esto hay que entenderlo antes de la demo, porque te lo van a preguntar.

**Inventario y kardex es un reporte, no un formulario.** Muestra el resultado, no el origen.
Por eso no tiene botón de crear: el stock **no se escribe a mano**, se mueve.

Si alguien pudiera teclear "tengo 50", el kárdex quedaría mintiendo. Ese número tiene que
venir de una compra, un ajuste o una venta — algo que deje rastro.

Por eso la pantalla ahora trae tres accesos arriba:

```
El stock de esta pantalla sale de los movimientos. Para cambiarlo:
   + Crear un artículo    🛍 Registrar una compra    ⚙ Ajustar existencias
```

**La frase para el cliente:**

> "Esta pantalla no se edita, se consulta. El stock sale de los movimientos: una compra
> lo sube, una venta lo baja, un ajuste lo corrige. Si yo pudiera escribir el número
> a mano, el kárdex no serviría para nada."

**Dónde se hace cada cosa:**

| Quiero… | Voy a |
|---|---|
| Crear, editar o borrar un artículo | **Catálogo → Productos y servicios** |
| Que suba el stock | **Compras → Registro de Compras** |
| Corregir el stock (conteo físico, daño) | **Inventario → Ajuste Inventario** |
| Mover stock entre bodegas | **Inventario → Transferencia** |
| Que baje el stock | Una venta en **Punto de Venta** |
| Dar de alta lotes o series | **Inventario → Garantías por serie** |

---

# 1 · CRUD de clientes

**Catálogo → Clientes** (o `F4`, escribe `clien`, Enter)

### Crear

1. **Busca primero.** Escribe la cédula en el filtro de arriba. Si ya existe, no lo dupliques.
2. Botón `+` o tecla **`F2`**.
3. Pestaña **General**:

| Campo | Ejemplo | Nota |
|---|---|---|
| Tipo ID | `Cédula` | Elige esto primero: define cuántos dígitos acepta |
| Identificación | `1712345678` | Debe ser una cédula válida |
| Razón social | `JUAN PEREZ MORA` | Se autocompleta si el SRI responde |
| Dirección | `Riobamba` | |
| Tipo contacto | `Cliente` | Puede ser cliente y proveedor a la vez |

4. Pestaña **Contacto**: teléfono y correo.
5. **`Ctrl + S`** o botón Guardar. Sale **"Contacto guardado."**

> "Si la cédula está mal formada, no me deja guardar. Mejor que reclame aquí y no el SRI."

### Editar
Clic en el cliente del listado → botón **Editar** → cambia → **Guardar**.

### Eliminar
Clic en el cliente → botón **Eliminar** → confirmar.

> Un cliente que ya tiene facturas **no se puede borrar**, y está bien: borrarlo dejaría
> facturas sin dueño.

---

# 2 · CRUD de exámenes y artículos

**Catálogo → Productos y servicios** (`F4`, `produ`, Enter)

### Crear un examen (servicio)

`F2` → pestaña **General**:

| Campo | Valor |
|---|---|
| Código | `EX-002` |
| Nombre | `Química sanguínea (6 elementos)` |
| Tipo | **Servicio (sin stock)** |
| Aplica Serie/Lote | `Ninguno` |
| Porcentaje IVA | **0%** |
| Precio venta | `18.00` |

**`Ctrl + S`** → **"Artículo guardado."**

> "Tipo servicio y IVA cero. Los servicios de salud van con tarifa cero, como manda la ley."

### Crear un reactivo (bien con lote)

| Campo | Valor |
|---|---|
| Código | `REA-002` |
| Nombre | `Tiras reactivas de orina x100` |
| Tipo | **Bien (lleva stock)** |
| Aplica Serie/Lote | **Serie** |
| Porcentaje IVA | `15%` |
| Precio venta | `42.00` |
| Stock mínimo | `5` |

Abajo, el recuadro **Estado** dice `Stock: 0`. Es correcto: nada entra por esta pantalla.

### Editar
Clic en el artículo → **Editar** → cambia el nombre o el precio → **Guardar**.

> **El stock y el costo promedio no se pueden cambiar aquí.** Si lo intentas, el sistema
> avisa: *"se mueven con una compra, un ajuste o una transferencia"*. Esa negativa es una
> función, no una falla — muéstrala a propósito.

### Eliminar
Clic → **Eliminar** → confirmar. Un artículo con movimientos en el kárdex no se borra.

---

# 3 · Que entre el stock

**Compras → Registro de Compras** (`F4`, `regis`, Enter)

`F2` para un registro nuevo:

| Campo | Ejemplo |
|---|---|
| Proveedor | búscalo y elígelo (si no hay, créalo en Compras → Proveedores) |
| Fecha emisión | hoy |
| Establecimiento / Punto | `001` / `001` |
| No. Comprobante | `000001234` |
| Bodega | `Bodega Central (compartida)` |

En la grilla de ítems, escribe el código y se completa solo:

| Código | Cant. | Costo s/IVA |
|---|---|---|
| `REA-002` | `10` | `27.50` |

**Guardar.** Vuelve a **Productos** y mira el Estado de `REA-002`: ya dice `Stock: 10`.

> "La compra subió el stock sola. Nadie tecleó el número."

---

# 4 · CRUD de lotes

**Inventario → Garantías por serie** (`F4`, `garan`, Enter)

### Crear
1. Arriba, **Producto**: elige `REA-002`.
2. En el cuadro de la derecha pega los lotes, **uno por línea**:

```
LOTE-B1140
LOTE-B1141
LOTE-B1142
```

3. **Registrar series.** Sale *"3 serie(s) registrada(s)."*

> "Se pueden escanear seguidos o pegar desde un Excel."

**Si registras menos lotes que stock**, sale un aviso amarillo. Muéstralo:
*"el sistema no me deja tener unidades sin identificar."*

### Editar
El triángulo naranja marca un lote como **dañado**. La flecha lo devuelve a **disponible**.

### Eliminar
El basurero. **Está bloqueado en los lotes vendidos** — ese lote está dentro de una factura.

---

# 5 · Ajustar existencias

**Inventario → Ajuste Inventario**

Se usa cuando el conteo físico no coincide, o cuando se daña mercadería.

> "Si contaron la bodega y hay 23 en vez de 25, aquí se corrige. Y queda el rastro de
> quién lo ajustó y por qué. No es que el número cambie solo."

---

# 6 · Facturar (el stock baja)

**Ventas → Punto de Venta** (`F4`, `punto`, Enter)

1. **Buscar por ID**: escribe `1803553062` → Enter.
2. Escribe `EX-001` → Enter.
3. Escribe `REA-002` → Enter → se abre el selector de lotes → marca uno → **Aplicar**.
4. Forma de pago **Efectivo** → **Emitir Factura**.

Vuelve al kárdex: hay una línea de egreso nueva y el stock bajó.

---

# 7 · Anular (el stock vuelve)

**Ventas → Facturas** → clic en la fila → **Anular**.

Después muestra las tres cosas:

1. **Kárdex** → línea nueva de *ingreso* "Anulación". El stock volvió.
2. **Garantías por serie** → el lote volvió a *disponible*.
3. **Libro diario** → asiento de reversión. **El original sigue ahí.**

---

## Atajos que conviene mostrar

| Tecla | Hace |
|---|---|
| `F2` | Nuevo registro |
| `Ctrl + S` | Guardar |
| `F3` | Buscar dentro de la pantalla |
| `Esc` | Cancelar |
| `F4` | Volver a Módulos |
| `F8` | Cerrar pestaña |

---

## Orden para la demo del lunes

```
1. Cliente nuevo                Catálogo → Clientes
2. Examen nuevo (servicio)      Catálogo → Productos
3. Reactivo nuevo (bien+lote)   Catálogo → Productos
4. Compra                       Compras → Registro de Compras   ← sube el stock
5. Lotes                        Inventario → Garantías por serie
6. Kárdex                       Inventario → Inventario y kardex ← la pantalla clave
7. Facturar                     Ventas → Punto de Venta          ← baja el stock
8. Anular                       Ventas → Facturas                ← el stock vuelve
9. Roles                        entra con doctor@demo.com
```

Unos 20 minutos. Practícalo dos veces antes del lunes.

**Usuarios:** `contador@demo.com` y `doctor@demo.com`, clave `demo1234`.
El de administrador es el tuyo.

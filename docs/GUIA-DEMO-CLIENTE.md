# Guía de demostración — Presentar HasReset al cliente

Recorrido paso a paso para mostrar el sistema en vivo. Cada paso trae **qué hacer** y **qué decir**.
Duración estimada: 15–20 min. Sistema en `http://108.174.152.179:8080`.

> Antes de empezar: recargá con `Cmd/Ctrl + Shift + R` para ver la última versión.

---

## 0. Preparación (antes de la reunión)
- Verificá que puedas entrar con el usuario administrador.
- Tené a mano un RUC real para la demo del autocompletar (ej. `1790016919001`).
- Confirmá que la empresa tenga el certificado `.p12` cargado (Administración → Empresas).

---

## 1. Entrar al sistema
**Hacer:** ir a la URL, iniciar sesión.
**Decir:** *"Este es HasReset, tu sistema contable y de facturación electrónica. Todo corre en la
nube, entrás desde cualquier navegador, sin instalar nada."*

---

## 2. Pantalla de Inicio (resumen del negocio)
**Hacer:** mostrar el dashboard: ventas del mes, cobrado, por cobrar, documentos recientes,
acciones pendientes y actividad.
**Decir:** *"Apenas entrás ves el estado del negocio: cuánto vendiste, cuánto cobraste, qué te
deben, y las últimas facturas con su estado del SRI. Todo en tiempo real."*

---

## 3. El menú lateral (todos los módulos)
**Hacer:** recorrer el sidebar: Catálogo, Ventas, Compras, Inventario, Contabilidad, Auditoría.
**Decir:** *"Cada módulo se abre en su propia pestaña, así podés trabajar en varios a la vez —
facturar, revisar inventario y ver un reporte sin perder lo que estabas haciendo."*

---

## 4. ⭐ El feature clave: traer datos del SRI
**Hacer:** ir a **Ventas → Punto de Venta**. En "Buscar por ID" escribir un RUC completo
(`1790016919001`) y buscar.
**Decir:** *"Mirá esto: escribo solo el RUC del cliente… y el sistema **trae la razón social del SRI
automáticamente**. La cajera solo confirma. Funciona con cédula (10 dígitos) o RUC (13). Si el
cliente es nuevo, se crea acá mismo sin salir de la venta."*

---

## 5. Emitir una factura en vivo
**Hacer:** agregar un producto, elegir forma de pago, **Emitir Factura**.
**Decir:** *"Agrego el producto, guardo… y el sistema hace todo solo: arma el XML, lo **firma con tu
certificado**, lo **envía al SRI** y trae la **autorización**. En segundos."*

---

## 6. La prueba: factura AUTORIZADA
**Hacer:** ir a **Ventas → Facturas**, abrir la factura recién emitida.
**Decir:** *"Acá está el estado: **AUTORIZADO**, con el número de autorización real del SRI de 49
dígitos. Esto no es una simulación: el SRI la aceptó. La factura le llega al correo del cliente con
su XML y su PDF."*

---

## 7. Lo que pasó por detrás (sin cargar nada a mano)
**Hacer:** mostrar **Inventario → Inventario y kardex** (bajó el stock) y **Contabilidad → Diario**
(se generó el asiento).
**Decir:** *"Con esa sola venta: **bajó el inventario** (y quedó registrada la serie que entregaste)
y se **generó el asiento contable**. Los balances quedan cuadrados sin que toques nada."*

---

## 8. Control de inventario con series (garantías)
**Hacer:** mostrar un producto con series y su historial (a quién se compró / vendió).
**Decir:** *"Cada equipo con número de serie queda trazado: a qué proveedor lo compraste y a qué
cliente se lo vendiste. Ideal para garantías y para no perder stock."*

---

## 9. Compras e importación del SRI
**Hacer:** mostrar **Compras → Importar del SRI (lote)**.
**Decir:** *"Tus compras las traés directo del portal del SRI: subís el archivo y se cargan solas.
Menos digitación, menos errores."*

---

## 10. Reportes y contabilidad
**Hacer:** mostrar **Contabilidad → Balances** y **Reportes**.
**Decir:** *"La contadora tiene libro diario, mayor y balances listos. Todo sale de las operaciones
del día a día, así que siempre está al día."*

---

## Cierre
**Decir:** *"Hoy esto está en el ambiente de **PRUEBAS** del SRI, que es el paso obligatorio antes de
producción. Cambiando un parámetro, las facturas tienen **validez tributaria real**. El sistema está
listo — definamos cuándo pasamos a producción y coordinamos con la contadora."*

---

## Respuestas rápidas a dudas típicas

| Pregunta del cliente | Respuesta |
|----------------------|-----------|
| ¿Necesito instalar algo? | No. Entrás desde el navegador, corre en la nube. |
| ¿Y si el SRI está caído? | El sistema reintenta; la factura queda registrada y se autoriza cuando el SRI responde. |
| ¿Sirve para varias empresas? | Sí, es multiempresa; cambiás de empresa desde arriba. |
| ¿Los datos son míos? | Sí. Certificado y datos son tuyos; nunca se comparten. |
| ¿Estas facturas de la demo valen? | No, son de PRUEBAS. Las reales salen al pasar a producción. |

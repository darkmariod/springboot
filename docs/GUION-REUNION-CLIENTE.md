# Guion de reunión — Qué decirle al cliente

Guion corto para seguir mientras le mostrás la pantalla. Están las **palabras exactas** que podés
decir en cada momento. Sistema en `http://108.174.152.179:8080`.

> Antes de arrancar: recargá con `Cmd/Ctrl + Shift + R` para ver la última versión.

---

## 1. Al abrir el sistema
> *"Javier, este es tu sistema. Entrás desde cualquier navegador, no instalás nada. Apenas entrás
> ves el resumen de tu negocio: cuánto vendiste, cuánto cobraste y qué te deben."*

## 2. El menú de la izquierda
> *"Acá tenés todo: ventas, compras, inventario y contabilidad. Cada cosa se abre en su pestaña,
> así trabajás en varias a la vez sin perder lo que estabas haciendo."*

## 3. ⭐ El momento fuerte — traer datos del SRI (Punto de Venta)
> *"Mirá esto: escribo solo el RUC o la cédula del cliente… y el sistema **trae el nombre solito
> del SRI**. No tipeás nada. Si el cliente es nuevo, se crea acá mismo."*

**Tip:** escribí el número completo (cédula = 10 dígitos, RUC = 13). Con menos, el SRI no consulta.

## 4. Emitir la factura
> *"Cargo el producto, elijo la forma de pago, y le doy a Emitir. El sistema arma la factura, la
> **firma con tu certificado** y la **manda al SRI** — todo solo, en segundos."*

## 5. Mostrar el resultado
> *"Y acá está: **AUTORIZADA por el SRI**, con su número real. Al cliente le llega la factura al
> correo con su PDF y su XML."*

## 6. Lo que pasó por detrás
> *"Con esa sola venta, **bajó tu inventario** y se **armó el asiento contable**. Tu contadora
> tiene todo cuadrado sin cargar nada a mano."*

## 7. Cierre (importante, para no prometer de más)
> *"Ahora estamos en modo **PRUEBAS**, que es el paso obligatorio antes de arrancar. Cuando digas,
> cambiamos a **producción** y ahí las facturas ya valen ante el SRI. El sistema está listo — vos
> decidís cuándo empezamos."*

---

## La idea de fondo que le tenés que dejar clara
> **"Vos solo vendés; el sistema factura, firma, manda al SRI, baja el stock y arma la contabilidad
> solo."**

Eso es lo que lo diferencia de facturar a mano o en Excel.

---

## Respuestas rápidas si el cliente pregunta

| Pregunta | Qué responder |
|----------|---------------|
| ¿Necesito instalar algo? | *"No, entrás desde el navegador. Corre en la nube."* |
| ¿Y si el SRI está caído? | *"El sistema reintenta; la factura queda guardada y se autoriza cuando el SRI responde."* |
| ¿Sirve para varias empresas? | *"Sí, cambiás de empresa desde arriba."* |
| ¿Los datos son míos? | *"Totalmente. Tu certificado y tus datos son tuyos, no se comparten."* |
| ¿Estas facturas de hoy valen? | *"No, son de prueba. Las reales salen cuando pasamos a producción."* |

---

## Nota técnica para vos (no para el cliente)
Si al emitir en PRUEBAS el SRI dice **"ERROR SECUENCIAL REGISTRADO"**, no es una falla: ese número
de factura ya se usó antes. Andá a **Administración → Empresas → Editar**, subí el **secuencial** a
un número nuevo y reintentá. Autoriza al toque.

# Lanzamiento y cobro de HasReset

**Respuesta corta:** el producto está listo para mostrarse y venderse, pero hay seis cosas que cerrar antes de recibir el primer pago. Las tres primeras bloquean; el resto protege el negocio.

Actualizado: 2026-10-20. El estado "Probado" significa que lo verificó una prueba automática o el navegador contra una copia local; no significa que se haya probado en producción.

## Camino mínimo para cobrar

| # | Paso | Estado hoy | Quién | Cómo |
|---|------|-----------|-------|------|
| 1 | Número de WhatsApp real en la landing | **Bloquea.** Hoy es un número de relleno (`593000000000`): los botones "Solicitar demo" y "Escribir por WhatsApp" no llegan a nadie | Mario da el número | Se cambia en una sola línea al inicio de `resources/views/landing.blade.php` y se sube con `bash deploy-landing.sh` |
| 2 | Dominio propio y HTTPS | **Bloquea.** El sistema vive en `http://IP:8080`: el navegador dice "No seguro" y las contraseñas viajan sin cifrar | Mario compra o elige el dominio; se configura en el VPS | Apuntar el dominio al VPS y publicar con Traefik (ya está en el VPS) o con un túnel de Cloudflare; certificado gratuito |
| 3 | Prueba real con el SRI (ambiente de pruebas) | **Bloquea.** Es lo único del producto que nunca se probó de punta a punta: firma, envío y autorización | Mario, con su propio RUC y certificado `.p12` | Cargar el certificado en e-documents, emitir una factura, una nota de crédito y una retención en pruebas, y confirmar que quedan AUTORIZADO. Existe el comando `php artisan sri:test-emision --company=ID` |
| 4 | Dar de alta a cada cliente nuevo | **Falta.** No hay pantalla ni comando para crear una empresa nueva; solo existe el de la demo | Desarrollo | Crear una pantalla "Nueva empresa" para el administrador (empresa, plan, usuario administrador) o, como mínimo, un comando `empresa:crear` |
| 5 | Forma de cobro y activación | Decisión pendiente | Mario | El plan y su vencimiento ya se controlan: el administrador cambia el plan y la fecha (`plan_vence`) de cada empresa, y un plan vencido bloquea las funciones. Falta definir cómo se cobra (transferencia, enlace de pago) y quién activa |
| 6 | Respaldo automático fuera del servidor | **Falta.** Los respaldos de hoy son copias manuales dentro del mismo servidor | Desarrollo | Tarea diaria que copie la base a otro lugar y guarde 14 días; probar una restauración |

## Antes de tener muchos clientes

- **Base de datos:** hoy es SQLite (un solo escritor a la vez). Con varias empresas facturando a la vez conviene pasar a MySQL o PostgreSQL.
- **Contraseñas de demostración:** cambiar las de todos los usuarios demo (están en documentos públicos del repositorio).
- **Correo:** cada empresa configura su servidor de correo en e-documents. Con Gmail: clave de aplicación, puerto 587 con SSL o puerto 465. Probar el envío real con tu cuenta.
- **Legal:** términos del servicio y política de privacidad (hay datos personales y, si vendes a salud, datos sensibles). Que los revise un abogado.
- **Facturarte a ti mismo:** tu cobro también es una venta; emite tu factura con tu RUC.
- **Soporte:** horario y canal (WhatsApp) escritos en la landing.

## Qué se debe validar con la contadora

Fórmula del Formulario 104, vacaciones (15 días por año), permiso sin sueldo (sueldo / 30 por día), vidas útiles sugeridas de los activos fijos y que el cierre del ejercicio no se puede deshacer desde el sistema.

## Lo que ya está listo para vender

Facturación electrónica con los seis comprobantes (XML probado sin firmar), RIDE en PDF, envío por correo al cliente, inventario con series, toma física y kárdex con origen, caja y bancos con asientos, conciliación con archivo del banco, estado de cuenta, retenciones, contabilidad con asientos manuales, períodos y estados financieros, nómina y activos fijos, roles y permisos, auditoría, y tres planes (Básico $85, Negocio $145, Completo $225 al año + IVA).

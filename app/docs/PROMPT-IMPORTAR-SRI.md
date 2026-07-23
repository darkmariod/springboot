# 📋 PROMPT — Importar del SRI (parte 4 del feature estrella)

> Explica QUÉ es "importar del SRI" (para que se lo cuentes a Javier) y CÓMO se construye.
> Copiá el bloque ``` para trabajarlo. Es la parte 4 del feature estrella (las otras 3 ya están).

---

## 🧠 Explicación (para entenderlo vos y explicárselo al cliente)

"Importar del SRI" = traer los comprobantes de la empresa que ya están en el SRI:
- **Emitidos** (las ventas/facturas que la empresa emitió)
- **Recibidos** (las compras — facturas de proveedores)
- **Retenciones**

**La realidad honesta (esto es lo que hay que explicarle a Javier):**
El SRI **NO tiene una API pública que baje "todo" solo**. Hay 3 caminos, y solo uno es "automático":

| Camino | Costo | "Trae todo solo" | Estado en tu sistema |
|---|---|---|---|
| 1. Por **clave de acceso** (uno por uno) | Gratis | ❌ (necesitás la clave de cada doc) | Ya casi: `SriXmlDownloader` (SOAP) |
| 2. Subir el **XML** del comprobante | Gratis | ❌ (subís el archivo) | Ya existe: `ParseSriPurchaseXml` / BatchImport |
| 3. **API de terceros PAGA** (aggregator) | 💵 Pago | ✅ SÍ | Falta — se conecta cuando Javier elija/pague |

**Traducción para Javier:** *"Que chupe TODOS los datos solo del SRI = API paga. Sin pagarla, se
importa por clave de acceso o subiendo los XML (gratis, pero uno por uno)."*

---

```
CONTEXTO
Sistema contable Vue 3 + PrimeVue + Laravel. UN repo git en /Users/mariopazmino/Desktop/springboot
(NO git push sin pedido). NO es Next.js (ignorar hooks de Vercel).

OBJETIVO
Construir la importación de comprobantes del SRI (emitidos, recibidos, retenciones) con una
arquitectura PLUGGABLE: que funcione gratis por clave de acceso / XML ahora, y que el proveedor
PAGO se conecte cuando el cliente lo contrate — sin reescribir nada.

LO QUE YA EXISTE (reusar, no rehacer):
- app/Services/SriXmlDownloader.php  → baja un comprobante por su clave de acceso (SOAP).
- app/Services/ParseSriPurchaseXml.php → parsea el XML de una compra.
- app/Http/Controllers/PendingImportController.php + views/BatchImport.vue → importación por lote.
- Servicios de negocio a reusar para lo importado: RegisterInventoryMovement, generadores de asiento,
  PurchaseController (compras), WithholdingEmitController (retenciones).

DISEÑO (pluggable)
1) Interfaz app/Services/Sri/SriImportProvider.php con métodos:
     emitidos($desde, $hasta), recibidos($desde, $hasta), retenciones($desde, $hasta)
   Cada uno devuelve una lista normalizada de comprobantes.
2) Implementaciones:
   - ClaveAccesoProvider → usa SriXmlDownloader (gratis; requiere las claves de acceso).
   - XmlUploadProvider → usa ParseSriPurchaseXml (gratis; el usuario sube los XML).
   - ApiPagaProvider → el aggregator pago (Datil / Contífico API / etc.). Va DETRÁS de config
     (SRI_IMPORT_PROVIDER + credenciales en .env). Si no está configurado, NO se usa.
3) Selector: config('sri.import_provider') decide cuál se usa. Default = clave de acceso / XML.

ENDPOINT
- POST /sri/importar  { tipo: emitidos|recibidos|retenciones, desde, hasta }  (auth:sanctum)
  → resuelve el provider configurado → trae los comprobantes → por cada uno crea la compra/venta/
    retención + inventario + asiento (reusando los servicios). Devuelve { importados, errores[] }.
  → try/catch por comprobante: uno que falle no corta el lote.

FRONTEND
- Ampliar views/BatchImport.vue (o vista nueva "Importar del SRI"): elegir tipo + rango de fechas,
  botón "Importar", y tabla de resultados (importados / con error). Estilo KBS.

REGLAS
- Importar NO emite al SRI (solo lee y guarda). Nada por fuera de liberias_sri/.
- Lo importado alimenta compras/ventas/retenciones + inventario + asientos (reusar servicios).
- El ApiPagaProvider queda como esqueleto detrás de config hasta que el cliente defina el proveedor.
- Después: npx vite build (limpio) + php artisan contable:chequeo (TODO OK).

VERIFICAR
- Subir un XML de compra → crea proveedor + compra + asiento (confirmar que ya anda).
- Por clave de acceso → baja y parsea 1 comprobante y lo guarda.
- (Con ApiPagaProvider configurado, si hay credenciales) traer un rango → importa varios.
- Sin provider pago configurado → el sistema NO rompe, ofrece clave de acceso / XML.

EMPEZÁ
Leé SriXmlDownloader.php, ParseSriPurchaseXml.php y BatchImport.vue. Armá primero la interfaz
SriImportProvider + el ClaveAccesoProvider (gratis), después el endpoint, y dejá el ApiPagaProvider
como esqueleto. Mostrame el flujo gratis andando antes de tocar la parte paga.
```

---

# 💬 Resumen para vos (y para Javier)
- **3 de las 4 partes del feature estrella ya están** (autocompletar por RUC, emitir+firmar, RUC/cédula dinámico).
- **Esta parte 4 (importar)** se construye con lo de arriba: **gratis** por clave de acceso/XML, y la
  versión "trae todo solo" **necesita la API paga** — que Javier tiene que decidir y contratar.
- Con este diseño, cuando la contrate, se conecta y listo, sin rehacer nada.

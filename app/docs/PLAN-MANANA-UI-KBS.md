# 🗺️ PLAN DE MAÑANA — UI densa estilo KBS + módulos faltantes

> Documento único para ejecutar VOS mañana, por fases. Marcá cada casilla al terminar.
> Meta: que las pantallas se vean con el MISMO PATRÓN Y DENSIDAD que KBS (master-detail,
> pestañas, grillas fiscales, toolbar). NO pixel-perfect — patrón y densidad.

---

## 🎯 ALCANCE DEFINIDO (decidido)

**EN ALCANCE (se hace):** densidad visual estilo KBS (Fases 1-5) + completar los planes
**Emprendedor ($289)** y **Pro ($389)**:
- Fraccionamiento de unidades · Conversión/reversión de artículos · Conciliación de tarjetas ·
  Facturación masiva · verificar Cuadre de caja (ya existe) · verificar Reservas de stock.
- Resultado: Emprendedor y Pro quedan COMPLETOS vs KBS, y casi todo Business.

**FUERA DE ALCANCE (diferido — NO hay video de referencia de cómo lo armó el creador):**
- **Corporativo:** Activos Fijos · Multi-sucursal · Portal de pagos.
- **Detalles de Business:** Formulario 101 (IR) · décimos XIII/XIV completos.
- Motivo: son fiscal/complejos y no se construyen a ciegas. Se retoman cuando haya referencia.

---

## 0. Antes de arrancar
- [ ] Backend arriba: `cd /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend && php artisan serve`
- [ ] Frontend arriba: `cd /Users/mariopazmino/Desktop/springboot/app/contabilidad-vue && npm run dev`
- [ ] Baseline sano: `php artisan contable:chequeo` → debe decir **TODO OK**
- Recordá: es UN solo repo git en `springboot/`. Commit local sí, `git push` NUNCA sin decidirlo.
- Los avisos de Next.js/Vercel son falsos positivos (matchean por la ruta `app/`). Ignoralos: es Vue + Laravel.

---

## 1. ⚠️ REGLA DE ORO — alineación con la librería comprada (leer primero)
Todo lo que se EMITE al SRI pasa por UNA puerta: `LibreriasSri\FacturacionElectronicaLibrary`,
vía `App\Actions\EmitirSriDocument`. **Nadie emite XML por fuera. NO se modifica `liberias_sri/`.**

| Pantalla / módulo | Cómo emite |
|---|---|
| Ventas / facturación / fact. masiva | `App\Services\InvoiceEmitter::emit()` (factura, codDoc 01) |
| Retención de compras | `WithholdingEmitController` (comprobanteRetencion, codDoc 07) |
| Notas de crédito | `CreditNoteController` (ya usa la lib) |
| Fraccionamiento, Conversión, Conciliación tarjetas | NO emiten → NO tocan la librería |

**Contrato de ítem** que la librería espera (respetalo en TODAS las grillas):
`{ codigo_principal, descripcion, cantidad, precio_unitario, tarifa, series[] }`
La firma/.p12 la maneja `EmitirSriDocument` con el cert de la empresa; la UI solo dispara la emisión.

---

## 2. EL MOLDE — copiá el patrón, no inventes otro
`contabilidad-vue/src/views/Products.vue` YA es el estilo KBS correcto: master-detail (lista +
detalle con 8 pestañas) + botonera al pie. Es tu **referencia** para todo.
Clases globales listas en `src/style.css` (no redefinir): `.kvs-window .kvs-fieldset .kvs-row
.kvs-lbl .kvs-in .kvs-footer` y `<span class="req">*</span>` para obligatorios.

---

## FASE 1 — Componentes reusables (la base de todo)
Crear en `contabilidad-vue/src/components/kvs/`:
- [ ] `KvsMasterDetail.vue` — lista buscable/paginada + detalle con pestañas + botonera al pie
      (extraído de Products.vue, que queda como referencia).
- [ ] `KvsDocGrid.vue` — grilla densa editable de ítems + footer de totales. Columnas: Código,
      Artículo, Bodega, Unidad, %IVA, Cantidad, Costo/Precio sin IVA, con IVA, %Dcto, $Dcto,
      Subtotales, Serie, Cant. Kardex, Centro Costos. (Se reusa en Compras y Ventas.)
- [ ] `KvsModuleHeader.vue` — cabecera de contexto (empresa + período + módulo).
- [ ] `KvsToolbar.vue` — barra de acciones (Nuevo, Guardar, Editar, Anular, Imprimir, Salir).
- [ ] `npx vite build` limpio.

## FASE 2 — COMPRAS densa (nueva vista `PurchaseEntry.vue`)
"Registro/Modificación de Compras" tipo KBS.
- [ ] Cabecera fiscal: Secuencia, Proveedor (Cédula/RUC), Establecimiento, Punto Emisión,
      No. Documento, Autorización, Sust. Tributario, F. Emisión/Caducidad, Bodega, Caja.
- [ ] Grilla de ítems con `KvsDocGrid` + totales (Subtotal, S. Gravado, S. IVA 0%, IVA, Total).
- [ ] Reusar backend: `PurchaseController`, modelo `Purchase`, `RegisterInventoryMovement`,
      generador de asientos. La compra se RECIBE (no se emite). Retención → `WithholdingEmitController`.
- [ ] NO tocar la importación por XML (sigue existiendo aparte).
- [ ] Verificar: guarda una compra, sube stock (kardex) y genera asiento. `contable:chequeo` OK.

## FASE 3 — VENTAS densa (mejorar `Pos.vue`, hoy simple ~117 líneas)
Formulario "VENTAS" tipo KBS.
- [ ] Cabecera: Cod/CI/RUC, Cédula/RUC/Pass, Código Alterno, Nombre, Dirección, Teléfono, Email,
      Vendedor, Precio (lista), Caja, Fecha, Referencia.
- [ ] Grilla `KvsDocGrid` + totales (Subtotal, S. Gravado, S. IVA 0%, S. Excento, S. No Objeto,
      Total Neto, Total IVA, Descuento, Total Factura).
- [ ] Al guardar EMITE con `InvoiceEmitter.emit()` (respetar el contrato de ítem).
- [ ] Verificar: emite factura → estado 'generado' (o AUTORIZADO si el .p12 está cargado).

## FASE 4 — FACTURAS DE VENTA listado denso (mejorar `Invoices.vue`, hoy ~145 líneas)
- [ ] Mantener el estado electrónico que YA muestra (`sri_document.estado` + `numero_autorizacion`).
- [ ] Columnas densas: Fecha, Secuencia, Serie, Número, Tipo Precio, Cliente, CI/RUC, Total,
      Estado, Estado Electrónico (Autorizado), Vendedor, Pago, Asiento, Creado.
- [ ] Filtros (año, número, cliente, CI/RUC, valor) + paginación + toolbar (Nuevo, Editar, Anular, Salir).

## FASE 5 — REPORTES con parámetros + PDF + export  (⚠️ el más grande — infra NUEVA)
Hoy NO hay librería de PDF ni de Excel en el backend. Pantalla KBS "Resumen Existencias".
- [ ] Backend: instalar `barryvdh/laravel-dompdf` (PDF) y export CSV (a mano) / `maatwebsite/excel`.
- [ ] Endpoint que recibe parámetros (Establecimiento, Bodega, Categoría, Artículo, Tipo Precio,
      Hasta, Orden) y devuelve PDF / Excel / CSV.
- [ ] Frontend `ReportViewer.vue`: formulario de parámetros KVS + preview del PDF (`<iframe>`) +
      botones Export Excel/Word/CSV.
- [ ] Empezar por UN reporte (Existencias por series/precios) como plantilla; el resto se clona.
> Si el tiempo no alcanza, esta fase queda para el siguiente bloque — con 1-4 ya tenés el demo.

## FASE 6 — Los 4 módulos que faltan (funcionalidad, estilo denso, gating por plan)
- [ ] Fraccionamiento de unidades (plan Emprendedor): campos unidad_base/fraccion/factor en products;
      al vender en unidad, el kardex descuenta el equivalente. Reusar `RegisterInventoryMovement`.
- [ ] Conversión/reversión de artículos (Pro): endpoint que baja stock de A y sube de B, kardex en
      ambos. Vista `ArticleConversion.vue`.
- [ ] Conciliación de tarjetas (Pro): como la conciliación bancaria pero con comisión del procesador
      (venta con tarjeta vs depósito neto). Replicar el patrón de conciliación bancaria existente.
- [ ] Facturación masiva (Pro): endpoint `POST /invoices/masiva` que recorre una lista y llama
      `InvoiceEmitter.emit()` por cada una, con try/catch por factura (una que falle no corta el lote).
- [ ] Cuadre de caja YA existe (`Cash.vue`/`CashController`) — solo restyle a KVS si le falta.
- [ ] Reservas de stock (Pro): primero VERIFICAR si ya existe (grep `reserva` en el backend);
      si no, agregar reserva de stock disponible en la venta (apartar sin descontar hasta confirmar).
- [ ] Registrar cada vista nueva en `src/modules.ts` con su `feature`, y agregar la feature al plan
      correcto en `contabilidad-backend/config/planes.php` (fraccionamiento→emprendedor; resto→pro).

---

## Archivos clave
- Molde/referencia: `contabilidad-vue/src/views/Products.vue`
- Componentes nuevos: `contabilidad-vue/src/components/kvs/*.vue`
- Vistas nuevas/mejoradas: `PurchaseEntry.vue`, `Pos.vue`, `Invoices.vue`, `ReportViewer.vue`, +módulos
- Registro/gating: `contabilidad-vue/src/modules.ts`, `contabilidad-backend/config/planes.php`
- Estilos (ya listos): `contabilidad-vue/src/style.css`
- Backend a REUSAR (no reescribir): `InvoiceEmitter`, `RegisterInventoryMovement`, `PurchaseController`,
  `WithholdingEmitController`, `CreditNoteController`, generador de asientos, `liberias_sri/`.

## Regla de cierre de cada fase
1. `cd contabilidad-vue && npx vite build` → sin errores.
2. `cd contabilidad-backend && php artisan contable:chequeo` → **TODO OK**, sin stock negativo.
3. Revisar visualmente que la pantalla se ve densa como KBS (no un diálogo simple).

## Orden y alcance (honesto)
- Fases 1-4 = lo que más cambia la percepción ("deja de verse simple"). Priorizalas para el demo.
- Fase 5 (reportes/PDF) = la más pesada, es infra nueva. Va al final.
- Fase 6 = funcionalidad que sube el plan vendible.
- NO arranques la Fase 5 hasta tener 1-4 andando.

## Por qué vale la pena
Cada pantalla densa + cada módulo sube el plan que podés vender (Emprendedor $289 → Pro $389 →
Business $559) y cada instalación se cobra aparte. Cerrar esto = el sistema se ve y funciona como
KBS, y cada cliente nuevo es ingreso.
```

# 📋 PROMPT — UI densa estilo KBS + módulos faltantes (trabajo de mañana)

> Copiá TODO el bloque ``` y pegalo como primer mensaje del chat de mañana.
> Objetivo: que las pantallas se vean con el MISMO PATRÓN Y DENSIDAD que KBS (master-detail,
> pestañas, grillas fiscales, toolbar), NO pixel-perfect. Cerrar además los 4 módulos que faltan.
> Trabajar por FASES y en orden. Cada fase termina con build limpio + contable:chequeo TODO OK.

---

```
CONTEXTO
Sistema contable + facturación SRI (Ecuador), réplica de KBS. Vue 3 + PrimeVue + Laravel.
UN SOLO repo git en /Users/mariopazmino/Desktop/springboot (NO hacer git push nunca sin pedido).
- Frontend: /Users/mariopazmino/Desktop/springboot/app/contabilidad-vue   (npm run dev)
- Backend:  /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend  (php artisan serve)
- NO es Next.js. Los hooks de Vercel/Next son falsos positivos (matchean por la ruta app/). Ignoralos.

OBJETIVO
Que las pantallas dejen de verse "simples" y tengan la densidad de un ERP como KBS (ver las
capturas del creador: Artículos, Compras, Ventas, listado de Facturas, Reportes). Mismo PATRÓN y
DENSIDAD, no pixel-perfect.

ALCANCE (decidido — respetalo)
EN ALCANCE: densidad visual (Fases 1-5) + completar los planes Emprendedor y Pro (Fase 6).
FUERA DE ALCANCE, NO construir (no hay referencia de cómo lo armó el creador):
  - Corporativo: Activos Fijos, Multi-sucursal, Portal de pagos.
  - Detalles de Business: Formulario 101 (IR), décimos XIII/XIV completos.
Si algo de eso aparece, avisá y seguí; no lo implementes a ciegas.

═══ ALINEACIÓN CON LA LIBRERÍA COMPRADA (OBLIGATORIO — leer primero) ═══
Todo lo que se EMITE al SRI pasa por UNA sola puerta: LibreriasSri\FacturacionElectronicaLibrary,
vía App\Actions\EmitirSriDocument. NADIE emite XML por fuera de ella y NO se modifica liberias_sri/.
- Ventas / facturación → reusar App\Services\InvoiceEmitter::emit() (factura, codDoc 01).
- Retención de compras → WithholdingEmitController (comprobanteRetencion, codDoc 07), ya usa la lib.
- Notas de crédito → CreditNoteController, ya usa la lib.
- Fraccionamiento, Conversión, Conciliación de tarjetas → NO emiten → NO tocan la librería.
- Contrato de ítem que la librería espera (respetarlo en las grillas):
  { codigo_principal, descripcion, cantidad, precio_unitario, tarifa, series[] }
- La firma/.p12 la maneja EmitirSriDocument con el cert de la empresa; la UI solo dispara la emisión.

EL MOLDE (copiá su patrón, NO inventes otro)
contabilidad-vue/src/views/Products.vue YA es el estilo KBS correcto: master-detail (lista +
detalle con 8 pestañas) + botonera al pie, con sus estilos scoped .kvs-split/.kvs-panel/.kvs-tabs/
.kvs-footer. Es la REFERENCIA de todas las pantallas.
Clases globales en src/style.css (no redefinir): .kvs-window .kvs-fieldset .kvs-row .kvs-lbl
.kvs-in .kvs-footer  +  <span class="req">*</span> para obligatorios.

═══ FASES (en orden) ═══

FASE 1 — Componentes reusables (base de todo). Crear en src/components/kvs/:
  - KvsMasterDetail.vue → shell: lista buscable/paginada + detalle con pestañas + botonera al pie
    (extraído de Products.vue, que queda como referencia).
  - KvsDocGrid.vue → grilla densa editable de ítems con footer de totales. Columnas: Código,
    Artículo, Bodega, Unidad, %IVA, Cantidad, Costo/Precio sin IVA, con IVA, %Dcto, $Dcto,
    Subtotales, Serie, Cant. Kardex, Centro Costos. Reusable en Compras y Ventas.
  - KvsModuleHeader.vue → cabecera de contexto (empresa + período + módulo), como el título verde
    "MAC PHONE (001) — 01/01/2026 al 31/12/2026 · Inventario".
  - KvsToolbar.vue → barra de acciones (Nuevo, Guardar, Editar, Anular, Imprimir, Salir).

FASE 2 — COMPRAS densa (nueva vista PurchaseEntry.vue) = "Registro/Modificación de Compras" KBS.
  Cabecera fiscal (Secuencia, Proveedor por Cédula/RUC, Establecimiento, Punto Emisión, No.
  Documento, Autorización, Sust. Tributario, F. Emisión/Caducidad, Bodega, Caja) + KvsDocGrid +
  totales (Subtotal, S. Gravado, S. IVA 0%, IVA, Total). Reusar PurchaseController + Purchase +
  RegisterInventoryMovement + generador de asientos. La compra se RECIBE (no se emite); si genera
  retención, va por WithholdingEmitController. NO tocar la importación por XML (sigue aparte).

FASE 3 — VENTAS densa (mejorar Pos.vue, hoy 117 líneas y simple) = formulario "VENTAS" KBS.
  Cabecera Datos Cliente/Factura (Cod/CI/RUC, Cédula/RUC/Pass, Código Alterno, Nombre, Dirección,
  Teléfono, Email), Vendedor, Precio (lista), Caja, Fecha, Referencia + KvsDocGrid + totales
  (Subtotal, S. Gravado, S. IVA 0%, S. Excento, S. No Objeto, Total Neto, Total IVA, Descuento,
  Total Factura). Al guardar EMITE reusando InvoiceEmitter.emit() (respetar el contrato de ítem).

FASE 4 — FACTURAS DE VENTA listado denso (mejorar Invoices.vue, hoy 145 líneas).
  Ya muestra estado electrónico (sri_document.estado + numero_autorizacion) — mantenerlo.
  Sumar columnas densas: Fecha, Secuencia, Serie, Número, Tipo Precio, Cliente, CI/RUC, Total,
  Estado, Estado Electrónico (Autorizado), Vendedor, Pago, Asiento, Creado. Filtros (año, número,
  cliente, CI/RUC, valor) + paginación + toolbar (Nuevo, Editar, Anular, Salir).

FASE 5 — REPORTES con parámetros + PDF + export  (⚠️ el más grande: infraestructura NUEVA).
  Hoy NO hay librería de PDF ni de Excel en el backend. Pantalla KBS "Resumen Existencias":
  panel de parámetros (Establecimiento, Bodega, Categoría, Artículo, Tipo Precio, Hasta, Orden) +
  vista previa PDF + botones Export Excel/Word/CSV.
  - Backend: instalar un generador de PDF (barryvdh/laravel-dompdf) y export (maatwebsite/excel o
    CSV a mano). Endpoint que recibe los parámetros y devuelve el PDF/Excel/CSV.
  - Frontend: vista ReportViewer.vue con el formulario de parámetros KVS + <iframe>/preview del PDF
    + botones de export.
  - Empezar por UN reporte (Existencias por series/precios) como plantilla; el resto se clona.

FASE 6 — Completar Emprendedor + Pro (funcionalidad, estilo denso, gating por plan):
  - Fraccionamiento de unidades (Emprendedor): campos unidad_base/fraccion/factor en products; al
    vender en unidad el kardex descuenta el equivalente. Reusar RegisterInventoryMovement.
  - Conversión/reversión de artículos (Pro): endpoint que baja stock de A y sube de B, kardex en
    ambos. Vista ArticleConversion.vue.
  - Conciliación de tarjetas (Pro): como la conciliación bancaria pero con comisión del procesador.
  - Facturación masiva (Pro): POST /invoices/masiva recorre una lista y llama InvoiceEmitter.emit()
    por cada una, try/catch por factura (una que falle no corta el lote).
  - Reservas de stock (Pro): primero VERIFICAR si ya existe (grep 'reserva' en el backend); si no,
    apartar stock disponible en la venta sin descontar hasta confirmar.
  - Cuadre de caja: YA existe (Cash.vue/CashController) — solo restyle a KVS si le falta.
  Registrar cada vista en src/modules.ts con su feature y agregar la feature al plan que
  corresponde en config/planes.php (fraccionamiento→emprendedor; el resto→pro).
  NO construir nada del Corporativo ni los detalles de Business (ver ALCANCE arriba).

REGLAS
- Reusar servicios: InvoiceEmitter (emisión+firma+SRI), RegisterInventoryMovement, generador de
  asientos. NO reimplementar lo que ya existe. NO tocar liberias_sri/.
- Formularios con densidad KBS (ver Products.vue). Reusar los componentes de la Fase 1.
- No agregar librerías salvo las de la Fase 5 (PDF/export), y solo ahí.
- Después de CADA fase: `cd contabilidad-vue && npx vite build` (limpio) y
  `cd contabilidad-backend && php artisan contable:chequeo` (TODO OK).

VERIFICAR AL FINAL
- Build limpio. contable:chequeo = TODO OK, sin stock negativo.
- Compras densa guarda una compra: sube stock (kardex) + genera asiento.
- Ventas densa emite: factura en 'generado' (o AUTORIZADO si el .p12 está cargado), y una del lote
  masivo que falle no corta el resto.
- Listado de facturas muestra Estado Electrónico. Un reporte exporta a PDF y CSV.
- Cada módulo nuevo aparece en el menú SOLO en su plan.

EMPEZÁ
Leé Products.vue (el molde) y armá la FASE 1 (componentes reusables). Mostrámelos antes de seguir
con la Fase 2. No arranques la Fase 5 (reportes/PDF) hasta tener las 1-4 andando.
```

---

# ⚠️ Nota honesta de alcance (para vos, no va en el prompt)

Esto es **medio-alto esfuerzo**, por eso el prompt lo parte en 6 fases. Lo que más cambia la
percepción ("deja de verse simple") son las Fases 1-4. La **Fase 5 (reportes con PDF/export) es la
más pesada** porque hoy no existe nada de eso — es infraestructura nueva (librería de PDF + export).
Si mañana el tiempo alcanza para 1-4, ya tenés Compras, Ventas y listados densos como KBS para el
demo; reportes y módulos quedan para el siguiente bloque.

# 💰 Por qué vale la pena
Cada pantalla densa + cada módulo sube el plan vendible (Emprendedor → Pro → Business) y cada
instalación se cobra aparte. Cerrar esto = el sistema se ve y funciona como KBS, y cada cliente
nuevo es ingreso.

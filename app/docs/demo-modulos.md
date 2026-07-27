# 📋 demo-modulos.md — Prompt único para terminar el sistema (backend + UX KBS/MicroPlus)

> Copiá TODO el bloque ``` y pegalo como primer mensaje del chat. Es el prompt completo: backend,
> feature estrella, UX marca HasReset, formularios estilo KBS/MicroPlus y CRUD en todos los módulos.
> Trabajar por fases EN ORDEN. Cada fase cierra con `npx vite build` limpio + `php artisan contable:chequeo` TODO OK.

---

```
CONTEXTO
Sistema contable + facturación SRI (Ecuador), réplica de KBS/MicroPlus (uso comercial autorizado).
Vue 3 + PrimeVue + Laravel. UN repo git en /Users/mariopazmino/Desktop/springboot (NO git push sin pedido).
- Frontend: app/contabilidad-vue (npm run dev) · Backend: app/contabilidad-backend (php artisan serve)
- NO es Next.js. Los hooks de Vercel/Next son falsos positivos (matchean por la ruta app/). Ignoralos.

OBJETIVO
Dejar el sistema listo para entregar: todos los módulos con UX estilo KBS/MicroPlus + CRUD completo,
el feature estrella funcionando, y la marca HasReset. Para que la contadora valide y se cobre.

═══ REGLA DE ORO — ALINEACIÓN CON LA LIBRERÍA (leer primero) ═══
Todo lo que EMITE al SRI pasa SOLO por la librería LibreriasSri\FacturacionElectronicaLibrary
(en app/contabilidad-backend/liberias_sri/), vía App\Actions\EmitirSriDocument. NADIE emite por
fuera y NO se modifica liberias_sri/. Ventas → InvoiceEmitter; retención → WithholdingEmitController;
nota de crédito → CreditNoteController. La firma usa el .p12 cargado de la empresa (acción del dueño).

EL MOLDE (copiar el patrón, NO inventar otro)
- src/views/Products.vue → master-detail (lista + detalle con pestañas) + botonera + CRUD. ES LA REFERENCIA.
- src/components/kvs/KvsMasterDetail.vue → reusarlo para no repetir el layout.
- Clases globales en src/style.css: .kvs-split .kvs-panel .kvs-tabs .kvs-row .kvs-lbl .kvs-in
  .kvs-footer + <span class="req">*</span> en obligatorios. Marca: --hr-navy --hr-blue --hr-blue-hover.

ESTADO ACTUAL (YA hecho — NO rehacer, solo verificar):
- Emisión: firma + envía + autoriza (librería) — verificado. Falta cargar el .p12 real (dueño).
- Autocompletar cliente por RUC: GET /sri/consulta (SriLookupController, con User-Agent de navegador
  — NO se lo saques o el SRI bloquea). Trae razón social, régimen, tipo. NO trae dirección ni correo.
- Formularios KBS + CRUD ya hechos: Contacts, Banks, Employees, Users, Warehouses, EmissionPoints,
  Products, Accounts(árbol). Componentes kvs y PurchaseEntry/ReportViewer también.
- Usuario contador creado (contador@demo.com / password123). Segundo email + alta inline en POS.
- Login rebrandeado (HasReset, botón Ingresar azul).

═══ FASE 1 — Completar el BACKEND (CRUD que falta) ═══
Agregar los métodos + rutas que faltan en estos controladores de ENTIDAD:
  - AdvanceController    → update, destroy
  - CreditNoteController → (documento fiscal: NO delete crudo → ANULAR: estado 'anulado' + reverso)
  - SerieController      → update, destroy
  - EmissionPointController → update
  - UserController       → destroy
Documentos fiscales (CreditNote, Advance) se ANULAN, no se borran (rompe la contabilidad).
NO tocar controladores de acción: Auth, Audit, Report, Accounting, Cash, Payroll, Invoice, MassInvoice,
InventoryTransaction, PendingImport, EdocConfig. Verificar: contable:chequeo TODO OK.

═══ FASE 2 — Feature estrella (facturación) ═══
2a) RUC/cédula dinámico al facturar (Pos.vue): al tipear, cambiar tipo_identificacion solo (13 díg →
    RUC 04; 10 díg → cédula 05) y disparar el autocompletar (ya existe). Emitir bien con ambos.
2b) Importar del SRI (emitidos/recibidos/retenciones) — arquitectura PLUGGABLE:
    Interfaz app/Services/Sri/SriImportProvider.php (emitidos/recibidos/retenciones por rango).
    Providers: ClaveAccesoProvider (gratis, reusa SriXmlDownloader), XmlUploadProvider (reusa
    ParseSriPurchaseXml), ApiPagaProvider (esqueleto detrás de config, para cuando el cliente pague).
    Endpoint POST /sri/importar {tipo,desde,hasta} → crea compra/venta/retención + inventario + asiento.
    HONESTO: "traer todo automático" = API paga; sin eso, clave de acceso o subir XML (gratis).

═══ FASE 3 — Marca HasReset (UX) ═══
- Lanzador (Home.vue): tiles de teal (#2f7676) → AZUL de marca (var(--hr-blue)); ícono + nombre
  dentro del tile; fondo navy (ya está) + logo HR arriba.
- Top bar (MainLayout.vue): marca HasReset + selector de empresa + usuario (ya existen).
- Título de la pestaña → "HasReset - Sistema Contable".

═══ FASE 4 — Ajustes pedidos por el cliente ═══
- Renombrar módulo "Contactos" → "Clientes" en modules.ts + el título de Contacts.vue (NO cambiar
  la key 'contacts' ni el endpoint).
- Quitar el estado ("generado") de la factura IMPRESA/preview en Invoices.vue (el objeto `preview`).
  El estado puede seguir en la LISTA, pero NO en la factura que se imprime.

═══ FASE 4b — Reportes estilo KBS ("Reportes Cuentas por Cobrar") ═══
El cliente quiere el módulo de reportes como KBS: elegir TIPO de reporte + parámetros + Generar/Resetear + salida PDF.
- YA existe base: src/views/ReportViewer.vue (reportes de inventario) + backend generatePdf/exportCsv
  en ReportController. Reusar eso.
- Construir vista "Reportes de Cuentas por Cobrar" con:
  * Panel izquierdo: lista de TIPOS de reporte (radio): Saldos Iniciales, Abonos (Resumido/Detallado),
    Anticipos (Resumido/Detallado/Saldos), Nota de Crédito (Res/Det), Nota de Débito (Res/Det),
    Cruce de Saldos (Res/Det), Facturas de Venta.
  * Panel derecho: parámetros (Desde, Hasta, Establecimiento, Secuencia, Comprobante, Categoría,
    Doc.Cliente, Cliente, Cerrado, Ordenar por) + botones Generar / Resetear.
  * Salida: preview PDF + export (reusar generatePdf/exportCsv).
- Backend: un endpoint por tipo de reporte (los datos de cartera están en ReceivableController).
- ESFUERZO: cada tipo es una consulta + layout. Empezar por Saldos + Facturas de Venta + Cruce de
  Saldos (los que la contadora más mira); el resto se suma después. NO hace falta los 13 para probar.

═══ FASE 5 — Replicar módulos desde capturas de KBS/MicroPlus (con las imágenes) ═══
Por CADA captura, seguir siempre:
  1. Leer: lista (columnas) + formulario (campos, pestañas) + botones.
  2. Mapear al molde (Products.vue / KvsMasterDetail). No inventar estilo.
  3. Si hay un campo que el backend no tiene → migración + fillable + validación (avisar cuál).
  4. Conectar CRUD real (crear/editar/eliminar de verdad).
  5. Verificar: build limpio + guarda de verdad + contable:chequeo TODO OK.

REGLAS
- Reusar KvsMasterDetail y el patrón de Products.vue. No repetir layout. No agregar librerías
  (salvo la de import paga, cuando se defina). No tocar liberias_sri/.
- Datos maestros (clientes, productos, bancos) → CRUD normal. Documentos fiscales → anular, no borrar.
- Después de CADA fase: npx vite build (limpio) + php artisan contable:chequeo (TODO OK).

VERIFICAR AL FINAL
- Facturar con RUC y con cédula → ambos emiten y autocompletan.
- Menú dice "Clientes"; factura impresa NO muestra "generado"; login azul; tiles azules.
- Cada módulo con datos crea/edita/borra (confirmar en la BD). Documentos se anulan.
- build limpio + contable:chequeo TODO OK.

EMPEZÁ
Leé Products.vue, KvsMasterDetail.vue, Pos.vue, Invoices.vue y modules.ts. Hacé la FASE 1 (backend)
y la FASE 4 (renombrar + quitar estado, son rápidas), mostrámelas, y seguí con 2, 3 y 5.
```

---

# 💬 Para vos (no va en el prompt)
- **Fases 1, 3, 4 las arrancás YA** (no necesitan capturas). La 5 es con las imágenes de KBS/MicroPlus.
- **La parte 2b (importar automático) necesita la API paga** que decide Javier — sin eso queda gratis
  por clave de acceso/XML. No lo prometas "automático total" sin definir la fuente.
- **Corporativo NO entra en los $350.** Con esto + el `.p12` → la contadora valida → cobrás los $260.

# 📋 PROMPT MAESTRO — Backend listo + UX KBS/MicroPlus (para entregar y cobrar)

> Ata todo: primero dejar el BACKEND completo, después replicar la UX de cada módulo desde las
> capturas de KBS/MicroPlus. Copiá el bloque ``` y pegalo. Fase A antes de las capturas; Fase B con ellas.

---

```
CONTEXTO
Sistema contable Vue 3 + PrimeVue + Laravel. UN repo git en /Users/mariopazmino/Desktop/springboot
(NO git push sin pedido). NO es Next.js (ignorar hooks de Vercel).
- Frontend: app/contabilidad-vue (npm run dev) · Backend: app/contabilidad-backend (php artisan serve)

OBJETIVO
Dejar el backend 100% listo (CRUD en todos los módulos-entidad) y replicar la UX de cada módulo
como KBS/MicroPlus de Ecuador, para entregar el sistema y cobrar. Uso comercial autorizado (contrato).

═══ FASE A — Completar el BACKEND (hacer PRIMERO, antes de las capturas) ═══
La emisión ya está (librería liberias_sri: firma + envía + autoriza). Falta cerrar el CRUD.

Controladores de ENTIDAD que les falta CRUD (agregar los métodos que faltan + su ruta):
  - AdvanceController    → falta update y destroy
  - CreditNoteController → falta update y destroy
  - SerieController      → falta update y destroy
  - EmissionPointController → falta update
  - UserController       → falta destroy
Los demás (Contact, Product, Bank, Employee, Warehouse, Account) YA tienen CRUD completo.

OJO — documentos fiscales: CreditNote y Advance NO se "editan/borran" como una tabla común (rompe
la integridad contable). Para esos, "CRUD" = crear + leer + ANULAR (no hard delete). Implementar
anulación (estado 'anulado' + reverso de asiento/inventario), NO un delete crudo.

NO tocar los controladores de ACCIÓN (no son entidades CRUD): Auth, Audit, Report, Accounting,
Cash, Payroll, Invoice (emisión), MassInvoice, InventoryTransaction, PendingImport, EdocConfig.

Importación del SRI → seguir docs/PROMPT-IMPORTAR-SRI.md (arquitectura pluggable; gratis por clave
de acceso/XML, API paga cuando el cliente la contrate).

Verificar Fase A: php artisan contable:chequeo → TODO OK. Cada endpoint responde a las 4 operaciones.

═══ FASE B — Replicar la UX de cada módulo desde captura KBS/MicroPlus (con las imágenes) ═══
Por CADA captura que te pasen, seguir SIEMPRE estos pasos:
  1. Leer la captura: LISTA (columnas) + FORMULARIO (campos, pestañas) + BOTONES (Nuevo/Guardar/
     Editar/Eliminar/Imprimir).
  2. Mapear al patrón KBS ya hecho: src/views/Products.vue (molde) + src/components/kvs/KvsMasterDetail.vue.
     Clases globales en src/style.css (.kvs-split .kvs-panel .kvs-tabs .kvs-row .kvs-lbl .kvs-in
     .kvs-footer + <span class="req">*</span>). NO inventar otro estilo.
  3. Si el formulario tiene un campo que el backend NO tiene → migración (agregar columna) + fillable
     + validación. Avisar cuál se agregó.
  4. Conectar el CRUD real contra el endpoint (crear/editar/eliminar de verdad, no solo pantalla).
  5. Verificar: npx vite build (limpio) + guardar/editar/borrar funciona + contable:chequeo TODO OK.

ALINEACIÓN LIBRERÍA (obligatorio)
Nada emite al SRI por fuera de liberias_sri/. Ventas → InvoiceEmitter; retención →
WithholdingEmitController; nota de crédito → CreditNoteController. NO modificar liberias_sri/.

REGLAS
- Reusar KvsMasterDetail / patrón de Products.vue. No repetir layout a mano. No agregar librerías.
- Documentos fiscales se ANULAN, no se borran. Datos maestros (clientes, productos, bancos) sí CRUD normal.
- Cada bloque cierra con build limpio + contable:chequeo TODO OK.

EMPEZÁ
Hacé la FASE A completa (los 5 controladores + verificar). Mostrámela. Después, a medida que
lleguen las capturas de KBS/MicroPlus, aplicá la FASE B módulo por módulo.
```

---

# 💬 Para vos (no va en el prompt)
- **Fase A la podés arrancar YA** (no necesita las capturas) — deja el backend redondo.
- **Fase B** es el método repetible: vos mandás captura → se replica igual → CRUD andando.
- Con esto + el `.p12` cargado + el feature estrella (partes 1-3 listas, la 4 con API paga) →
  la contadora valida → **cobrás los $260 que faltan**.
- Recordá: corporativo y "traer todo del SRI automático" (API paga) van **aparte de los $350**.

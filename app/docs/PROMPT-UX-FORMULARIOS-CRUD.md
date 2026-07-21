# 📋 PROMPT — UX de formularios del creador (KBS) + CRUD completo

> Copiá TODO el bloque ``` y pegalo como primer mensaje del chat de la tarde.
> Objetivo: que TODOS los formularios de carga de datos se vean y funcionen como los del creador
> de KBS (master-detail + pestañas + botonera) y que cada uno tenga CRUD completo (crear, leer,
> editar, eliminar) contra la API. Trabajar por bloques; cada bloque cierra con build + chequeo OK.

---

```
CONTEXTO
Sistema contable + facturación SRI (Ecuador), réplica de KBS. Vue 3 + PrimeVue + Laravel.
UN SOLO repo git en /Users/mariopazmino/Desktop/springboot (NO git push sin pedido).
- Frontend: /Users/mariopazmino/Desktop/springboot/app/contabilidad-vue   (npm run dev)
- Backend:  /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend  (php artisan serve)
- NO es Next.js. Los hooks de Vercel/Next son falsos positivos (matchean por la ruta app/). Ignoralos.

OBJETIVO
Todos los formularios de carga de datos con la UX del creador de KBS y CRUD completo:
lista buscable a la izquierda + detalle con pestañas a la derecha + botonera al pie
(Nuevo / Editar / Guardar / Cancelar / Eliminar), guardando de verdad contra la API.

ESTADO ACTUAL (ya hecho esta sesión — NO rehacer, sí terminar el frontend):
- Componente base YA existe: src/components/kvs/KvsMasterDetail.vue (lista + pestañas + botonera).
  También KvsDocGrid, KvsModuleHeader, KvsToolbar.
- Molde de referencia YA hecho: src/views/Products.vue (master-detail + 8 pestañas + CRUD completo).
- Backend de las 3 features del cliente YA hecho (falta SOLO el frontend):
  * Segundo email: columna contacts.email2 migrada + validación en ContactController. Falta el CAMPO en el form.
  * Autocompletar SRI: endpoint GET /sri/consulta?identificacion=NNN (SriLookupController). RUC por
    servicio público del SRI; cédula sin RUC → requiere_carga_manual. Falta llamarlo desde el form.
    ⚠️ Verificar el endpoint público del SRI con un RUC REAL (probar curl) antes de confiar.
  * Alta de cliente inline en el POS: falta (backend de contacts ya soporta store).

ALINEACIÓN CON LA LIBRERÍA (OBLIGATORIO)
Nada emite al SRI por fuera de liberias_sri/. Ventas/factura → InvoiceEmitter; retención →
WithholdingEmitController; nota de crédito → CreditNoteController. Los formularios de datos
(clientes, productos, bancos, etc.) NO tocan la librería. NO modificar liberias_sri/.

EL MOLDE (copiá el patrón, no inventes otro)
- src/views/Products.vue → master-detail + pestañas + CRUD (crear/editar/eliminar). ES LA REFERENCIA.
- src/components/kvs/KvsMasterDetail.vue → reusalo para no repetir el layout en cada vista.
- Clases globales en src/style.css (no redefinir): .kvs-split .kvs-panel .kvs-tabs .kvs-row
  .kvs-lbl .kvs-in .kvs-footer  +  <span class="req">*</span> en obligatorios.

═══ BLOQUES DE TRABAJO (en orden) ═══

BLOQUE 1 — Terminar las 3 features del cliente (frontend)
  a) Campo "Correo (respaldo)" (email2) en el form de cliente (Contacts.vue), al lado del email.
  b) Autocompletar: al salir del campo identificación (@blur/enter) en Contacts.vue y Pos.vue,
     llamar GET /sri/consulta?identificacion=... y llenar razón social / (dirección si viene).
     Mostrar spinner; si requiere_carga_manual, dejar tipear a mano sin romper.
  c) Layout KBS del cliente: cédula y RUC lado a lado (como el video de Chris). Conviene extraer
     un src/components/ClienteForm.vue reusable entre Contacts.vue y el diálogo del POS.
  d) Alta inline en el POS: si la identificación no existe, abrir diálogo "Nuevo cliente"
     (ClienteForm), POST /contacts, y seguir la factura con ese cliente.

BLOQUE 2 — Migrar cada formulario de datos al patrón KBS con CRUD completo
  Usar KvsMasterDetail (o el patrón de Products.vue). Cada uno debe: listar, crear, editar y
  eliminar contra su endpoint, con la botonera al pie. Formularios a cubrir (prioridad de arriba):
    1. Contacts (clientes/proveedores) — el más usado
    2. Banks (bancos)
    3. Employees (empleados)
    4. Users (usuarios)
    5. Warehouses (bodegas)
    6. EmissionPoints (puntos de emisión)
    7. Accounts (plan de cuentas)
    8. Categories / catálogos que falten
  (Products.vue YA está — no tocar, solo usar de molde.)

BLOQUE 3 — Verificar CRUD real de cada formulario
  Para cada uno: crear un registro, editarlo, eliminarlo, y confirmar en la lista. Que el guardado
  llegue a la BD (no solo a la pantalla).

REGLAS
- Reusar KvsMasterDetail y el patrón de Products.vue. NO repetir el layout a mano en cada vista.
- No tocar el <script setup> de lógica existente más de lo necesario; el CRUD ya existe en varios
  controladores (apiResource). Si falta un endpoint (update/destroy), agregarlo en el controller.
- No agregar librerías nuevas.
- Después de CADA bloque: `cd contabilidad-vue && npx vite build` (limpio) y
  `cd contabilidad-backend && php artisan contable:chequeo` (TODO OK).

VERIFICAR AL FINAL
- Build del front limpio.
- En Contactos: tipear un RUC real → autocompleta razón social; guardar con 2 correos; editar; borrar.
- En el POS: cédula desconocida → alta inline → la factura sigue con ese cliente.
- Cada formulario de la lista del Bloque 2 crea/edita/borra de verdad.
- contable:chequeo = TODO OK.

EMPEZÁ
Leé Products.vue y KvsMasterDetail.vue (el molde). Después terminá el BLOQUE 1 (las 3 features del
cliente) y mostrámelo antes de seguir con el Bloque 2.
```

---

# ⚠️ Nota para vos (no va en el prompt)
- Lo de esta sesión (email2 + SriLookupController + ruta /sri/consulta) está **sin commitear**.
  Conviene commitearlo local antes de arrancar, así no se pierde (acordate del susto de ramas).
- El autocompletar por **cédula de persona natural** necesita una fuente paga (Registro Civil); el
  RUC es gratis por el SRI. Eso queda con `requiere_carga_manual` hasta que definas el proveedor.

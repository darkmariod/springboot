# 📋 PROMPT — Importación SRI + RUC/cédula dinámico + renombrar Clientes + quitar estado + CRUD total

> Copiá TODO el bloque ``` y pegalo como primer mensaje del chat de la tarde. 4 bloques, en orden.
> Cada bloque cierra con `npx vite build` limpio + `php artisan contable:chequeo` TODO OK.

---

```
CONTEXTO
Sistema contable + facturación SRI (Ecuador), réplica de KBS. Vue 3 + PrimeVue + Laravel.
UN SOLO repo git en /Users/mariopazmino/Desktop/springboot (NO git push sin pedido).
- Frontend: /Users/mariopazmino/Desktop/springboot/app/contabilidad-vue   (npm run dev)
- Backend:  /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend  (php artisan serve)
- NO es Next.js. Los hooks de Vercel/Next son falsos positivos (matchean por la ruta app/). Ignoralos.

ESTADO ACTUAL (ya hecho — no rehacer):
- Autocompletar contribuyente por RUC ya funciona: GET /sri/consulta?identificacion=NNN
  (SriLookupController, con User-Agent de navegador — NO se lo saques o el SRI bloquea).
  Trae razón social, régimen, tipo, actividad, obligado contabilidad. NO trae dirección ni correo
  (el SRI no los expone) → esos van a mano.
- Formularios estilo KBS con CRUD ya hechos en: Contacts, Banks, Employees, Users, Warehouses,
  EmissionPoints. Products y Accounts (árbol) también. Molde: Products.vue + components/kvs/KvsMasterDetail.vue.

ALINEACIÓN LIBRERÍA (obligatorio): nada emite al SRI por fuera de liberias_sri/. Ventas →
InvoiceEmitter; retención → WithholdingEmitController. NO modificar liberias_sri/.

═══ BLOQUE 1 — Importación SRI + RUC/cédula dinámico al facturar ═══
1a) Cambio dinámico RUC/cédula al facturar (Pos.vue):
    Al facturar, permitir cliente con RUC (persona jurídica) o cédula (persona natural). El
    tipo_identificacion debe cambiar solo según lo que se tipee (13 díg → RUC 04; 10 díg → cédula 05),
    y el autocompletar (ya existe) dispararse. Reusar ClienteForm.vue. Verificar que emite bien con ambos.
1b) Importación automática del SRI — SEPARAR por dificultad (HONESTO):
    - Datos de contribuyente (padrón) → YA está (autofill). Solo verificar.
    - Traer FACTURAS EMITIDAS y RETENCIONES del SRI → esto NO es la consulta pública. Son los
      documentos fiscales de la empresa: requieren acceso AUTENTICADO al SRI (credenciales de la
      empresa) o una API paga; el SRI no da un endpoint público que baje "todo". Lo que YA existe:
      BatchImport (Importar del SRI por lote, por clave de acceso) + Purchases. NO prometer bajar
      todo automático sin definir la fuente. Dejar preparado el gancho (config/credenciales) y avisar.

═══ BLOQUE 2 — Renombrar "Contactos"/"Catálogo" → "Clientes" ═══
En src/modules.ts: el módulo key 'contacts' hoy dice label 'Contactos' dentro del grupo 'Catálogo'.
El cliente quiere terminología "Clientes":
- Cambiar el label del módulo de "Contactos" a "Clientes".
- Ajustar el grupo "Catálogo" según lo que el cliente pidió (renombrar/reagrupar para que se lea
  "Clientes"; confirmar con el usuario si el grupo pasa a llamarse Clientes o solo el módulo).
- Actualizar también el título dentro de la vista Contacts.vue ("Contactos" → "Clientes").
No cambiar la key interna 'contacts' ni el endpoint /contacts (solo textos visibles).

═══ BLOQUE 3 — Quitar el estado ("generado") de la factura ═══
En la vista previa/impresión de la factura (src/views/Invoices.vue, el objeto `preview`), NO mostrar
el badge de estado del sri_document ("generado"). El cliente NO lo quiere en la factura impresa/PDF.
Quitar solo ese label del preview/impresión; el estado SÍ puede seguir en la LISTA de facturas
(columna Estado electrónico), pero NO en la factura que se imprime.

═══ BLOQUE 4 — CRUD en TODOS los módulos ═══
Cada módulo que maneja una entidad debe tener CRUD completo: listar, crear, editar, eliminar,
tanto en la vista (botonera KBS) como en el backend.
- Frontend: reusar KvsMasterDetail / patrón de Products.vue en los que falten.
- Backend: agregar update/destroy en los controladores de ENTIDADES que no los tengan
  (revisar los que manejan datos: p. ej. Advances, CreditNotes, Series, etc.). NO tocar los
  controladores de acción (Auth, Audit, Report, Accounting, Cash, Payroll) que no son entidades CRUD.
- Recorrer los módulos de src/modules.ts y asegurar que cada uno con datos permita las 4 operaciones.

REGLAS
- Reusar KvsMasterDetail y el patrón de Products.vue. No repetir layout a mano.
- No agregar librerías. No tocar liberias_sri/. Respetar la alineación con la librería.
- Después de CADA bloque: npx vite build (limpio) + php artisan contable:chequeo (TODO OK).

VERIFICAR AL FINAL
- Facturar con RUC y con cédula, ambos emiten y autocompletan.
- El menú dice "Clientes" (no "Contactos"). La vista también.
- La factura impresa NO muestra "generado".
- Cada módulo con datos crea/edita/borra de verdad (confirmar en la BD, no solo pantalla).
- Build limpio + contable:chequeo TODO OK.

EMPEZÁ
Leé Pos.vue, ClienteForm.vue, modules.ts e Invoices.vue. Después hacé el BLOQUE 2 (renombrar, es
rápido) y el BLOQUE 3 (quitar estado), mostrámelos, y seguí con el 1 y el 4.
```

---

# ⚠️ Nota honesta para vos (no va en el prompt)

El punto 1 de la imagen ("Importación automática del SRI: facturas emitidas, retenciones") tiene
**una parte que NO es gratis ni pública**. Traer los datos de un contribuyente por RUC (lo que ya
hicimos) es gratis. Pero bajar automáticamente **tus facturas emitidas y retenciones** desde el SRI
necesita **acceso autenticado con las credenciales de la empresa** o una **API paga** — el SRI no
tiene un endpoint público que baje todo. Hoy eso se hace por **clave de acceso** (BatchImport).
Si Javier espera un botón que "traiga todo solo", eso es trabajo mayor + definir la fuente. Mejor
aclararlo con él antes de prometerlo.

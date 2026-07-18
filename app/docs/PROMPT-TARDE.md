# 📋 PROMPT PARA LA TARDE — copiá y pegá esto al asistente

> Copiá TODO el bloque ``` de abajo y pegalo como primer mensaje del chat de la tarde.
> Objetivo: que TODOS los formularios se vean como el de EDocuments (el que le gustó a Javier):
> cabecera verde, secciones con recuadro, labels a la derecha con asterisco rojo.

---

```
CONTEXTO
Sistema contable + facturación SRI (Ecuador), réplica de KBS. Vue 3 + PrimeVue + Laravel.
- Frontend: /Users/mariopazmino/Desktop/springboot/app/contabilidad-vue   (npm run dev)
- Backend:  /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend  (php artisan serve)
- Capturas del sistema del creador (referencia): /Users/mariopazmino/Desktop/sistema-captura-creador/

OBJETIVO
El cliente Javier quiere que TODOS los formularios se vean como el de EDocuments
(contabilidad-vue/src/views/SignatureConfig.vue): ventana/diálogo con cabecera verde-azulada,
campos agrupados en secciones con recuadro (fieldset), labels alineados a la derecha con
asterisco rojo en los obligatorios, y botonera al pie (Cancelar + Guardar).

MOLDE EXACTO (ya existe, copialo — NO inventes otro estilo)
Leé estos 2 archivos que YA están en estilo KVS y usalos de plantilla:
- contabilidad-vue/src/views/SignatureConfig.vue  → ventana con fieldsets y secciones.
- contabilidad-vue/src/views/Products.vue          → master-detail con pestañas.

CLASES CSS — YA SON GLOBALES en contabilidad-vue/src/style.css (NO las redefinas, NO uses
<style scoped> para esto, solo poné las clases en el HTML):
- .kvs-fieldset  + <legend>  → recuadro de sección con título verde
- .kvs-row       → fila: label a la izquierda, campo a la derecha
- .kvs-lbl       → el label (alineado a la derecha)
- .kvs-lbl .req  → el asterisco rojo va como <span class="req">*</span> dentro del label
- .kvs-in        → el campo (input/select) ocupa el resto de la fila
- .kvs-footer    → barra de botones al pie
- .kvs-window / .kvs-window-title → para formularios de PÁGINA (no diálogo)
Los diálogos (<Dialog>) YA tienen la cabecera verde automática por CSS global.

PATRÓN DE CONVERSIÓN (aplicá esto en el <template> de cada formulario)

  ANTES (diálogo blanco suelto):
    <label>Razón social *<InputText v-model="form.razon_social" fluid /></label>
    <label>Dirección<InputText v-model="form.direccion" fluid /></label>

  DESPUÉS (estilo KVS):
    <fieldset class="kvs-fieldset">
      <legend>Datos del proveedor</legend>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req">*</span> Razón social:</label>
        <InputText v-model="form.razon_social" class="kvs-in" />
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl">Dirección:</label>
        <InputText v-model="form.direccion" class="kvs-in" />
      </div>
    </fieldset>

REGLA DE ORO
- Tocá SOLO el <template> (el HTML del formulario) y agregá las clases. El <script setup> y
  las llamadas a la API NO se tocan. La lógica de guardar/editar/eliminar queda igual.
- Agrupá los campos en 1 o 2 <fieldset> con <legend> descriptivo (ej. "Datos", "Contacto").
- Campos obligatorios: <span class="req">*</span> al inicio del label.

FORMULARIOS A CONVERTIR (en orden de importancia para el demo):
1. Contacts.vue      (clientes/proveedores — el más usado)
2. Banks.vue
3. Employees.vue
4. Users.vue
5. Warehouses.vue
6. EmissionPoints.vue
7. Advances.vue
8. CreditNotes.vue
9. Categories.vue (si existe)
(Suppliers, Accounts, Quotes ya tienen cabecera verde; refinalos al final si sobra tiempo.)

CÓMO TRABAJAR
- Convertí de a UNO. Después de cada uno: `cd contabilidad-vue && npx vite build`
  → tiene que decir "built in ..." sin errores.
- NO toques backend, rutas, controladores ni migraciones.
- No agregues librerías.

VERIFICAR AL FINAL
- Abrí el navegador, entrá a 2-3 formularios y confirmá que se ven como EDocuments.
- `cd contabilidad-backend && php artisan contable:chequeo` → tiene que decir TODO OK.

EMPEZÁ
Leé SignatureConfig.vue y Products.vue para ver el molde, después convertí Contacts.vue y
mostrame el resultado antes de seguir con el resto de la lista.
```

---

# ✅ LO QUE YA DEJÉ HECHO PARA VOS

- **Las 9 clases KVS** (`.kvs-window`, `.kvs-row`, `.kvs-lbl`, `.kvs-in`, `.kvs-footer`,
  `.kvs-fieldset`, etc.) **ya son globales** en `src/style.css`. El asistente solo pone las
  clases en el HTML — no tiene que escribir CSS.
- **Todos los diálogos** ya tienen la **cabecera verde automática**.
- El molde vivo está en `SignatureConfig.vue` (el de tu captura) y `Products.vue`.

Con eso, convertir cada formulario es **cambiar el HTML y agregar clases** — rápido y sin riesgo.

---

# 🔜 LAS FASES QUE FALTAN (después de la UX)

Todo lo demás está construido y verificado. En orden:

## 1. 🔥 Cargar el `.p12` real (10 min — bloquea el demo)
EDocuments → Configuración de firma → `.p12` + clave + SMTP → "Probar correo" → emitir factura
→ debe llegar a **AUTORIZADO**. Detalle en `HOY-TARDE.md`.

## 2. 🔥 Desplegar en Debian 13
Comandos completos en `HOY-TARDE.md` (SSH, nginx, MariaDB, SSL, backup).
⚠️ `php-soap` obligatorio · `APP_KEY` respaldada · `APP_DEBUG=false`.

## 3. Que la contadora pruebe → cobrar
Usuario `contador`, empresa en plan `business`, datos limpios con `DemoSeeder`.

## 4. Después (nuevo desarrollo)
**Activos Fijos** — el módulo fuerte de KBS que falta. Depreciación mensual automática, no
toca el resto del sistema. Pedí requisitos por escrito primero (las 16 preguntas en
`ENTREGA-BASICO-PRO.md`).

---

## ⚠️ Pendientes tuyos (no dependen del código)
1. **Confirmar el saldo**: leí $350 y también $260 en distintos mensajes.
2. **Borrar** `sistema-captura-creador/Screenshot 2026-07-16 at 1.43.17 PM.png` antes de subir
   el repo — tiene las credenciales reales del creador de KBS.

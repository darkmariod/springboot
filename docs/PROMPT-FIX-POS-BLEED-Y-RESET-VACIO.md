# PROMPT — (A) Corregir POS que se fuga sobre otras pestañas · (B) Dejar el sistema vacío para el cliente

Frontend Vue 3 + PrimeVue + Pinia (`app/contabilidad-vue`) · Backend Laravel (`app/contabilidad-backend`).
**No tocar git.** Dos tareas independientes; se pueden aplicar por separado.

---

# A) Bug: al abrir Cotizaciones (u otro módulo) aparece el Punto de Venta encima

## Síntoma
Con el sidebar global, al activar la pestaña "Cotizaciones" (o cualquier otra), el contenido del
**Punto de Venta** sigue visible y queda apilado arriba del módulo activo. Se ve "roto".

## Causa raíz
El shell (`layouts/MainLayout.vue`) oculta las pestañas inactivas con `v-show`:
```html
<component :is="componentMap[t.component]" v-show="tabs.activeKey === t.key" />
```
`v-show` funciona **fijando `display:none` en el estilo inline del elemento raíz** del componente.
Pero el root de `views/Pos.vue` **ya trae `display` en su estilo inline**:
```html
<div style="display:flex; flex-direction:column; height:100%; overflow:hidden;">
```
Ese `display:flex` inline pisa al `display:none` de `v-show`, así que el POS **nunca se oculta** y
se muestra sobre la pestaña activa. Es el único view con `display` inline en el root (Quotes,
Invoices, Contacts usan clases, por eso a ellos no les pasa).

## Fix (mover el `display` inline del root a una clase con scope)
En `views/Pos.vue`:

1. Cambiar el elemento raíz del `<template>`:
   ```html
   <!-- ANTES -->
   <div style="display:flex; flex-direction:column; height:100%; overflow:hidden;">

   <!-- DESPUÉS -->
   <div class="pos-root">
   ```

2. Agregar en el `<style scoped>` de `Pos.vue`:
   ```css
   .pos-root { display: flex; flex-direction: column; height: 100%; overflow: hidden; }
   ```

> Regla general para evitar que reaparezca: **ningún root de un view usado como pestaña debe llevar
> `display` en `style="..."` inline** — siempre en una clase. Así `v-show` puede ocultarlo.

## Verificación (A)
1. `cd app/contabilidad-vue && npx vite build` → sin errores.
2. Abrir Punto de Venta, luego Cotizaciones → **solo** se ve Cotizaciones (el POS ya no se fuga).
3. Cambiar entre Inicio / Clientes / Productos / POS / Facturas / Cotizaciones → cada pestaña
   muestra únicamente su propio contenido.

---

# B) Dejar el sistema VACÍO (sin datos, sin firma, sin archivos) para llenar con el cliente

## Objetivo
Entregar el sistema en blanco para hacer el alta paso a paso con el cliente: sin clientes,
productos, facturas, cotizaciones, inventario, movimientos ni certificado .p12 cargado.
Mantener solo la estructura mínima para poder operar.

## Qué se BORRA (datos y archivos)
Tablas transaccionales y de catálogo:
`contacts, products, product_series, invoices, invoice_payments, purchases, purchase_payments,
quotes, credit_notes, advances, withholdings, journal_entries, journal_entry_lines,
inventory_movements, sri_documents, bank_movements, banks, employees, payrolls, pending_imports`
(incluir cualquier otra tabla de datos que exista).

Certificado / firma (en la tabla `companies`):
- `certificado_p12 = null`
- `certificado_clave = null`
(así "Configuración de firma" queda vacío y el cliente sube su .p12 en vivo).

Archivos en `storage`:
- XML/PDF generados de comprobantes.
- Certificados subidos (p. ej. `storage/app/certificados/*` o donde se guarden).

Secuenciales:
- `companies.secuencial = 1` (y por punto de emisión si aplica).

## Qué se MANTIENE (estructura mínima para operar)
- La(s) empresa(s) con sus datos fiscales (RUC, dir, estab/pto emisión) — pero **sin** certificado.
- Usuario administrador + roles/permisos.
- Plan de cuentas (contabilidad), tasas de impuestos/retención.
- Un punto de emisión (001-001) y una bodega por defecto, para no bloquear la facturación.

> Si se prefiere que el cliente también cree bodega y punto de emisión desde cero, indicarlo y se
> quitan también. Por defecto se dejan para que pueda facturar sin fricción.

## Cómo implementarlo
Ya existe `database/seeders/DemoCleanSeeder.php`. Revisarlo y ajustarlo para que cumpla exactamente
lo de arriba. Si no borra el certificado ni los archivos de `storage`, agregarlo. Debe ser
**idempotente** (se puede correr varias veces).

Comando para dejar el sistema vacío en el VPS (una vez ajustado el seeder):
```bash
docker exec contable-backend php artisan db:seed --class=DemoCleanSeeder --force
docker exec contable-backend php artisan tinker --execute="\App\Models\Company::query()->update(['certificado_p12'=>null,'certificado_clave'=>null,'secuencial'=>1]);"
```
(Preferible que el propio seeder haga el update del certificado, para no depender del tinker.)

## Verificación (B)
1. Login → Inicio muestra $0 en KPIs, "Sin cotizaciones", "Aún no hay facturas".
2. Clientes / Productos / Facturas / Inventario → listas vacías.
3. "Configuración de firma" → sin certificado cargado.
4. `php artisan contable:chequeo` → sin asientos, sin stock negativo (TODO OK sobre base vacía).
5. Se puede dar de alta un cliente → un producto → emitir una factura de prueba sin errores.

## Nota importante
Esto deja el sistema en **PRUEBAS** y sin validez tributaria hasta que el cliente cargue su
certificado y se pase a PRODUCCIÓN. No borrar la estructura de plan de cuentas ni impuestos: sin
eso, los asientos automáticos fallan.

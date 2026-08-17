# Plan para terminar la app y venderla — 4 puntos

Sistema HasReset · Laravel 12 + Vue 3 (`app/`). **No tocar git** (commit lunes-viernes vos).
Los 4 puntos son independientes; hacelos en este orden.

---

# 1) PROMPT — Terminar la app el martes (lo que falta para vender)

Objetivo: completar los **3 comprobantes SRI faltantes** para que el plan Profesional quede
completo, más los arreglos ya identificados.

## 1.A — Los 3 comprobantes que faltan (lo principal)
Hoy el sistema emite Factura (01), Nota de crédito (04) y Retención (07). Faltan:
- **Liquidación de compra (03)**
- **Nota de débito (05)**
- **Guía de remisión (06)**

La librería `LibreriasSri` ya soporta los 6 tipos; falta armar el flujo en la app, **copiando el
patrón que ya funciona**:
> Referencia: `app/Http/Controllers/CreditNoteController.php` (NC, cod. 04) y
> `WithholdingEmitController.php` (retención, cod. 07). Cada uno: arma el payload → llama a
> `EmitirSriDocument` con su `codDoc` → firma → envía → autoriza → guarda estado.

Para cada comprobante nuevo, replicar:
1. **Controlador** `LiquidacionCompraController` / `NotaDebitoController` / `GuiaRemisionController`
   con método `emitir()` que arma el payload y llama a `EmitirSriDocument` con el `codDoc` correcto
   (`03` / `05` / `06`).
2. **Ruta** en `routes/api.php` (dentro del grupo autenticado), junto a las de NC y retención.
3. **Vista Vue** `LiquidacionCompra.vue` / `NotaDebito.vue` / `GuiaRemision.vue` (copiar la estructura
   de `CreditNotes.vue`).
4. **Registrar** en `modules.ts` (grupo Ventas/Compras) y en el `componentMap` de `MainLayout.vue`.

Datos propios de cada uno (el resto del payload es igual a una factura):
- **Liquidación de compra (03):** el emisor le compra a alguien sin factura; el "cliente" es el
  proveedor. Lleva datos del proveedor y detalle de la compra.
- **Nota de débito (05):** referencia el comprobante que modifica (`codDocModificado`, número y fecha
  del documento original) y el/los motivos con su valor.
- **Guía de remisión (06):** lleva transportista (RUC, nombre, placa), fechas de traslado, punto de
  partida/destino y el detalle de lo que se transporta. Es el que más campos nuevos tiene.

**Verificación:** emitir uno de cada tipo en PRUEBAS y confirmar **AUTORIZADO** (si el SRI dice
"secuencial registrado", subir el secuencial y reintentar).

## 1.B — Confirmar reportes tributarios
Revisar que el módulo de Impuestos genere los reportes **Formulario 103 y 104** en el formato que
espera el contador. Si faltan, agregarlos en `ReportController`.

## 1.C — Aplicar los arreglos ya identificados
Ejecutar `docs/PROMPT-TRABAJO-COMPLETO.md` (PASO 1: fix POS `v-show`→`v-if`; PASO 2: fix inventario
nota de crédito + stock negativo). Son rápidos y hay que tenerlos antes de vender.

## 1.D — Desplegar y probar
Build + `docker compose up -d --build` + probar en el navegador cada comprobante.

---

# 2) Separar cada plan: que el cliente NO acceda a lo que no compró

## Cómo funciona hoy (ya construido)
- `config/planes.php` define, por plan (emprendedor/pro/business/corporativo), la lista de
  `features` desbloqueadas.
- `Company::tieneFeature($f)` responde si el plan de la empresa incluye esa feature (y respeta el
  vencimiento del plan).
- Frontend: `modulesPara(plan.tiene)` **esconde** del menú los módulos cuya `feature` no está en el plan.
- Existe el middleware `app/Http/Middleware/EnsureFeature.php` que **bloquea** (402) si el plan no
  tiene la feature o venció.

## El problema (por eso "se ve todo")
1. El middleware `EnsureFeature` **existe pero no está aplicado a las rutas** → la API responde igual
   aunque el plan no incluya el módulo.
2. El frontend es **fail-open**: si el plan no carga, muestra todo.

## PROMPT — Cerrar el gating de planes
1. **Registrar el alias del middleware** (Laravel 12, en `bootstrap/app.php`):
   ```php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->alias(['feature' => \App\Http\Middleware\EnsureFeature::class]);
   })
   ```
2. **Aplicarlo a las rutas** por feature, en `routes/api.php`. Ejemplo:
   ```php
   Route::middleware('feature:series')->group(function () {
       Route::apiResource('series', SeriesController::class);
   });
   Route::middleware('feature:nomina')->group(function () { /* empleados, roles de pago */ });
   Route::middleware('feature:conciliacion')->group(function () { /* conciliación bancaria */ });
   // …una por cada módulo que dependa de un plan superior
   ```
   (Los módulos base —catálogo, ventas, facturación— van sin middleware: están en todos los planes.)
3. **Frontend fail-closed para producción:** en `stores/plan.ts`, cambiar el comportamiento cuando
   el plan no carga: en vez de mostrar todo, mostrar solo lo base. (Dejá el fail-open solo para demos
   internas si querés, con una bandera.)

**Verificación:** poné una empresa en plan Emprendedor y confirmá que al entrar a "Series" o "Nómina"
por API responde **402**, y que en el menú no aparecen.

---

# 3) Los planes finales (KBS + INTEKROSS combinados)

Ya está la página lista: `docs/planes-hasreset.html` (publicada). Combina la estructura realista de
**INTEKROSS** (el proveedor de tu librería) con el estilo de tarjetas de **KBS**:

| Plan | Precio | Qué desbloquea |
|---|---|---|
| Emprendedor | $49/año | POS + inventario, sin factura electrónica |
| Negocio | $99/año | + facturación electrónica SRI + traer cliente del SRI |
| Profesional | $149/año | + todos los comprobantes + series/garantías + conciliación |
| Empresarial | Personalizado | + nómina + multiempresa + corporativo a medida |

> Para que estos precios manden en el sistema, actualizá `config/planes.php` con estos nombres y
> `precio_anual` (hoy tiene los de KBS: $289/$389/$559/$659). Alineá los dos.

---

# 4) PROMPT — Landing page en Laravel (como KBS/INTEKROSS), sin botón al ERP

## Contexto de arquitectura (importante)
Hoy Nginx sirve la **SPA de Vue en `/`** y Laravel está detrás de `/api`. Para que la landing sea
pública y **el ERP quede separado sin botón de acceso**, la forma más limpia es:
- **Landing (Blade de Laravel) en `/`** → lo que ve cualquiera que entra al dominio.
- **ERP (SPA de Vue) en `/app`** → sin enlace desde la landing; se entra sabiendo la URL.

## PROMPT
1. **Blade de la landing.** Crear `resources/views/landing.blade.php` reutilizando el diseño de
   `docs/planes-hasreset.html` (hero + los 4 planes + pie). **No incluir ningún enlace ni botón a
   `/app` ni a "Ingresar".** Los botones de los planes van a un formulario de contacto / WhatsApp,
   no al login.
2. **Ruta web.** En `routes/web.php`:
   ```php
   Route::get('/', fn () => view('landing'));
   ```
3. **Mover el ERP a `/app` en Nginx** (`app/nginx.conf`):
   - `location /api` y `location /sanctum` → siguen yendo a Laravel (PHP-FPM), sin cambios.
   - `location = /` y las rutas públicas (`/planes`, assets de la landing) → a Laravel.
   - `location /app` → sirve la SPA de Vue (`try_files $uri /app/index.html`).
   - Ajustar el `base` del build de Vue a `/app/` (en `vite.config.*`: `base: '/app/'`) y el router
     a `createWebHistory('/app/')`.
4. **Sin rastros del ERP en la landing:** revisar que el HTML de la landing no tenga links a `/app`,
   ni "Ingresar", ni el logo enlazando al login. El acceso al sistema es solo por URL directa `/app`.

## Verificación
- Entrar al dominio raíz → se ve la landing con los planes, **sin ningún botón para entrar al sistema**.
- Entrar a `/app` a mano → carga el login del ERP.
- La landing no expone la existencia del `/app`.

> Alternativa más simple si no querés tocar Nginx: publicar la landing como un sitio estático aparte
> (el mismo `planes-hasreset.html`) en otro dominio/hosting, y dejar el ERP como está. Pero pediste
> "desde Laravel", así que arriba va la forma con Blade.

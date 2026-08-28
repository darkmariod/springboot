# PROMPT — Nuevo dashboard "Inicio" con sidebar (HasReset)

> Referencia visual: `mockup-dashboard-hasreset.html` (en la raíz del repo).
> Stack real: Laravel 12 (backend) + Vue 3 + PrimeVue + Pinia (frontend en `app/contabilidad-vue`).
> **No tocar git.** Trabajar solo en archivos; el commit lo hace el usuario.

## Objetivo

Agregar una pantalla **"Inicio"** tipo dashboard, con **navegación lateral (sidebar)** por
secciones, que resuma el estado del negocio: ventas, cobros, por cobrar, documentos recientes,
acciones pendientes y actividad. Debe verse profesional, con tema claro/oscuro, y datos reales
(no hardcodeados).

## Decisión de alcance (recomendada)

**AGREGAR, no reemplazar.** Mantener el sistema de pestañas actual (`MainLayout.vue` + store de
tabs) y añadir "Inicio" como una pestaña/vista más que se abre por defecto al entrar. El sidebar
del dashboard es interno a la vista Inicio; NO reemplaza el lanzador de módulos existente todavía.

> Motivo: el cliente ya vio el shell de pestañas estilo KBS. Cambiar todo el shell es un segundo
> paso separado. Este prompt entrega valor sin romper lo que ya funciona.

Íconos: usar `pi pi-*` de **PrimeIcons** (íconos de línea, ya instalados), no emoji.

---

## Backend

### 1. Endpoint de resumen
Crear `GET /api/dashboard/resumen?company_id={id}` → `DashboardController@resumen`.

Devuelve **datos calculados desde la base** (no inventados), con esta forma:

```json
{
  "periodo": "2026-08",
  "ventas_mes": 12450.00,
  "ventas_variacion_pct": 8.2,
  "cobrado_mes": 8200.00,
  "por_cobrar": 3450.00,
  "facturas_por_cobrar": 4,
  "documentos_recientes": [
    { "tipo": "Factura", "numero": "001-001-000000125", "cliente": "Comercial XYZ",
      "valor": 1150.00, "estado_sri": "AUTORIZADO" }
  ],
  "acciones": {
    "sri_pendientes": 3,
    "conciliaciones": 2,
    "facturas_vencidas": 4
  },
  "actividad": [
    { "tipo": "sri", "texto": "Factura 001-001-125 autorizada por el SRI",
      "cuando": "2026-08-08T14:52:00Z", "usuario": "Administrador" }
  ]
}
```

Cálculos (reutilizar modelos existentes; ver `InvoiceController`, `SriDocument`, `BankMovement`):
- `ventas_mes`: suma de `invoices.importe_total` del mes actual, estado no anulado.
- `ventas_variacion_pct`: contra el mes anterior (si mes anterior = 0 → `null`).
- `cobrado_mes`: suma de pagos/cobros registrados en el mes (InvoicePayment o equivalente).
- `por_cobrar` / `facturas_por_cobrar`: facturas a crédito con `saldo_pendiente > 0`.
- `documentos_recientes`: últimos 5 comprobantes (Invoice + SriDocument), ordenados por fecha desc.
  Mapear `estado_sri` desde `sriDocument.estado` (AUTORIZADO / enviado / NO AUTORIZADO / generado).
- `acciones.sri_pendientes`: SriDocument con estado en (`enviado`, `firmado`, `generado`).
- `acciones.conciliaciones`: BankMovement con `conciliado = false`.
- `acciones.facturas_vencidas`: facturas crédito con saldo y fecha vencida.
- `actividad`: últimos 8 eventos (autorizaciones SRI, cobros, movimientos de inventario recientes).

Ruta en `routes/api.php`, dentro del grupo autenticado (Sanctum), junto a las demás.

---

## Frontend

### 2. Vista `Home.vue`
Crear `app/contabilidad-vue/src/views/Home.vue`. Traducir el mockup a Vue + PrimeVue.
Estructura (ver `mockup-dashboard-hasreset.html` para el detalle visual):

- **Encabezado**: título "Resumen del negocio" + subtítulo con empresa/RUC/periodo (del company store).
- **3 KPIs** (`Ventas del mes`, `Cobrado`, `Por cobrar`) con:
  - valor grande con `font-variant-numeric: tabular-nums`,
  - sparkline SVG inline (endpoint puede devolver serie corta; si no, omitir la línea),
  - chip de tendencia (`▲ %` verde / plano gris).
  - "Por cobrar" con acento ámbar (`--warn`).
- **Documentos recientes**: tabla con chips de estado SRI:
  - AUTORIZADO → chip verde, enviado/en proceso → ámbar, NO AUTORIZADO → rojo.
  - Número de comprobante en fuente monoespaciada.
- **Acciones pendientes**: lista con badge numérico + texto + flecha. Cada ítem navega a su módulo.
- **Actividad**: feed con punto de color por tipo + timestamp relativo.

Usar `api.get('/dashboard/resumen?company_id=' + companyStore.activeId)` en `onMounted`.
Estado de carga con skeletons de PrimeVue mientras llega la respuesta.

### 3. Registrar "Inicio" en el shell
En `MainLayout.vue`:
- Agregar `Home` al `componentMap` (import de `views/Home.vue`).
- Abrir la pestaña "Inicio" por defecto al montar (si no hay pestañas abiertas).
- Agregar un tile "Inicio" en el lanzador de módulos si aplica.

### 4. Estilos y tema
- Reusar las variables de marca ya existentes (`--hr-blue`, `--hr-gradient`). Si no existen tokens
  de tema claro/oscuro, definir los del mockup (`--ground`, `--panel`, `--border`, `--good/warn/crit`)
  en un scope local de `Home.vue` o en el CSS global.
- Semánticos (verde/ámbar/rojo) SEPARADOS del azul de marca — el azul no es estado.
- Chips de estado con punto + etiqueta, no solo color (accesibilidad).

---

## Criterios de aceptación

1. `GET /api/dashboard/resumen` responde 200 con montos calculados desde la base real (verificar
   con la factura AUTORIZADA sembrada: aparece en `documentos_recientes` con chip verde).
2. La vista "Inicio" abre por defecto al entrar y muestra los 3 KPIs, la tabla, acciones y actividad.
3. Ningún dato hardcodeado en el frontend: todo viene del endpoint.
4. Tema claro y oscuro legibles; contraste correcto en ambos.
5. `npx vite build` compila sin errores. `php artisan contable:chequeo` sigue en TODO OK.
6. Responsive: en pantalla angosta el layout colapsa a una columna.

## Verificación

```bash
# Backend
php artisan test --filter Dashboard   # si se agregan tests
# Frontend
cd app/contabilidad-vue && npx vite build
# Datos sanos
php artisan contable:chequeo
```

Luego, en el navegador: login → debe abrir "Inicio" con los KPIs poblados y la factura
AUTORIZADA visible en "Documentos recientes".

## Fuera de alcance (siguiente iteración)

- Reemplazar por completo el shell de pestañas por el sidebar global.
- Filtros de rango de fechas en el dashboard.
- Gráficos grandes (por ahora solo sparklines en los KPIs).

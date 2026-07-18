# 🎨 FASE UX — Todos los formularios estilo KVS (imagen 1)

> El cliente quiere que **todos los formularios** se vean como la imagen 1 (ventana con
> cabecera verde-azulada de KVS), no como el Dialog blanco simple (imagen 2).

---

## ✅ YA HECHO (verificado en navegador el 17-jul)

**Un solo bloque de CSS global** pinta los **17 diálogos** de golpe con la cabecera verde de
KVS — sin tocar el código de cada formulario. Ya está aplicado en `src/style.css` y probado:
el diálogo "Nuevo proveedor" quedó con la cabecera verde.

> Los diálogos que ahora tienen el look KVS automáticamente: Proveedores, Cuentas, Contactos,
> Bancos, Anticipos, Notas de crédito, Puntos de emisión, Empleados, Cotizaciones, Usuarios,
> Bodegas, Cobros (Receivables), Pagos (Payables), etc.

**El CSS que se agregó** (por si tenés que reponerlo — está al final de `src/style.css`):
```css
.p-dialog { border: 1px solid #b9c2cc !important; border-radius: 6px !important; overflow: hidden; }
.p-dialog .p-dialog-header { background: linear-gradient(#3d8b8b, #2a6b6b) !important; color: #fff !important; }
.p-dialog .p-dialog-title { color: #fff !important; font-size: 13.5px !important; font-weight: 600 !important; }
.p-dialog .p-dialog-content { background: #fff !important; }
.p-dialog .p-dialog-footer { background: #f7f9fb !important; }
.req::after { content: " *"; color: #d93025; font-weight: 700; }
```

**Ventana completa (sin Dialog):** Artículos y EDocuments ya usan `.kvs-window`/`.kvs-fieldset`.

---

## 🎯 LO QUE FALTA (refinamiento, opcional para la tarde)

La cabecera verde ya está en todos. Lo que queda es **detalle fino**, y es opcional:
si la tarde viene corta, **saltealo** — con la cabecera verde ya se ve como KVS.

### Refinamiento A — Asterisco rojo consistente
Muchos formularios ya escriben `Razón social *` a mano. Para que se vea igual en todos,
en cada `<label>` de campo obligatorio poné la clase `req`:

```html
<!-- Antes -->
<label>Razón social *<InputText .../></label>

<!-- Después (el asterisco rojo lo pone el CSS solo) -->
<label class="req">Razón social<InputText .../></label>
```
El CSS `.req::after` ya está global — solo agregás la clase. **No es urgente.**

### Refinamiento B — Fieldsets con secciones (solo formularios grandes)
Solo vale la pena en los formularios largos. Envolvé grupos de campos en:
```html
<fieldset class="kvs-fieldset">
  <legend>Datos del proveedor</legend>
  ... los campos ...
</fieldset>
```
`.kvs-fieldset` ya está global. **Candidatos:** ninguno urgente — Proveedores, Empleados y
Usuarios son cortos y se ven bien sin secciones.

---

## 📋 Checklist de formularios (para ir tildando si querés el refinamiento)

Todos ya tienen la **cabecera verde** ✓. La columna "asterisco" es el refinamiento A opcional.

| Formulario | Cabecera KVS | Asterisco `req` |
|---|---|---|
| Proveedores | ✅ auto | ☐ opcional |
| Contactos | ✅ auto | ☐ opcional |
| Plan de cuentas | ✅ auto | ☐ opcional |
| Bancos | ✅ auto | ☐ opcional |
| Cotizaciones | ✅ auto | ☐ opcional |
| Anticipos | ✅ auto | ☐ opcional |
| Notas de crédito | ✅ auto | ☐ opcional |
| Empleados | ✅ auto | ☐ opcional |
| Usuarios | ✅ auto | ☐ opcional |
| Bodegas | ✅ auto | ☐ opcional |
| Puntos de emisión | ✅ auto | ☐ opcional |
| Cobros / Pagos | ✅ auto | ☐ opcional |
| **Artículos** | ✅ ventana completa | ✅ ya tiene |
| **EDocuments** | ✅ ventana completa | ✅ ya tiene |

---

## 🧪 Probar
```bash
cd contabilidad-vue && npm run dev
```
Abrí cualquier formulario (Proveedores → Nuevo proveedor) → **la cabecera debe ser verde**.
Si un diálogo quedó blanco, es que se cacheó el build viejo: `npm run build` de nuevo.

---

## ⚠️ Nota técnica
El CSS usa `!important` a propósito: PrimeVue trae su propio tema y sin `!important` el
gradiente no le gana. No es "mala práctica" acá — es la forma correcta de sobrescribir un
tema de terceros de forma global y consistente.

---

# 👉 DESPUÉS DE ESTO: seguir con el despliegue
Ver **[HOY-TARDE.md](HOY-TARDE.md)** — cargar el `.p12` → deploy Debian 13 → contadora → cobrar.
La UX de formularios **ya no bloquea nada**: se ve como KVS.

# ¿Qué falta para terminar? (para poder vender los planes)

Comparación entre lo que **prometen los planes** (INTEKROSS / KBS) y lo que tu sistema
**realmente hace hoy**. Ordenado por prioridad: primero lo que bloquea una venta, al final lo que no.

---

## ✅ Ya está listo para vender: plan NEGOCIO ($99)
Todo lo que promete este plan ya funciona:
Facturación electrónica SRI (verificada AUTORIZADO), traer cliente del SRI, certificado digital,
cotizaciones, compras, roles avanzados, POS, inventario, clientes/productos ilimitados.
**Este plan lo podés ofrecer hoy mismo.**

---

## 🔴 PRIORIDAD 1 — Falta para el plan PROFESIONAL ($149)
El plan Profesional promete "todos los comprobantes". Hoy el sistema emite 3 de 6.
**Faltan 3 comprobantes** (la librería ya los soporta; falta armar el flujo + pantalla, igual que
se hizo con Nota de Crédito y Retención):

1. **Guía de remisión (cod. 06)** — emisión al SRI + pantalla.
2. **Nota de débito (cod. 05)** — emisión al SRI + pantalla.
3. **Liquidación de compra (cod. 03)** — emisión al SRI + pantalla.

> Referencia de cómo hacerlo: mirar `CreditNoteController` (NC, cod. 04) y `WithholdingEmitController`
> (retención, cod. 07) — ya funcionan. Los nuevos siguen el mismo patrón: construir payload →
> `EmitirSriDocument` con su `codDoc` → firmar → enviar → autorizar.

---

## 🟠 PRIORIDAD 2 — Confirmar / pulir antes de vender
4. **Módulo tributario / Reportes formulario 103 y 104 (ATS)** — existe el módulo de Impuestos;
   confirmar que los reportes salen en el formato que el SRI/contador espera.
5. **Bug de inventario** (nota de crédito suma en vez de restar; sin bloqueo de stock negativo) —
   ver `docs/PROMPT-TRABAJO-COMPLETO.md`, PASO 2. Arreglar antes de vender inventario avanzado.
6. **Bug visual del POS** (se fuga sobre otras pestañas) — ver PASO 1 del mismo prompt. Es 1 línea.

---

## 🟢 NO bloquea nada — plan EMPRESARIAL (Personalizado)
El grupo **Corporativo** está deshabilitado en el código:
Activos Fijos, Producción, Ventas Online, Académico, Presupuestos, Centro de Costo.
Como el plan Empresarial es **"a cotizar a medida"**, esto **no te frena** ninguna venta.
Se desarrolla recién cuando un cliente lo pida y lo pague.

---

## Resumen en una línea
| Para vender… | ¿Listo? | Falta |
|---|---|---|
| **Negocio $99** | ✅ Sí | Nada |
| **Profesional $149** | 🔴 Casi | Guía de remisión, Nota de débito, Liquidación de compra (+ confirmar 103/104) |
| **Empresarial** | 🟢 N/A | Es personalizado — se cotiza y desarrolla a medida |

**Recomendación:** vendé ya el plan **Negocio**, y para completar el **Profesional** enfocá el
próximo trabajo en esos **3 comprobantes que faltan**. Es lo único que te separa de tener el
sistema "completo" como los planes que mostraste.

# 📚 Documentación del sistema — índice

## 👉 Archivo de trabajo actual
### **[FASES_PENDIENTES_ACTUAL.md](FASES_PENDIENTES_ACTUAL.md)** ← estado real + fases 9-13.
(Las fases 0-8 de FASES_COMPLETAS.md ya estan TODAS aplicadas y verificadas en la base.)

Ahí está el estado real, la lista de capturas de KVS que necesito, y las fases 9-13.

---

## Estado actual

### ✅ Construido y probado
Login · multiempresa · plan de cuentas en árbol · contactos · productos · bancos ·
**POS + facturación SRI** (clave de acceso de 49 dígitos) · **importar compras XML del SRI** ·
inventario + kardex + costo promedio · **asientos automáticos** · libro diario · libro mayor ·
**estados financieros que cuadran** · mayorizar/desmayorizar · caja + arqueo · conciliación
bancaria · cartera CxC/CxP · retenciones con empate automático · documentos SRI en lote ·
cotizaciones · **configuración de firma electrónica** · puntos de emisión.

**Interfaz (cerrada, igual a KVS):** menú lateral + pestañas que no se cierran · botón ⛶
**maximizar** (Esc restaura) · atajos `Ctrl+P` imprimir, `Ctrl+F` buscar, `Ctrl+W` cerrar pestaña.
Sin pantalla de inicio: el área de trabajo arranca vacía y abrís desde el menú.

### ✔️ Fases 0-8 — TODAS aplicadas y verificadas en la base (15-jul-2026)
Series/IMEI · usuarios/roles/auditoría · notas de crédito y anticipos · importación TXT en
lote · combos/min-max (tablas) · nómina · planes y features · firma electrónica. Auditado
tabla por tabla. `FASES_COMPLETAS.md` queda solo como referencia de cómo se construyó.

### 🆕 Además construido directo (no estaba en las fases)
Lanzador de tiles estilo KVS · módulo **EDocuments** · **preview RIDE** de factura con
Imprimir/PDF · ficha de producto estilo KVS · reportes de inventario con parámetros ·
procesar factura por factura en el lote · datos demo del video (`DemoSeeder`).

> 🗂️ **Archivos viejos** (`FASES_SIGUIENTES.md`, `FASE_10_*`, `FASE_11_*`, `FASE_12_*`,
> `PASOS_PENDIENTES_BACKEND.md`): superados. Podés borrarlos tranquilo.

### 📹 Contexto de las reuniones
- **[REUNION_CREADOR_KVS.md](REUNION_CREADOR_KVS.md)** — qué mostró el creador + **sus precios
  ($289/año el básico; la firma incluida es su gancho de venta)**
- **[transcripcion_creador_kvs.txt](transcripcion_creador_kvs.txt)** — transcripción completa (28 min)
- **[EXPLICACION_SRI_EMITIR_IMPORTAR.md](../../EXPLICACION_SRI_EMITIR_IMPORTAR.md)** (en el Escritorio) —
  emitir vs importar

---

## 🔑 Los 2 conceptos que no se te pueden olvidar

**1. Emitir vs importar:**
> El **.p12 firma lo que SALE** (tus facturas). Lo que **ENTRA viene del XML** del proveedor
> (te llega por correo, o lo bajás del portal SRI con RUC+clave). **El .p12 NUNCA se usa para importar.**

**2. Nómina:**
> El **9.45%** se le descuenta al empleado. El **11.15% + décimos + fondos + vacaciones** los paga
> la empresa y **NO van en el rol** — son provisiones.

---

## Cómo seguir
1. Abrí **[FASES_PENDIENTES_ACTUAL.md](FASES_PENDIENTES_ACTUAL.md)**.
2. Sacá las **capturas de KVS** que están listadas ahí (formularios abiertos, no listas).
3. Pedime las fases 9-13 **de a una** y te doy el código listo para pegar.
4. Orden recomendado: **13 (deploy) → 9 (RIDE+correo) → 10 → 11 → 12**.

> **Tu semáforo de siempre:** si los estados financieros dejan de cuadrar, el asiento quedó mal.

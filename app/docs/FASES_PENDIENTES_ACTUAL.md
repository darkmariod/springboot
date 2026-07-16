# 🎯 Plan de trabajo (actualizado 16-jul-2026)

## ✅ Hecho y verificado en navegador
- **Interfaz final como pidió el cliente:** SIN menú lateral. Tiles verde-azulados a pantalla
  completa (como el lanzador de KVS) + pestañas de trabajo + botón "Módulos" en la barra
  superior + maximizar (⛶/Esc) + atajos.
- Fases 0-8 completas en la base (auditado tabla por tabla).
- EDocuments (firma/documentos/puntos) · preview RIDE · ficha de producto KVS ·
  reportes de inventario · procesar por fila en lote · datos demo del video.
- El filtro por **plan** actúa sobre los tiles: plan básico no ve Contabilidad/Nómina.
  El lanzador es tu vitrina de upsell.

---

## 📊 Los 16 módulos del lanzador de KVS vs tu sistema

| Módulo KVS | Tu sistema | Veredicto |
|---|---|---|
| Administración | ✅ (empresas, usuarios, auditoría, planes) | Hecho |
| Inventario | ✅ + **Fase 14 lista para pegar** | Cerrarlo YA |
| **Impuestos** | ❌ | **Fase 15** — lo necesita la contadora |
| EDocuments | ✅ + **Fase 9 lista para pegar** (correo) | Cerrarlo YA |
| Recursos Humanos | ✅ (Nómina: empleados, rol, liquidación) | Hecho |
| Contabilidad | ✅ (diario, mayor, estados, mayorizar) | Hecho |
| Caja/Bancos | ✅ (caja, bancos, conciliación auto-match) | Hecho |
| Compras | ✅ (XML, TXT lote, cartera, pago múltiple) | Hecho |
| Ventas | ✅ (POS, facturas, cotizaciones, cartera) — faltan NC SRI y retenciones emitidas | **Fase 16** |
| **Activos Fijos** | ❌ | **Fase 17** — para "obligado a llevar contabilidad" |
| Presupuestos | ❌ | NO hacer (nadie lo pidió) |
| Producción | ❌ | NO hacer (tus combos ya cubren el armado) |
| Estab. Centro Costo | ❌ | NO hacer por ahora (empresa grande) |
| Herramientas | ❌ | NO hacer (utilidades internas de ellos) |
| Ventas Online | ❌ | Futuro — tu diferenciador natural con Vue |
| Académico | ❌ | NO hacer (otro mercado) |

> **No copies los 16.** KVS los tiene porque lleva años y vende a colegios y fábricas.
> Tu cliente compra computadoras: con 11 módulos bien hechos le sobra. Los "NO hacer"
> son features que costarían meses y no te pagan.

---

## 📋 Orden de trabajo

### AHORA (código ya escrito, solo pegar)
| # | Doc | Qué cierra |
|---|-----|-----------|
| 1 | **[FASE_9_CORREO_FACTURAS.md](FASE_9_CORREO_FACTURAS.md)** | Lo del cliente (audios): factura al correo, SMTP configurable, PDF+XML adjuntos. `composer require barryvdh/laravel-dompdf` primero |
| 2 | **[FASE_14_INVENTARIO_KVS.md](FASE_14_INVENTARIO_KVS.md)** | Inventario 100% como el video: listas de precios, combos, códigos alternos, reportes reponer/series |

### DESPUÉS (pedime el doc y te lo escribo, de a uno)
| # | Fase | Qué | Por qué |
|---|------|-----|---------|
| 3 | **13 — Deploy a servidor** 🔥 | VPS + dominio + SSL + build de producción | **Sin esto no vendés**: solo corre en tu Mac. KVS vende "desde cualquier parte" |
| 4 | **16 — Ventas: NC SRI + retenciones emitidas** | Nota de crédito electrónica real (codDoc 04, devuelve stock/series) + retención al proveedor (codDoc 07) | La librería ya los soporta; completa el módulo Ventas |
| 5 | **15 — Impuestos** | Reporte base 104 (ventas/compras/IVA por período) + anexo simplificado | El tile "Impuestos" de KVS; lo que la contadora usa cada mes |
| 6 | **17 — Activos Fijos** | Registro de activos + depreciación mensual automática (línea recta) con asiento | Para clientes obligados a llevar contabilidad |
| 7 | **18 — Ventas Online** | Catálogo público con carrito que factura contra tu sistema | Diferenciador: KVS lo tiene como módulo aparte, vos lo harías nativo |

### 📸 Sigue pendiente de tu lado
- Capturas de los módulos de KVS (lista en la versión anterior de este doc / abajo):
  facturación, caja, conciliación, contabilidad, retenciones, cartera, nómina, **impuestos**
  (esa es la que más necesito para la Fase 15), EDocuments completo, POS.
- El `.p12` real cuando lo tengas → EDocuments → Configuración de firma.

---

## Datos que le pedís a cada cliente que te compre
**RUC · razón social · dirección matriz · .p12 + su clave · correo de envío (y su clave SMTP
o contraseña de aplicación) · clave del portal SRI** (para el TXT de compras).
Todo se carga en EDocuments una sola vez. Esa es "la magia" — igual que KVS.

## Semáforo de siempre
Si los estados financieros dejan de cuadrar, el asiento quedó mal. No sigas hasta arreglarlo.

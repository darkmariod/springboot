# 📚 Documentación — índice

## 🔥 EMPEZÁ ACÁ
### **[HOY-TARDE.md](HOY-TARDE.md)**
Todo listo, solo ejecutar: cargar el `.p12` → desplegar en Debian 13 → que la contadora
pruebe → cobrar. Incluye qué hacer después (el módulo más fuerte de KBS).

---

## 🎨 UX de formularios
**[FASE-UX-FORMULARIOS.md](FASE-UX-FORMULARIOS.md)** — todos los diálogos ya tienen la cabecera verde de KVS (aplicado). Refinamiento opcional adentro.

## 📖 Apoyo

| Archivo | Para qué |
|---|---|
| **[GUIA_PRUEBA_CONTADORA.md](GUIA_PRUEBA_CONTADORA.md)** | Probar sin saber contabilidad → `php artisan contable:chequeo` |
| **[MAPA-MODULOS-PLANES.md](MAPA-MODULOS-PLANES.md)** | Qué módulo está en qué plan (auditado) |
| **[ENTREGA-BASICO-PRO.md](ENTREGA-BASICO-PRO.md)** | Qué entregás + **las 16 preguntas** antes del Corporativo |
| **[FORMULARIOS-CREADOR.md](FORMULARIOS-CREADOR.md)** | Código: Categorías · Compra manual · Buscador *(opcional)* |
| **[cliente-requisitos.md](cliente-requisitos.md)** | Lo que pidió Javier por WhatsApp |
| **[REUNION_CREADOR_KVS.md](REUNION_CREADOR_KVS.md)** | Análisis del video + precios de KBS |
| **[transcripcion_creador_kvs.txt](transcripcion_creador_kvs.txt)** | Transcripción (28 min) |

> 🗂️ Superados: `FASES_*`, `FASE_1*`, `PASOS_PENDIENTES_BACKEND.md`, `TARDE-DESPLIEGUE.md`,
> `FASES-CREADOR.md`. Podés borrarlos.

---

## ✅ Estado (verificado 17-jul)

**Todo construido.** Interfaz KVS (tiles + pestañas) · formularios del creador (Artículos con
8 pestañas, EDocuments completo) · series/garantías · importar del SRI (XML + TXT lote) ·
cartera con 10 formas de pago · bodegas · contabilidad · nómina · usuarios · auditoría.

**Planes alineados con KBS:** Emprendedor $289 · PRO $389 · Business $559 · Corporativo $659.

**Falta solo:** el `.p12` real y el deploy.

---

## 🔑 Los 2 conceptos clave

**1. Emitir vs importar**
> El **.p12 firma lo que SALE**. Lo que **ENTRA viene del XML** del proveedor.
> El .p12 **nunca** se usa para importar.

**2. Nómina**
> **9.45%** se le descuenta al empleado. **11.15% + décimos + fondos + vacaciones** los paga
> la empresa y **NO van en el rol** — son provisiones.

---

## Levantar
```bash
cd contabilidad-backend && php artisan serve     # terminal 1
cd contabilidad-vue && npm run dev               # terminal 2
```

## Semáforo
`php artisan contable:chequeo` — verde, mostralo. Rojo, arreglalo.

# 📹 Reunión con el creador de KVS (14 jul 2026) — análisis

> Demo de venta: el creador te mostró KVS como si fueras cliente (tienda de computadoras).
> Transcripción completa: `docs/transcripcion_creador_kvs.txt`

## Lo que mostró (features clave para TU negocio)

1. **SERIES por producto (LA feature para venta de computadoras):**
   - Cada producto se marca "maneja series". Al comprar 5 iPhones, ingresás 5 series
     (digitando o con **lector de código de barras**).
   - Al vender, elegís/escaneás la serie específica que se va.
   - Sirve para **garantías**: sabés a qué proveedor le compraste ESA unidad y a qué
     cliente se la vendiste. Sin series, con 2 proveedores del mismo producto no sabés
     a cuál devolver, y el cliente te puede traer una unidad que no es tuya.

2. **Importar compras desde el SRI por TXT (en lote):**
   - Del portal del SRI (con tu clave) bajás un **TXT con TODAS las facturas del mes**.
   - Lo subís al sistema → se insertan como "pendientes de procesar".
   - El sistema **va al SRI y trae los XML automáticamente** (⚠️ el SRI solo deja
     descargar el XML durante ~1 mes).
   - Procesás cada pendiente: el sistema consulta el RUC en servicios públicos y **crea
     el proveedor solo**, elegís qué productos ingresar, y si manejan series las digitás.

3. **Kardex con historia completa:** ingresos/ventas con número de factura, series
   vendidas, y cómo "flotó" el precio de compra en el tiempo (700 → 600 → 600).

4. **Ficha de producto rica:** varias **listas de precios**, stock **mínimo/máximo**
   (el sistema avisa qué reponer), **códigos alternos**, **ubicación física**
   (fila/columna), **imágenes**, y **componentes/combos** (armás un computador con
   partes; al venderlo descarga todos los componentes del stock).

5. **Venta con pistola:** escaneás la serie o el código → se agrega a la factura →
   precio manual según negociación → forma de pago (ej. transferencia + banco + número).

6. **Ventanas adaptables:** pestañas que no se cierran, minimizar/maximizar.
   ✅ *Nuestro sistema YA lo tiene.*

7. **Firma electrónica configurada EN el sistema:** .p12 + clave + correo de envío +
   endpoints del SRI. Envío automático; si el SRI no autoriza, reintenta.
   ✅ *Nuestro sistema YA sube el .p12 por empresa.*

8. **Conciliación bancaria con auto-match:** subís el archivo del banco y el sistema
   **marca en azul las coincidencias automáticamente**; vos verificás el resto.

## 💰 Inteligencia de negocio (sus precios)
- Plan básico inventario: **$289/año** (inventario, ventas, compras, reportes básicos,
  formularios de impuestos, facturación electrónica). **Sin firma.**
- Planes suben con: contabilidad, nómina, conciliación bancaria.
- Plan PRO (morado): incluye **firma electrónica por 1 año** (la renuevan ellos),
  series/lotes y conciliación.
- Instalación: 2-3 horas. Web, en la nube, 24/7, servidores fuera de Ecuador.
- **La firma como servicio incluido es su gancho de venta.** Anotalo para tu pricing.

## Qué nos falta copiar (ver docs/FASES_SIGUIENTES.md)
| Feature | Estado nuestro |
|---|---|
| Series por producto + garantías | ❌ FASE 7 (prioridad 1 para tu negocio) |
| Import TXT en lote + traer XML del SRI | ❌ FASE 8 |
| Combos/componentes, listas de precios, min/max, ubicación | ❌ FASE 9 |
| Lector de código de barras en POS | ❌ FASE 9 |
| Conciliación auto-match | ❌ FASE 9 |
| Pestañas múltiples | ✅ Hecho |
| .p12 por empresa | ✅ Hecho |
| Kardex | ✅ Hecho (falta mostrar series) |

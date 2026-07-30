# 📥 Cómo traer tus comprobantes del SRI al sistema (guía para el cliente)

## Qué hace esto
Trae tus **facturas recibidas (compras)** y **retenciones** desde el SRI directo al sistema,
para no cargarlas una por una a mano.

## Lo único que necesitás
La **clave de tu portal del SRI** (tu RUC + la contraseña con la que entrás a
`srienlinea.sri.gob.ec`).
⚠️ NO es la firma electrónica (`.p12`). Son dos claves distintas:
- **Clave del portal SRI** → para BAJAR tus comprobantes (esta guía).
- **Firma `.p12`** → para EMITIR/firmar tus facturas (otra cosa).

## Paso a paso

1. Entrá al portal del SRI: **srienlinea.sri.gob.ec** con tu **RUC + clave**.
2. Andá a **"Comprobantes electrónicos recibidos"**.
3. Elegí el **mes** y descargá el **listado en `.txt`**.
4. En el sistema, andá a **Compras → "Importar del SRI (lote)"**.
5. Subí ese **`.txt`**.
6. El sistema lee el archivo y **trae cada factura/retención solo**. ✅

## Importante (esto es límite del SRI, no del sistema)
El SRI solo entrega los comprobantes del **mes actual y el mes anterior**. Los más viejos ya no
se bajan automáticamente — de esos, si los tenés, se sube el **XML** a mano.

## En resumen
- **Traer historial** → `.txt` del portal SRI, en **Compras → Importar del SRI**. (Con tu clave del portal.)
- **Autocompletar** al facturar (escribís el RUC y aparece el nombre) → ya funciona solo, gratis.
- **Firmar/emitir** → con tu `.p12` cargado en EDocuments.

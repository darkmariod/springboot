# 🗺️ MAPA DE MÓDULOS — qué está hecho y en qué plan va

> Auditado contra tu código el 17-jul. **No es de memoria: lo verifiqué archivo por archivo.**

---

# ⚡ LA RESPUESTA CORTA

**Está TODO construido, incluido el "corporativo".** Contabilidad, nómina, usuarios y
auditoría **ya existen y funcionan** — los verifiqué en el backend y en el frontend.

**No necesitás videos ni contexto nuevo para el corporativo.** Ya está hecho.

Lo único que te falta es lo que KBS llama Corporativo **de verdad**, y son 4 cosas que
**ningún cliente tuyo pidió todavía**:

| KBS Corporativo real | Tenés | ¿Lo necesitás? |
|---|---|---|
| Multi-sucursal | ❌ | Solo si el cliente tiene 2+ locales |
| Activos fijos + depreciación | ❌ | Solo si está obligado a llevar contabilidad |
| Portal del empleado | ❌ | No lo pidió nadie |
| Portal de pagos online | ❌ | No lo pidió nadie |

> **Traducción: podés entregar HOY.** Lo que falta no bloquea nada.

---

# 📋 MAPA COMPLETO (verificado)

| Feature | Módulos que trae | ¿Construido? |
|---------|------------------|--------------|
| `catalogo` | Contactos · Productos y servicios | ✅ |
| `ventas` | Punto de Venta · Facturas · Cotizaciones · Retenciones recibidas | ✅ |
| `compras` | Compras · Proveedores | ✅ |
| `inventario` | Inventario y kardex · Bodegas · Ajuste · Transferencia | ✅ |
| `facturacion_sri` | EDocuments · Documentos SRI · Puntos de emisión | ✅ |
| `import_lote` | Importar del SRI (lote) | ✅ |
| `reportes` | Reportes de inventario · Reportes | ✅ |
| `series` | **Garantías por serie** 🎯 | ✅ |
| `cartera` | Anticipos · Notas de crédito · CxC · CxP | ✅ |
| `bancos` | Caja · Bancos | ✅ |
| `conciliacion` | Conciliación bancaria | ✅ |
| `contabilidad` | Plan de cuentas · Libro diario · Libro mayor · Estados financieros · **Impuestos** | ✅ |
| `nomina` | Empleados · Rol de pagos | ✅ |
| `usuarios` | Usuarios y roles | ✅ |
| `auditoria` | Auditoría | ✅ |

**Backend verificado:** `AccountingController` · `PayrollController` · `UserController` ·
`AuditController` · `TaxController` — los 5 existen.

---

# 🔧 LO ÚNICO QUE HAY QUE HACER: alinear los planes con KBS

Hoy tenés **3 planes**; KBS vende **4**. Y KBS pone contabilidad/nómina/conciliación en
**Business**, no en Corporativo. Por eso te conviene partirlo.

## Cómo queda (reemplazá `config/planes.php` entero)

```php
<?php
// Alineado con los planes reales de KBS (kbs-erp.com, línea Inventarios).
return [
    // $289/año en KBS. Facturación + inventario básico. SIN series.
    'emprendedor' => [
        'nombre' => 'Emprendedor',
        'precio_anual' => 289,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri',
            'reportes', 'import_lote',
        ],
    ],
    // $389/año. Acá aparecen las SERIES y la firma gestionada.
    'pro' => [
        'nombre' => 'PRO',
        'precio_anual' => 389,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri',
            'reportes', 'import_lote',
            'series', 'cartera', 'firma_incluida',
        ],
    ],
    // $559/año. KBS mete acá contabilidad, nómina, impuestos y conciliación.
    'business' => [
        'nombre' => 'Business',
        'precio_anual' => 559,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri',
            'reportes', 'import_lote',
            'series', 'cartera', 'firma_incluida',
            'bancos', 'conciliacion', 'contabilidad', 'nomina', 'usuarios', 'auditoria',
        ],
    ],
    // $659/año. Todo lo de Business + lo que TODAVÍA NO tenés (multi-sucursal, activos fijos).
    'corporativo' => [
        'nombre' => 'Corporativo',
        'precio_anual' => 659,
        'features' => [
            'catalogo', 'ventas', 'compras', 'inventario', 'facturacion_sri',
            'reportes', 'import_lote',
            'series', 'cartera', 'firma_incluida',
            'bancos', 'conciliacion', 'contabilidad', 'nomina', 'usuarios', 'auditoria',
            'sucursales', 'activos_fijos',   // ← lo único que falta construir
        ],
    ],
];
```

## Y en el frontend — `src/views/Companies.vue`
```ts
const planes = [
  { label: 'Emprendedor', value: 'emprendedor' },
  { label: 'PRO', value: 'pro' },
  { label: 'Business', value: 'business' },
  { label: 'Corporativo', value: 'corporativo' },
]
```

## Migrar las empresas que ya están en `basico`
```bash
php artisan tinker
>>> App\Models\Company::where('plan','basico')->update(['plan' => 'emprendedor']);
>>> App\Models\Company::first()->update(['plan' => 'business']);   # para el demo: que vea todo
```

> ⚠️ En `EdocConfigController` y donde valides el plan, cambiá
> `'in:basico,pro,corporativo'` por `'in:emprendedor,pro,business,corporativo'`.

---

# 💰 PRECIOS — KBS vs vos

| Plan | KBS (por año, **para siempre**) | Vos (pago único) |
|---|---|---|
| Emprendedor | $289 + IVA | **$350** |
| **PRO** (series + firma) | $389 + IVA | **$500** |
| Business (contabilidad + nómina) | $559 + IVA | **$700** |
| Corporativo | $659 + IVA | *(no lo vendas todavía)* |

**Tu argumento, con sus números:**
> *"KBS Pro son $389 + IVA por año. Al segundo año ya van $778. Al tercero, $1.167. Y siguen
> pagando para siempre. Esto es pago único: $500 y es suyo."*

---

# 🎯 QUÉ ENTREGARLE A ESTE CLIENTE

Es una **tienda de computadoras** → necesita **series** para garantías → **plan PRO**.

Para el demo poné la empresa en `business` (que vea todo, incluida la contabilidad).
Si contrata Pro, la bajás a `pro` y los tiles de contabilidad/nómina desaparecen solos.

**Tu as bajo la manga:**
> *"Aunque contrate Pro y no vea la contabilidad, el sistema la está llevando igual. El día
> que quiera Business, abre el módulo y ya tiene toda su historia desde el día uno."*
> **KBS no puede hacer eso.**

---

# 📝 SI ALGÚN DÍA PEDÍS EL CORPORATIVO DE VERDAD

Solo entonces necesitás contexto nuevo. Lo que hay que averiguar:

**Multi-sucursal**
1. ¿Cuántos locales? ¿Cada uno con su bodega y su punto de emisión?
2. ¿El stock se ve consolidado o separado por sucursal?
3. ¿Se transfiere mercadería entre sucursales?

**Activos fijos**
1. ¿Qué activos? (vehículos, equipos, muebles)
2. ¿Método de depreciación: línea recta u otro?
3. ¿Necesita el asiento de depreciación mensual automático?

**Portales (empleado / pagos online)**
1. ¿El empleado entra a ver su rol de pago?
2. ¿Pagos online con qué pasarela? (Datafast, PayPhone, Kushki)

> **No construyas nada de esto sin que esté escrito y pagado.** Son meses.

---

# ✅ ENTONCES, ¿QUÉ FALTA PARA ENTREGAR HOY?

**De módulos: NADA.** Todo lo que vendés (Emprendedor/Pro/Business) está construido.

Lo que falta es **poner en marcha**, no programar:

1. **Ajustar los 4 planes** (5 min — el código está arriba)
2. **Cargar el `.p12`** → factura llega a `AUTORIZADO`
3. **Configurar el SMTP** → la factura llega al correo del cliente
4. **Desplegar** en Debian 13 → [TARDE-DESPLIEGUE.md](TARDE-DESPLIEGUE.md)
5. **Usuario para la contadora** + `php artisan contable:chequeo` en verde
6. **Cobrar**

---

# 💵 Sobre el saldo — necesito que me confirmes

En tus mensajes leí **dos versiones distintas**:

- Antes: *"me dio 90 usd… saldo de 350 me falta cobrar"* → total **$440**, falta **$350**
- Ahora: *"350 - 90 pagados"* → total **$350**, falta **$260**

**¿Cuál es?** No es un detalle: son $90 de diferencia y no quiero que te confundas al cobrar.
Anotá acá el número correcto:

| | |
|---|---|
| Precio acordado total | **$_____** |
| Pagado (adelanto) | **$90** |
| Gastado (librería SRI) | **-$30** |
| **Falta cobrar** | **$_____** |

**Sea cual sea: cobrás cuando la contadora valide en el servidor.** Ahí ya no es promesa —
es su sistema, en su dominio, con su firma, facturando de verdad.

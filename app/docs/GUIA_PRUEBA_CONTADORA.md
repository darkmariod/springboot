# 🧪 Cómo probar el sistema (para vos, antes de que lo pruebe la contadora)

> Vos me dijiste: *"no sé cómo probar ni ver datos contables para seguir corrigiendo"*.
> Esto lo resuelve. No necesitás saber contabilidad: el sistema se autochequea.

---

## 1. Tu herramienta principal: el chequeo automático

```bash
cd /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend
php artisan contable:chequeo
```

Te dice en 2 segundos si la contabilidad está sana. Revisa **5 cosas**:

| Revisa | Por qué importa |
|---|---|
| Cada asiento cuadra (debe = haber) | Si no, la contabilidad está rota |
| Las líneas suman lo que dice la cabecera | Detecta asientos mal armados |
| **Ecuación contable**: Activo = Pasivo + Patrimonio + Utilidad | Es LA prueba de fuego de un sistema contable |
| No hay stock negativo | Vender sin comprar mata la credibilidad en un demo |
| Resumen: asientos, facturas, por cobrar, por pagar | Panorama rápido |

**Si dice `TODO OK — la contabilidad está sana`, podés mostrarlo tranquilo.**
Si dice `PROBLEMA(S)`, te lista exactamente cuál asiento está mal.

> Corrélo **siempre antes de un demo** y **después de cada fase que pegues**.

---

## 2. Resetear a datos de demo limpios

Si rompiste algo probando, volvé al escenario del video (iPhones con series, compra,
ventas, banco, empleado):

```bash
php artisan db:seed --class=DemoSeeder
php artisan contable:chequeo    # confirmá que quedó sano
```

---

## 3. El flujo que va a probar la contadora (y qué debe pasar)

| Paso | Dónde | Qué tiene que pasar |
|---|---|---|
| 1. Ver stock | Inventario → Inventario y kardex | Sin negativos, con costo promedio |
| 2. Importar compra | Compras → subir XML del SRI | Crea el proveedor solo, sube stock, **genera asiento** |
| 3. Vender | Ventas → Punto de Venta | Baja stock, emite con clave de 49 dígitos, **genera asiento** |
| 4. Cobrar | Ventas → Cuentas por cobrar | Baja el saldo, **genera asiento del cobro** |
| 5. Ver el diario | Contabilidad → Libro diario | Todos los asientos, con su origen |
| 6. Mayorizar | Contabilidad → botón "Mayorizar" | Pasan de `pendiente` a `mayorizado` |
| 7. **Estados financieros** | Contabilidad → Estados financieros | **Tiene que decir "Cuadrado ✓"** |

**Si el paso 7 dice "Cuadrado ✓", el sistema está bien.** Ese es tu semáforo.

---

## 3b. Guion del demo (el mismo orden que mostró el creador)

Grabá el video en ESTE orden — es el recorrido que hizo el creador con inventario y facturación.
Requisito: el `.p12` ya cargado en EDocuments (si no, la factura queda en `generado`).

1. **Mostrar el stock inicial** — Inventario → Kardex. "Compré 5 iPhone 17 Pro Max y 10 cubos."
2. **La compra con series** — Compras. Mostrar la factura del proveedor y las 5 series ligadas
   (trazabilidad de garantía, lo que KVS hace y Contífico no).
3. **Vender por serie** — Punto de Venta. Vender 1 iPhone eligiendo su serie, pago transferencia.
   Señalar: baja stock, clave de acceso de 49 dígitos, **y pasa a AUTORIZADO**.
4. **El correo** — mostrar que la factura llega sola al correo del cliente (XML + PDF).
5. **El asiento automático** — Contabilidad → Libro diario. "Cada venta genera su asiento solo,
   con su referencia."
6. **Editar contabilidad** — Desmayorizar un asiento. Es LO que la contadora valora de KVS.
7. **El cierre** — Estados financieros → tiene que decir **"Cuadrado ✓"**.

> Semáforo del video: si la factura dice **AUTORIZADO** y los estados financieros dicen
> **Cuadrado ✓**, el sistema quedó demostrado igual que el de KBS.

---

## 4. Lo que la contadora va a buscar primero (preparate)

1. **"¿Puedo editar un asiento?"** → Contabilidad → botón **Desmayorizar** en el asiento.
   Es lo que más valora de KVS y lo que odia de Contífico.
2. **"¿De dónde salió este asiento?"** → cada asiento tiene su concepto y referencia
   (ej. "Venta factura 001-001-000000003").
3. **"¿Quién hizo esta factura?"** → Administración → Auditoría.
4. **"¿Y si el cliente me paga con cheque?"** → Cuentas por cobrar → Registrar cobro →
   forma de pago: cheque / transferencia / cruce.
5. **"¿Puedo cruzar un anticipo?"** → Ventas → Anticipos, después "Usar saldo" en la factura.

---

## 5. Verificar la API a mano (si algo no carga en pantalla)

```bash
# Token
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@demo.com","password":"password123"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")

# Cualquier endpoint
curl -s "http://127.0.0.1:8000/api/balance-sheet?company_id=1" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

Endpoints verificados y funcionando (20/20): `inventory/stock`, `invoices`, `purchases`,
`receivables`, `payables`, `journal`, `ledger`, `income-statement`, `balance-sheet`,
`series`, `withholdings`, `advances`, `credit-notes`, `sri-documents/pending`, `employees`,
`quotes`, `bank-movements`, `contacts`, `products`, `accounts`.

---

## 6. Levantar todo (si se cayó)

```bash
# Terminal 1 — backend
cd /Users/mariopazmino/Desktop/springboot/app/contabilidad-backend && php artisan serve

# Terminal 2 — frontend
cd /Users/mariopazmino/Desktop/springboot/app/contabilidad-vue && npm run dev
```

## 7. Estado de la librería comprada
✅ **Verificada**: `LibreriasSri\FacturacionElectronicaLibrary` carga y emite (clave de acceso
de 49 dígitos generada). Se queda en estado `generado` porque **falta cargar el .p12**.
Con el certificado cargado en EDocuments pasa sola a `firmado → enviado → AUTORIZADO`.

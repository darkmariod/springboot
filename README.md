# HasReset — Sistema Contable + Facturación Electrónica SRI (Ecuador)

Sistema de gestión para pymes que **factura electrónicamente ante el SRI**, controla
**inventario** y lleva la **contabilidad** de forma automática. Pensado para comercios que venden
productos con o sin series (garantías) y necesitan que cada venta genere su factura autorizada, baje
el stock y arme el asiento contable sin cargar nada a mano.

---

## ¿Qué hace la app?

- **Facturación electrónica SRI**: genera el XML, lo **firma** con el certificado `.p12`, lo **envía**
  y trae la **autorización** del SRI. Soporta factura, nota de crédito, nota de débito, retención,
  guía de remisión y liquidación de compra.
- **Autocompletar cliente desde el SRI**: al escribir la cédula (10 dígitos) o el RUC (13 dígitos)
  consulta el padrón y trae la razón social; si el SRI no responde, se cargan los datos a mano.
- **Inventario y kárdex**: cada compra, venta, ajuste y transferencia mueve el stock por un único
  servicio central, con **costo promedio ponderado**. El kárdex se reconstruye en orden cronológico,
  así una compra con fecha retroactiva revaloriza las ventas posteriores.
- **Series**: cada unidad con número de serie se rastrea desde el proveedor que la vendió hasta el
  cliente que la compró. Alta masiva, cambio de estado y bloqueo de borrado si ya se vendió.
- **Contabilidad automática**: cada factura y compra genera su asiento. Libro diario, libro mayor,
  estados financieros, Formulario 103 y ATS.
- **Anulación con rastro**: anular una factura crea un contra-asiento y devuelve el stock con un
  movimiento nuevo. Nada se borra. Una factura ya autorizada por el SRI no se anula: se reversa con
  nota de crédito.
- **Cotizaciones**, **punto de venta**, **compras e importación del SRI en lote**, **conciliación
  bancaria y de tarjetas**, **nómina**, **multisucursal** con establecimiento propio ante el SRI.
- **Multiempresa** con tres planes que activan módulos (ver más abajo).

> **Ambientes SRI:** el sistema opera en **PRUEBAS** (certificación, sin validez tributaria) o
> **PRODUCCIÓN** (facturas fiscales reales). Se cambia con un parámetro en la empresa.

---

## Planes

| Plan | Anual | Semestral | Incluye |
|------|------:|----------:|---------|
| **Básico** | $85 | $55 | Catálogo, ventas, compras, inventario, series, cartera, bancos, reportes, auditoría. **Sin facturación al SRI.** |
| **Negocio** | $145 | $92 | Todo lo anterior + facturación electrónica SRI, importación en lote, facturación masiva, conciliaciones, usuarios y roles, conversión y fraccionamiento de artículos, reservas de stock. |
| **Completo** | $225 | $142 | Todo lo anterior + contabilidad, nómina, multisucursal. |

Los planes se definen en `app/contabilidad-backend/config/planes.php`. Cada ruta protegida lleva
el middleware `feature:<nombre>`; un plan sin esa feature recibe `402`.

---

## Stack

| Capa | Tecnología |
|------|------------|
| Backend | **Laravel 12** (PHP 8.3), API REST con Sanctum |
| Frontend | **Vue 3** + **PrimeVue** + **Pinia** (SPA), build con Vite |
| Base de datos | SQLite en modo WAL (en volumen Docker) |
| Facturación SRI | Librería propia XAdES-BES (firma `.p12`), SOAP a los webservices del SRI |
| Infraestructura | **Docker** (backend PHP-FPM + frontend Nginx) |

### Arquitectura (resumen)

```
Navegador ──> Nginx (frontend, Vue SPA) ──/api──> Laravel (backend PHP-FPM) ──> SQLite
                                                        │
                                                        └─> Webservices SRI (firma + autorización)
```

Todo movimiento de existencias pasa por `App\Services\RegisterInventoryMovement`. Es el único
punto que escribe en el kárdex, y por eso el kárdex, el stock y el stock por bodega siempre cuadran
entre sí.

---

## Desplegar con Docker

Todo el despliegue vive en la carpeta `app/`.

### 1. Requisitos
- Docker + Docker Compose instalados en el servidor.
- El archivo `app/.env.docker` con la configuración del backend (NO se versiona).

### 2. Levantar el sistema
```bash
cd app
docker compose up -d --build
```
Esto construye y arranca dos contenedores:
- `contable-backend` — Laravel (PHP-FPM).
- `contable-frontend` — Nginx sirviendo la SPA, publicado en el **puerto 8080**.

### 3. Preparar la base de datos (primera vez)
```bash
docker exec contable-backend php artisan migrate --force
docker exec contable-backend php artisan db:seed --class=DatabaseSeeder --force
```

> **Ojo con las migraciones nuevas.** El volumen `db-data` se monta sobre `/var/www/database`
> y tapa la carpeta de migraciones de la imagen. Una migración nueva hay que copiarla a mano antes
> de correr `migrate`:
> ```bash
> docker cp contabilidad-backend/database/migrations/<archivo>.php contable-backend:/var/www/database/migrations/
> docker exec contable-backend php artisan migrate --force
> ```

### 4. Entrar
Abrir en el navegador: **http://SERVIDOR:8080/app/**
(en local: http://localhost:8080/app/)

### 5. Configurar la empresa y la firma
1. **Administración → Empresas**: RUC, razón social y dirección. Sin estos datos el sistema no
   deja facturar (no se puede armar la clave de acceso).
2. **EDocuments → Configuración de firma**: subir el certificado `.p12` y su clave. El sistema
   valida que el archivo abra con esa clave antes de guardarlo.

Con eso, la facturación firma y envía al SRI automáticamente. Sin certificado, las facturas se
generan y quedan en estado `generado` sin enviarse — útil para demostraciones.

---

## Comandos de mantenimiento

```bash
# Revisar que el kárdex cuadre con el stock y las bodegas (6 chequeos por producto)
docker exec contable-backend php artisan inventario:auditar
docker exec contable-backend php artisan inventario:auditar --corregir   # reconstruye lo descuadrado

# Revisar que la contabilidad esté sana (asientos cuadrados, ecuación contable, sin stock negativo)
docker exec contable-backend php artisan contable:chequeo

# Dejar el sistema sin datos para entregarlo a un cliente nuevo
docker exec contable-backend php artisan sistema:limpiar --usuario=CORREO --empresa=ID            # simulación
docker exec contable-backend php artisan sistema:limpiar --usuario=CORREO --empresa=ID --confirmar
docker exec contable-backend php artisan sistema:limpiar --usuario=CORREO --empresa=ID --en-blanco --confirmar  # vacía también la empresa

# Logs y reconstrucción
docker compose logs -f backend
docker compose up -d --build frontend     # solo el frontend tras cambios en Vue
```

`sistema:limpiar` conserva el usuario, la empresa, el plan de cuentas, las bodegas y el punto de
emisión; borra todo el movimiento y reinicia la numeración en 1. Conviene sacar copia de
`database.sqlite` antes.

---

## Estructura del repositorio

```
springboot/
├── app/
│   ├── contabilidad-backend/   # Laravel 12 (API, modelos, servicios, comandos)
│   ├── contabilidad-vue/       # Vue 3 + PrimeVue (SPA)
│   ├── docker-compose.yml      # Orquestación backend + frontend
│   ├── Dockerfile              # Imagen del backend (incluye OpenSSL legacy para el .p12)
│   └── Dockerfile.frontend     # Imagen del frontend (Nginx + build Vite)
├── docs/                       # Guías de demo y de estudio
├── certificados/               # Certificados .p12 (NO se versiona)
└── README.md
```

---

## Seguridad

Nunca se versionan: certificados `.p12`, archivos `.txt` de recibidos del SRI, `.env`, la base
`database.sqlite` ni las facturas firmadas generadas. Ver `.gitignore`.

El sistema debe publicarse **solo por HTTPS**: el certificado `.p12` y su clave viajan en la carga
del formulario de firma.

---

## Documentación

- `docs/guion-demo.md` — guion de demostración de 25 minutos, con las frases para cada bloque y
  las respuestas a las preguntas frecuentes.
- `docs/conceptos-contables.md` — cómo funciona el sistema por dentro: flujo de una venta, kárdex,
  costo promedio ponderado, libro diario y mayor, series, anulación. Con los números reales del sistema.

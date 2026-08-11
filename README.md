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
- **Autocompletar cliente desde el SRI**: escribís la cédula (10 díg.) o el RUC (13 díg.) y trae la
  razón social automáticamente; si no existe, lo crea sin salir de la venta.
- **Inventario y kardex**: cada compra/venta/ajuste/transferencia mueve el stock por un servicio
  central, con costo promedio ponderado y control de **series** (a quién se compró y se vendió cada
  unidad).
- **Contabilidad automática**: cada factura y compra genera su asiento; libro diario, mayor y balances.
- **Cotizaciones**: se crean y, si el cliente aprueba, se convierten en factura con un clic.
- **Compras e importación del SRI (lote)**: sube el `.txt` del portal del SRI y trae los comprobantes.
- **Multiempresa** con planes (Emprendedor / PRO / Business / Corporativo) que activan módulos.
- **Punto de Venta (POS)** con búsqueda de productos, formas de pago y emisión directa.

> **Ambientes SRI:** el sistema opera en **PRUEBAS** (certificación, sin validez tributaria) o
> **PRODUCCIÓN** (facturas fiscales reales). Se cambia con un parámetro en la empresa.

---

## Stack

| Capa | Tecnología |
|------|------------|
| Backend | **Laravel 12** (PHP 8.3), API REST con Sanctum |
| Frontend | **Vue 3** + **PrimeVue** + **Pinia** (SPA), build con Vite |
| Base de datos | SQLite (en volumen Docker) |
| Facturación SRI | Librería propia XAdES-BES (firma `.p12`), SOAP a los webservices del SRI |
| Infraestructura | **Docker** (backend PHP-FPM + frontend Nginx), Cloudflare Tunnel opcional |

### Arquitectura (resumen)

```
Navegador ──> Nginx (frontend, Vue SPA) ──/api──> Laravel (backend PHP-FPM) ──> SQLite
                                                        │
                                                        └─> Webservices SRI (firma + autorización)
```

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

### 4. Entrar
Abrir en el navegador: **http://SERVIDOR:8080**
(en local: http://localhost:8080)

### 5. Cargar el certificado de firma
Dentro del sistema: **Administración → Empresas → Cargar .p12**, subir el certificado y su clave.
Con eso, la facturación firma y envía al SRI automáticamente.

### Comandos útiles
```bash
# Ver logs
docker compose logs -f backend
# Reconstruir solo el frontend tras cambios de Vue
docker compose up -d --build frontend
# Verificar salud contable (asientos cuadrados, sin stock negativo)
docker exec contable-backend php artisan contable:chequeo
# Dejar el sistema vacío para alta con el cliente
docker exec contable-backend php artisan db:seed --class=DemoCleanSeeder --force
```

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
├── docs/                       # Guías y prompts de trabajo
├── certificados/               # Certificados .p12 y recibidos (NO se versiona)
└── README.md
```

---

## Seguridad

Nunca se versionan: certificados `.p12`, archivos `.txt` de recibidos del SRI, `.env`, la base
`database.sqlite` ni las facturas firmadas generadas. Ver `.gitignore`.

---

## Documentación

- `docs/GUIA-DEMO-CLIENTE.md` — recorrido paso a paso para presentar el sistema al cliente.
- `docs/PROMPT-TRABAJO-COMPLETO.md` — plan de trabajo técnico (fixes + inventario + deploy + respaldo).

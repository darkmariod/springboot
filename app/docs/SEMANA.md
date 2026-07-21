# 📅 PLAN DE LA SEMANA — Deploy + entrega contadora (clon KBS)

> **Contexto.** Sistema contable/inventario (clon de KBS, con lo mejor de UX de Contífico) para
> **Javier Solis**, tienda de computadoras en Ecuador. El "cerebro" ya está construido y
> verificado (facturación SRI, series, importar SRI, contabilidad en verde). Esta semana es
> **v1 → producción**: dejar la Debian con el sistema montado, validar SRI con la contadora
> **Cristina**, y arrancar el ciclo de mejoras por módulos. Meta: **sistema arriba y probándose,
> sin dominio, accesible por Tailscale.**
>
> Fuente: `docs/*` + transcripción de `reunion-cliente-clon-kbs.mov` (`docs/transcripcion_reunion_cliente.txt`).

---

## ✅ DECISIONES YA RESUELTAS (las dijiste en la reunión)

| Decisión | Resuelto | Cita del video |
|---|---|---|
| **Acceso de la contadora** | **Tailscale, sin dominio** | *"como para un cliente, sin dominio y sin nada, solo con 100.8 que se conecta"* |
| **Base de datos del server** | **MySQL / MariaDB** | *"ahorita estoy trabajando con MySQL... subiéndole mañana en Debian"* |
| **Deploy v1** | **Directo/manual ahora** | Subís cambios y te conectás; local en tu Mac → push al server |
| **Docker/Dokploy** | **Después de cerrar v1** | *"cuando ya estén aprobadas todas las migraciones, le anexo todo en una sola nueva imagen de Docker"* |

> **Único número sin confirmar:** el saldo a cobrar. Las docs dicen **$350** y también **$260**
> (pagado $90, librería SRI -$30). No aparece en el video. **Confirmá vos.**

---

## 🎥 LO QUE PIDIÓ EL CLIENTE EN EL VIDEO (trabajo de la semana)

### A. Facturación con Cédula **y** RUC a la vez 🎯 (lo que más recalcó)
- Un mismo cliente puede pedir **una parte con RUC y otra con cédula** (ej: le factura el
  televisor con RUC y las zapatillas con cédula).
- Al dictar el RUC/cédula, el sistema **jala los datos del SRI automáticamente** (nombres, correo).
  La cajera **solo verifica** (sobre todo el correo, porque muchos tienen uno viejo que ya no usan).
- **Formulario de cliente más grande**: **cédula a la izquierda, RUC a la derecha**, para que
  **guarde de las dos maneras** (como el que armaron con Chris en el video).

### B. Ingreso de productos — campos que faltan
Debe permitir registrar: **cantidad · números de serie · nombre del producto · código de
producto · proveedor** al que se le compra.

### C. Formularios: más parámetros (según los videos de los 12 módulos)
- Solo con **asterisco** los obligatorios (ya está así).
- **Segundo correo** (de emergencia): los clientes tienen un solo correo, se llena, y las
  facturas rebotan. Poner un correo de respaldo.
- Agregar el resto de ítems que aparezcan al comparar con los módulos del KBS.

### D. Agregar cliente **desde la factura**
Si dicta una cédula y **no está registrada**, poder **abrir el registro de cliente y crearlo ahí
mismo** sin salir de la factura.

### E. SMTP / envío de facturas
Activar el envío por correo (Gmail o corporativo) para mandar las facturas **al instante o al
cierre del día**. Ya está en el módulo **EDocuments**: sacar el SMTP y configurarlo.

### F. Biométrico + lector de código de barras → **AL FINAL, no esta semana**
- Lectores de huella comprados en Taiwán: marcas tipo **Anviz / Hikvision / ZKTeco** (varios formatos).
- Plan: primero un **script de Python** que lea el biométrico y validarlo aparte; después integrar.
- El cliente fue claro: *"eso hacerle casi al último"*. **Lo primordial es el cerebro (facturar,
  traer datos del SRI, cargar el .p12).**

### 📹 Pendiente que depende del cliente
- Javier va a **grabar los ~12 módulos del KBS corporativo** (consiguió acceso) y te pasa el video
  (posiblemente **mañana**, si va a Río Hospital). Con eso completás los ítems que falten por módulo.
- **Cristina (contadora)** da los requisitos adicionales y **hace las pruebas/validación del SRI**.
  Coordinar con ella qué día esta semana.

---

## 🗓️ FLUJO DE TRABAJO DE LA SEMANA (orden del cliente)

**El cliente definió 3 hitos + ciclo de mejoras:**

### Hito 1 (MAÑANA) — Servidor montado
- Recibís la máquina/Debian **ya con SO listo** e **insertás el sistema**.
- Debian en Tailscale (aparece en `admin/machines`, IP `100.x.x.x`).
- Preparar server (`TARDE-DESPLIEGUE.md §3.2`): nginx, **mariadb-server**, git, curl,
  `php-fpm php-mysql php-xml php-curl php-mbstring php-zip php-gd php-bcmath` **`php-soap`**.
- **`php -m | grep -i soap`** → DEBE aparecer (sin esto el SRI no envía ni autoriza).
- Node 22 para compilar el Vue. Crear DB MySQL `contable` + usuario + clave fuerte.
- Deploy backend: `composer install --no-dev`, `.env` prod (**`APP_DEBUG=false`**),
  `key:generate`, `migrate --force`, `db:seed --force`, `config/route:cache`, permisos `www-data`.
- Deploy frontend: `VITE_API_URL` → API, `npm ci && npm run build`.
- nginx (API en subdominio o `/api`, `client_max_body_size 20M`), CORS, ufw, backup cron.

### Hito 2 — Cambios por video (cuando Javier lo mande)
- Completar formularios A–E de arriba (cédula+RUC, productos, 2º correo, alta desde factura, SMTP).
- Trabajás **local en la Mac** y **subís los cambios al server** (te conectás por Tailscale y pusheás).

### Hito 3 — Validación SRI con Cristina
- Cargar el **`.p12`** en EDocuments → *"Firma cargada · vence …"*. Usuario/Clave SRI, ambiente
  **PRUEBAS** primero. SMTP Gmail (465/SSL, **contraseña de aplicación**) → "Probar correo".
- POS → factura real → `firmado → enviado → **AUTORIZADO**`. `contable:chequeo` → **TODO OK**.
- Que **Cristina** pruebe y liste errores → corregís → nueva versión.

### Ciclo posterior (según contrato: revisiones de ~10–15 días)
- Cristina manda videos/observaciones → nueva versión → subís → validan. Repetir.
- Básico + Pro **ya cubiertos**. **Corporativo** (biométrico, código de barras, multi-sucursal)
  es lo que lleva más tiempo. Estimado del cliente: **culminar a fin del próximo mes**.

---

## 🔒 BLOQUEANTES / SEGURIDAD (antes de subir el repo)
- [ ] **Borrar** `sistema-captura-creador/Screenshot 2026-07-16 at 1.43.17 PM.png` — credenciales
      reales del creador de KVS (lo avisan `TARDE-DESPLIEGUE.md` y `cliente-requisitos.md`).
- [ ] `.env` confirmado en `.gitignore`, nunca subido al repo (tiene secretos).
- [ ] **Respaldar `APP_KEY`** aparte: encripta el `.p12` y las claves SMTP. Si se regenera, se
      pierden todas las firmas cargadas.
- [ ] Commitear/pushear los cambios pendientes del backend (hay archivos `M` sin subir).

---

## 📌 ESTRATEGIA DE MIGRACIONES (como lo explicaste al cliente)
- Mientras desarrollás: una migración por cambio; si algo sale mal, `rollback`.
- Cuando **v1 esté aprobada**: **consolidar en una sola migración limpia** + una **imagen de
  Docker** (ahí entra Dokploy). Base de datos configurable (MySQL/Postgres/SQLite según el cliente).
- En producción ya no se altera a la ligera: por eso el esqueleto tiene que quedar sólido antes.

---

## ✅ VERIFICACIÓN (cómo saber que quedó bien)
- `php -m | grep soap` en el server → aparece.
- Factura de prueba en **AUTORIZADO** + correo recibido.
- `php artisan contable:chequeo` → **TODO OK**. Estados financieros → **"Cuadrado ✓"**.
- Cristina entra por Tailscale, factura con cédula y RUC, y completa el flujo sin errores.

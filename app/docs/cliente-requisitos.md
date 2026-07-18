# 📱 CLIENTE-REQUISITOS — Javier Solis (WhatsApp 17-jul)

> Lo que pidió el cliente + el plan para dejar el sistema entregable.
> **Orden de mañana:** 1) UX del cliente → 2) formularios del creador → 3) deploy → 4) demo.

---

# 1. LO QUE PIDIÓ EL CLIENTE (WhatsApp)

| # | Pidió | Traducción |
|---|-------|------------|
| 1 | *"Que diga ahí. Dentro"* (marcó el tile de Inventario) | El **nombre del módulo va DENTRO del tile**, no debajo |
| 2 | *"abajo de fondo crees poder colocar una imagen media parecida a esta"* | **Imagen de fondo** en el lanzador: tech/isométrica, azul oscuro, líneas de neón |

## 1.1 Nombre DENTRO del tile — `src/views/Home.vue`

Reemplazá el `<template>` y el `<style>` por esto:

```vue
<template>
  <div class="launcher">
    <!-- Nivel 2: items del grupo elegido -->
    <template v-if="grupoActivo">
      <button class="volver" @click="grupoActivo = null">
        <i class="pi pi-arrow-left" /> Módulos
      </button>
      <h2 class="grupo-titulo">{{ grupoActivo.label }}</h2>
      <div class="tiles">
        <div v-for="item in grupoActivo.items" :key="item.key" class="tile" @click="abrir(item)">
          <i :class="item.icon" />
          <span class="tile-label">{{ item.label }}</span>
        </div>
      </div>
    </template>

    <!-- Nivel 1: los grupos -->
    <template v-else>
      <div class="tiles">
        <div v-for="g in grupos" :key="g.label" class="tile"
             @click="g.items.length === 1 ? abrir(g.items[0]) : (grupoActivo = g)">
          <i :class="iconoGrupo[g.label] ?? 'pi pi-th-large'" />
          <span class="tile-label">{{ g.label }}</span>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Fondo tech pedido por el cliente. Si no hay imagen, queda el degradado solo. */
.launcher {
  padding: 36px;
  height: 100%;
  overflow: auto;
  background:
    linear-gradient(rgba(10, 25, 45, 0.82), rgba(10, 25, 45, 0.92)),
    url('/fondo-tech.jpg') center / cover no-repeat fixed,
    linear-gradient(135deg, #0a192d 0%, #123a5c 100%);
}
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, 132px);
  gap: 24px;
  justify-content: start;
}
/* El nombre va DENTRO del cuadrado (lo que pidió el cliente) */
.tile {
  width: 132px;
  height: 118px;
  border-radius: 14px;
  background: linear-gradient(160deg, #2f7676, #245f5f);
  border: 1px solid rgba(255, 255, 255, 0.12);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 8px;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
  transition: transform 0.12s ease, box-shadow 0.12s ease;
}
.tile i { font-size: 2.1rem; color: #fff; }
.tile-label {
  font-size: 12px;
  font-weight: 600;
  color: #fff;
  text-align: center;
  line-height: 1.2;
}
.tile:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.45);
  border-color: rgba(255, 255, 255, 0.35);
}
.grupo-titulo { margin: 14px 0 24px; font-size: 18px; color: #fff; }
.volver {
  border: 0; background: transparent; color: #7fd1d1; font-weight: 600; font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 4px 0;
}
</style>
```

> **Ojo:** el `<script setup>` de Home.vue **no cambia**. Solo template y estilos.

## 1.2 La imagen de fondo

1. Conseguí una imagen tipo la que mandó (isométrica, tech, azul oscuro). Buscá en
   **Unsplash** o **Freepik** con: `isometric technology network dark blue`.
   ⚠️ Fijate que la licencia permita uso comercial — se la vas a vender a un cliente.
2. Guardala como **`contabilidad-vue/public/fondo-tech.jpg`** (así el `url('/fondo-tech.jpg')`
   la encuentra sola).
3. Achicala a **~1920px de ancho y menos de 400 KB** (si pesa 5 MB, el sistema arranca lento
   y el cliente lo va a notar).

> Si todavía no tenés la imagen, **igual funciona**: el degradado azul oscuro queda de fondo.

## 1.3 Probar
```bash
cd contabilidad-vue && npm run dev
```
Entrá → los tiles deben tener **el nombre adentro, en blanco**, sobre el fondo oscuro.

---

# 2. FORMULARIOS DEL CREADOR (para que el demo sea igual a KVS)

Código completo en **[FORMULARIOS-CREADOR.md](FORMULARIOS-CREADOR.md)**:
- **Categorías de Artículos** (en KVS la categoría es obligatoria en el artículo)
- **Registro/Modificación de Compras** (compra manual con las 10 formas de pago)
- **Buscador de artículos** (el "Listado de Existencias")

Y en **[FASES-CREADOR.md](FASES-CREADOR.md)** está el resto ordenado.

**Antes del demo, sí o sí:**
```bash
php artisan contable:chequeo   # tiene que decir TODO OK
```

---

# 3. DEPLOY — Debian 13 (Trixie) + SSH desde tu Mac

## 3.1 Conectarte por SSH (desde tu Mac)

```bash
# 1. Generar tu llave (una sola vez en tu vida)
ssh-keygen -t ed25519 -C "mario@monkeycomputer"
# Enter en todo. Queda en ~/.ssh/id_ed25519

# 2. Copiar la llave al servidor (te pide la clave root una vez)
ssh-copy-id root@IP_DEL_SERVIDOR

# 3. Entrar (ya sin clave)
ssh root@IP_DEL_SERVIDOR
```

**Atajo:** creá `~/.ssh/config` en tu Mac:
```
Host contable
    HostName IP_DEL_SERVIDOR
    User root
    IdentityFile ~/.ssh/id_ed25519
```
Y entrás con solo: `ssh contable`

## 3.2 Preparar el servidor (una vez)

```bash
apt update && apt upgrade -y
apt install -y nginx mariadb-server git unzip curl \
  php-fpm php-mysql php-xml php-curl php-mbstring php-zip php-gd php-bcmath php-soap

# ⚠️ php-soap es OBLIGATORIO: la librería del SRI lo usa para enviar y autorizar
php -m | grep -i soap    # tiene que aparecer "soap"

# Node (para compilar el Vue)
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs

# Base de datos
mysql -e "CREATE DATABASE contable CHARACTER SET utf8mb4;"
mysql -e "CREATE USER 'contable'@'localhost' IDENTIFIED BY 'PONE_UNA_CLAVE_FUERTE';"
mysql -e "GRANT ALL ON contable.* TO 'contable'@'localhost'; FLUSH PRIVILEGES;"
```

## 3.3 Subir el sistema

```bash
cd /var/www
git clone TU_REPO_GITHUB contable
cd contable/contabilidad-backend

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
nano .env
```

**En el `.env` de producción:**
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tudominio.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=contable
DB_USERNAME=contable
DB_PASSWORD=LA_CLAVE_FUERTE
```

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache && php artisan route:cache

# Permisos (si no, Laravel no puede escribir logs)
chown -R www-data:www-data /var/www/contable
chmod -R 775 storage bootstrap/cache

# Compilar el frontend
cd ../contabilidad-vue
npm ci && npm run build      # genera dist/
```

## 3.4 nginx

```bash
nano /etc/nginx/sites-available/contable
```
```nginx
server {
    listen 80;
    server_name tudominio.com;

    # El Vue compilado
    root /var/www/contable/contabilidad-vue/dist;
    index index.html;

    # Vue Router: todo lo que no sea archivo va al index
    location / {
        try_files $uri $uri/ /index.html;
    }

    # La API va a Laravel
    location /api {
        alias /var/www/contable/contabilidad-backend/public;
        try_files $uri $uri/ @laravel;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/contable/contabilidad-backend/public/index.php;
        }
    }
    location @laravel {
        rewrite /api/(.*)$ /api/index.php?/$1 last;
    }

    client_max_body_size 20M;   # para subir el .p12 y los XML
}
```
```bash
ln -s /etc/nginx/sites-available/contable /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

> Si el `/api` te da problemas con el `alias`, la alternativa simple y segura es
> **servir Laravel en un subdominio**: `api.tudominio.com` con su propio `server {}` y
> `root /var/www/contable/contabilidad-backend/public;`. Después en el Vue apuntás
> `VITE_API_URL=https://api.tudominio.com/api` y recompilás.

## 3.5 SSL (gratis)

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d tudominio.com
# Renovación automática ya queda configurada
```

## 3.6 Firewall

```bash
apt install -y ufw
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw enable
```

## 3.7 Backup diario (el cliente confía su contabilidad ahí)

```bash
crontab -e
```
```
0 3 * * * mysqldump -u contable -pLA_CLAVE contable | gzip > /root/backup-contable-$(date +\%F).sql.gz
0 4 * * * find /root -name "backup-contable-*.sql.gz" -mtime +14 -delete
```

---

## ⚠️ 3 cosas que NO podés olvidar en el deploy

1. **`APP_KEY`**: el `.p12` y todas las claves se guardan **encriptadas con esa key**.
   Si la perdés o la regenerás, **perdés todas las firmas y claves SMTP cargadas**.
   Guardala en un lugar seguro aparte.
2. **`APP_DEBUG=false`**: si queda en `true`, cualquiera ve tus rutas, tu `.env` y tus errores.
3. **`php-soap`**: sin esa extensión la librería del SRI **no puede enviar ni autorizar**.
   Verificá con `php -m | grep soap` antes de decir que está listo.

---

# 4. CHECKLIST DE ENTREGA

- [ ] UX del cliente: nombre dentro del tile + fondo tech
- [ ] Formularios del creador (`FORMULARIOS-CREADOR.md`)
- [ ] `.p12` real cargado en EDocuments → factura pasa a **AUTORIZADO**
- [ ] SMTP configurado → "Probar correo" llega
- [ ] `php artisan contable:chequeo` → **TODO OK**
- [ ] Deploy en Debian 13 con dominio + SSL
- [ ] Backup diario andando
- [ ] Usuario para la contadora (rol `contador`)
- [ ] `php artisan db:seed --class=DemoSeeder` para que pruebe con datos limpios

## 🎬 Guion del demo (10 min)
1. Inventario → Reportes → "Existencias Bodega" → Imprimir/PDF
2. **Garantías por serie** → `350269500001` → *"a quién le compré y a quién le vendí ESTA unidad"* 🎯
3. POS → escaneá `350269500002` → vendé en vivo
4. Cuentas por cobrar → **cobro repartido** ($30 efectivo + resto transferencia) ← lo que Contífico no tiene
5. Libro diario → asiento automático → **Desmayorizar** ← lo que la contadora ama de KVS
6. Estados financieros → **"Cuadrado ✓"**
7. Cerrá: *"y la factura le llega sola al correo del cliente con su XML y PDF"*

---

# 💰 Cobranza

| | |
|---|---|
| Cobrado | **$90** |
| Gastado (librería SRI) | **$30** |
| **Saldo pendiente** | **$350** |

**Cuándo cobrar:** cuando la contadora valide en el servidor. Ahí ya no es una promesa —
es un sistema funcionando en su dominio, con su firma, facturando de verdad.

**Si te regatea:** KVS le cobra **$289/año, para siempre**. Vos le entregás el sistema
completo por $440 una vez. Y tenés algo que ellos no: **los asientos ya están hechos**
aunque no compre el módulo de contabilidad — el día que lo quiera, abre y ya tiene su
historia completa.

---

## ⚠️ Seguridad — pendiente de la vez pasada
La captura `sistema-captura-creador/Screenshot 2026-07-16 at 1.43.17 PM.png` tiene
**credenciales reales del creador de KVS** (clave de su firma electrónica, clave de correo,
usuario SRI). **Borrala antes de subir el repo al servidor o a GitHub.**

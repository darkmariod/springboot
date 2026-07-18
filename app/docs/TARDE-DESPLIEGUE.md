# 🚀 TARDE — cerrar, desplegar y que pruebe la contadora

> Plan de la tarde. Al final del día: sistema **en un servidor, con dominio, facturando de
> verdad**, y la contadora probándolo.

---

# 0. 💰 PRIMERO: los precios REALES de KBS (los saqué de kbs-erp.com)

**Confirmaste bien**: Emprendedor sin series · Pro con series. Así lo venden ellos:

## Línea INVENTARIOS (la tuya — vendés a tienda de computadoras)

| Plan | Anual | Semestral | Series/Lotes | Firma gratis |
|------|-------|-----------|--------------|--------------|
| **Emprendedor** | **$289** + IVA | $169 | ❌ | ❌ |
| **Pro** | **$389** + IVA | $225 | ✅ | ✅ |
| **Business** | **$559** + IVA | $299 | ✅ | ✅ |
| **Corporativo** | **$659** + IVA | $379 | ✅ | ✅ |

## Línea SERVICIOS (solo facturación, sin inventario)
Emprendedor **$99** · Pro **$189** · Business **$399** · Corporativo **$489** (anual + IVA)

**Lo que incluye cada nivel (de su web):**
- **Emprendedor**: facturación electrónica, POS, caja
- **Pro**: + cuentas por pagar, retenciones, producción/recetas, **series**, **firma gratis**
- **Business**: + contabilidad, 500+ reportes, nómina, impuestos/anexos, conciliación bancaria
- **Corporativo**: + multi-sucursal, activos fijos, nómina completa, portal empleado, portal de pagos

> Todos: implementación gratis + capacitación. Corporativo suma WhatsApp exclusivo.

## ⚠️ Ajustá tus planes — hoy no coinciden

Tu `config/planes.php` tiene **básico $289 / pro $489 / corporativo $890**.
KBS cobra **$289 / $389 / $559 / $659**. Dos correcciones:

1. **Renombrá** `basico` → `emprendedor` para hablar el mismo idioma que el cliente.
2. **Sacá las series del Emprendedor** (hoy tu básico no las tiene — ✅ ya está bien)
   y confirmá que el Pro sí las tenga (✅ ya está).

**Tu precio recomendado:**
| Plan | KBS (por año, para siempre) | Vos (pago único) |
|---|---|---|
| Emprendedor | $289/año | **$350 una vez** |
| Pro (con series + firma) | $389/año | **$500 una vez** |

> **El argumento de venta:** al segundo año, el cliente de KBS ya pagó $778 y sigue pagando.
> El tuyo pagó $500 y listo. **En 15 meses te pagaste solo.**

### Cómo aplicarlo (5 min)
```php
// config/planes.php — renombrar la key 'basico' por 'emprendedor'
'emprendedor' => [
    'nombre' => 'Emprendedor',
    'precio_anual' => 289,
    'features' => ['catalogo','ventas','compras','inventario','facturacion_sri','reportes','import_lote'],
],
'pro' => [
    'nombre' => 'PRO',
    'precio_anual' => 389,   // era 489
    // ... el resto igual (ya tiene series, cartera, bancos, conciliacion, firma_incluida)
],
```
Y en `EdocConfigController`/`Companies.vue`, en la lista de planes cambiá `basico` por
`emprendedor`. En la migración de `companies.plan` el default puede quedar en `corporativo`
para tus demos.

```bash
php artisan tinker
>>> App\Models\Company::first()->update(['plan' => 'pro']);   # para el demo de la contadora
```

---

# 1. 🔥 CARGAR EL .p12 (lo único que bloquea todo — 10 min)

Sin esto la factura se queda en `generado` y **no hay demo real**.

1. **EDocuments → Configuración de firma**
2. Cargá el `.p12` + su clave → debe decir *"Firma cargada · vence …"*
3. **Usuario SRI** = el RUC · **Clave SRI** = la del portal
4. **Tipo Ambiente**: `PRUEBAS` primero (para no ensuciar producción), después `PRODUCCION`
5. **SMTP** (Gmail): `smtp.gmail.com` · puerto **465** · SSL ✓ · usuario = el correo ·
   clave = **contraseña de aplicación de 16 letras** (NO la clave normal)
6. Botón **"Probar correo"** → tiene que llegarte
7. POS → emití una factura → debe pasar a `firmado → enviado → AUTORIZADO`

### Si falla
| Error | Causa casi siempre |
|---|---|
| "El certificado no abre con esa clave" | La clave está mal, o el archivo no es `.p12` |
| El correo no sale | Usaste la clave normal de Gmail en vez de la **contraseña de aplicación** |
| Se queda en `firmado`, no llega a `enviado` | Falta la extensión **`php-soap`** |
| El SRI devuelve "CLAVE ACCESO REGISTRADA" | Ya mandaste esa factura — subí el secuencial |

```bash
php -m | grep -i soap   # tiene que aparecer "soap"
```

---

# 2. FORMULARIOS DEL CREADOR (si te da el tiempo)

**[FORMULARIOS-CREADOR.md](FORMULARIOS-CREADOR.md)** — Categorías · Compra manual · Buscador.

> **Criterio:** si la tarde viene corta, **saltealos y andá al deploy**. La contadora va a
> probar el FLUJO (facturar, cobrar, ver asientos), no el alta manual de compras. El deploy
> vale más que estos 3 formularios.

---

# 3. 🚀 DEPLOY — Debian 13 + SSH desde tu Mac

## 3.1 Conectarte (desde tu Mac)
```bash
ssh-keygen -t ed25519 -C "mario@monkeycomputer"     # una sola vez
ssh-copy-id root@IP_DEL_SERVIDOR
ssh root@IP_DEL_SERVIDOR
```
Atajo — en `~/.ssh/config` de tu Mac:
```
Host contable
    HostName IP_DEL_SERVIDOR
    User root
    IdentityFile ~/.ssh/id_ed25519
```
→ después entrás solo con `ssh contable`

## 3.2 Preparar el servidor
```bash
apt update && apt upgrade -y
apt install -y nginx mariadb-server git unzip curl \
  php-fpm php-mysql php-xml php-curl php-mbstring php-zip php-gd php-bcmath php-soap

php -m | grep -i soap    # ⚠️ SIN ESTO LA LIBRERÍA DEL SRI NO ENVÍA NI AUTORIZA

curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs

mysql -e "CREATE DATABASE contable CHARACTER SET utf8mb4;"
mysql -e "CREATE USER 'contable'@'localhost' IDENTIFIED BY 'CLAVE_FUERTE_ACA';"
mysql -e "GRANT ALL ON contable.* TO 'contable'@'localhost'; FLUSH PRIVILEGES;"
```

## 3.3 Subir el sistema
```bash
cd /var/www
git clone TU_REPO contable && cd contable/contabilidad-backend

curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer install --no-dev --optimize-autoloader

cp .env.example .env && php artisan key:generate
nano .env
```
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tudominio.com
DB_CONNECTION=mysql
DB_DATABASE=contable
DB_USERNAME=contable
DB_PASSWORD=CLAVE_FUERTE_ACA
```
```bash
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache && php artisan route:cache

chown -R www-data:www-data /var/www/contable
chmod -R 775 storage bootstrap/cache

cd ../contabilidad-vue && npm ci && npm run build
```

## 3.4 nginx — **usá subdominio para la API** (más simple y no falla)
```bash
nano /etc/nginx/sites-available/contable
```
```nginx
# Frontend
server {
    listen 80;
    server_name tudominio.com;
    root /var/www/contable/contabilidad-vue/dist;
    index index.html;
    location / { try_files $uri $uri/ /index.html; }
}

# API (Laravel)
server {
    listen 80;
    server_name api.tudominio.com;
    root /var/www/contable/contabilidad-backend/public;
    index index.php;
    client_max_body_size 20M;          # para subir el .p12 y los XML

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```
```bash
ln -s /etc/nginx/sites-available/contable /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

**Apuntar el Vue a la API** — en `contabilidad-vue/.env.production`:
```
VITE_API_URL=https://api.tudominio.com/api
```
Y en `src/lib/api.ts`, que el `baseURL` la use:
```ts
baseURL: import.meta.env.VITE_API_URL ?? '/api',
```
Después: `npm run build` de nuevo.

**CORS** — en `contabilidad-backend/config/cors.php`:
```php
    'allowed_origins' => ['https://tudominio.com'],
    'supports_credentials' => true,
```

## 3.5 SSL + firewall + backup
```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d tudominio.com -d api.tudominio.com

apt install -y ufw && ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable

crontab -e
```
```
0 3 * * * mysqldump -u contable -pCLAVE contable | gzip > /root/bk-$(date +\%F).sql.gz
0 4 * * * find /root -name "bk-*.sql.gz" -mtime +14 -delete
```

## ⚠️ Las 3 que no podés olvidar
1. **`php-soap`** → sin eso el SRI no recibe nada.
2. **`APP_KEY`** → el `.p12` y las claves se guardan encriptadas con esa key. Si la perdés o
   la regenerás, **perdés todas las firmas cargadas**. Copiala a un lugar seguro.
3. **`APP_DEBUG=false`** → si queda en `true`, exponés tu `.env` entero a internet.

---

# 4. 👩‍💼 PREPARAR LA PRUEBA DE LA CONTADORA

## 4.1 Dejar datos limpios
```bash
php artisan db:seed --class=DemoSeeder --force
php artisan contable:chequeo          # TIENE que decir TODO OK
```

## 4.2 Crearle su usuario
```bash
php artisan tinker
>>> App\Models\User::create([
...   'company_id' => 1, 'name' => 'Contadora',
...   'email' => 'contadora@empresa.com',
...   'password' => Hash::make('unaClaveSegura123'),
...   'rol' => 'contador', 'activo' => true,
... ]);
>>> App\Models\Company::first()->update(['plan' => 'pro']);
```

## 4.3 Lo que ella va a buscar (de los primeros videos) — preparate

| Va a preguntar | Dónde está |
|---|---|
| *"¿Puedo editar un asiento?"* | Contabilidad → **Desmayorizar** ← lo que ama de KVS |
| *"¿De dónde salió este asiento?"* | Cada asiento tiene concepto y referencia a su documento |
| *"¿Y si me pagan con cheque y efectivo a la vez?"* | Cuentas por cobrar → **cobro repartido** (10 formas) |
| *"¿Puedo cruzar un anticipo?"* | Ventas → Anticipos → "Usar saldo" en la factura |
| *"¿Quién hizo esta factura?"* | Administración → Auditoría *(plan Corporativo)* |
| *"¿Importa las compras del SRI?"* | Compras → Importar del SRI (lote) |
| *"¿Conciliación bancaria?"* | Caja y Bancos → Conciliación (auto-match) |
| *"¿Las facturas llegan al correo?"* | Sí, automático al emitir (XML + PDF) |

> **Si pregunta por contabilidad y está en plan Pro:** *"El sistema ya está llevando los
> asientos aunque no vea el módulo. El día que contrate Business, abre y tiene toda su
> historia desde el día uno."* ← **KBS no puede hacer eso.**

## 4.4 Guion del demo (10 min)
1. Inventario → Reportes → "Existencias Bodega" → Imprimir/PDF
2. **Garantías por serie** → `350269500001` → *"a quién le compré y a quién le vendí ESTA unidad"* 🎯
3. POS → escaneá `350269500002` → vendé en vivo → **la factura llega al correo**
4. Cuentas por cobrar → **cobro repartido** (efectivo + transferencia)
5. Libro diario → asiento automático → **Desmayorizar**
6. Estados financieros → **"Cuadrado ✓"**

---

# 5. ✅ CHECKLIST DE LA TARDE

- [ ] Planes renombrados (`emprendedor` $289 / `pro` $389) para hablar como KBS
- [ ] `.p12` cargado → factura **AUTORIZADO**
- [ ] SMTP → "Probar correo" llega
- [ ] `php -m | grep soap` en el servidor → aparece
- [ ] Deploy con dominio + SSL
- [ ] `APP_DEBUG=false` · `APP_KEY` respaldada
- [ ] Backup diario en cron
- [ ] `contable:chequeo` → **TODO OK**
- [ ] Usuario de la contadora creado, empresa en plan `pro`
- [ ] Le mandaste: **URL + usuario + clave + el guion de qué probar**

---

# 6. 💵 COBRANZA

| | |
|---|---|
| Cobrado | **$90** |
| Librería SRI | **-$30** |
| **Saldo** | **$350** |

**Cuándo cobrar:** cuando la contadora valide **en el servidor**. Ahí ya no es una promesa:
es su sistema, en su dominio, con su firma, facturando de verdad.

**Si te regatea, los números de KBS:**
> *"KBS Inventario Pro cuesta **$389 + IVA por año**. El segundo año ya van $778, el tercero
> $1.167, y siguen pagando para siempre. Esto es pago único."*

---

# 7. 🔜 DESPUÉS DEL DESPLIEGUE (para seguir desarrollando)

En orden de valor:

1. **Business/Corporativo** — pero **solo con requisitos por escrito**
   → las 16 preguntas están en [ENTREGA-BASICO-PRO.md](ENTREGA-BASICO-PRO.md)
2. **Nota de crédito SRI** (codDoc 04) + **retenciones emitidas** (codDoc 07) — la librería
   ya los soporta
3. **Módulo Impuestos** (formulario 104) — 📸 mandame la captura de KBS
4. **Formularios del creador** si quedaron pendientes

**Lo que KBS tiene y NO vale la pena copiar todavía:** producción/recetas, multi-sucursal,
portal del empleado, portal de pagos online. Son de su Business/Corporativo y ningún cliente
tuyo los pidió.

---

## ⚠️ Seguridad — sigue pendiente
Borrá `sistema-captura-creador/Screenshot 2026-07-16 at 1.43.17 PM.png` **antes de subir el
repo al servidor**: tiene la clave de la firma del creador de KBS, su clave de correo y su
usuario SRI.

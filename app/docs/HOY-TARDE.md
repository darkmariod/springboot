# 🎯 HOY EN LA TARDE — todo listo, solo ejecutar

> **Ya no hay que programar nada.** Todo está construido y verificado.
> La tarde es: cargar el `.p12` → desplegar → que la contadora pruebe → cobrar.

---

# ✅ LO QUE YA ESTÁ HECHO (verificado hoy, no de memoria)

| | Estado |
|---|---|
| **Planes de KBS** (4 niveles, precios reales) | ✅ aplicados y probados |
| **Importar del SRI** (XML individual · TXT en lote · retenciones) | ✅ 5 rutas activas |
| **Librería comprada** | ✅ carga y emite (clave de 49 dígitos) |
| **Módulos** | ✅ **todos**, incluido contabilidad, nómina, usuarios, auditoría |
| **CRUD completo** | ✅ Plan de cuentas, Cotizaciones y Proveedores (arreglados hoy) |
| **UX de Javier** | ✅ nombre dentro del tile + fondo tech |
| **Contabilidad** | ✅ `contable:chequeo` en verde |

## Los 4 planes, ya alineados con KBS

| Plan | KBS/año | Trae | Tu precio (único) |
|---|---|---|---|
| **Emprendedor** | $289 | Facturación + inventario, **sin series** | $350 |
| **PRO** ← *este cliente* | $389 | **+ series 🎯 + cartera + firma** | **$500** |
| **Business** | $559 | + contabilidad + nómina + conciliación | $700 |
| **Corporativo** | $659 | + multi-sucursal + activos fijos *(no construido)* | — |

**La empresa demo quedó en `business`** para que la contadora vea todo.
Para vender: Administración → Empresas → columna Plan → cambiás y los tiles aparecen/desaparecen.

---

# 🔥 PASO 1 — Cargar el `.p12` (10 min, es lo único que bloquea)

**EDocuments → Configuración de firma**

1. **Firma**: cargá el `.p12` + su clave → debe decir *"Firma cargada · vence …"*
2. **Usuario SRI** = el RUC · **Clave SRI** = la del portal
3. **Tipo Ambiente**: `PRUEBAS` primero, después `PRODUCCION`
4. **SMTP** (Gmail): `smtp.gmail.com` · puerto **465** · SSL ✓
   · usuario = el correo · clave = **contraseña de aplicación de 16 letras** (NO la normal)
5. **"Probar correo"** → tiene que llegarte
6. POS → emití una factura → `firmado → enviado → AUTORIZADO`

| Si falla | Es casi seguro |
|---|---|
| "El certificado no abre con esa clave" | La clave está mal, o no es un `.p12` |
| El correo no sale | Usaste la clave normal de Gmail, no la **contraseña de aplicación** |
| Queda en `firmado`, no pasa a `enviado` | Falta **`php-soap`** en el servidor |
| "CLAVE ACCESO REGISTRADA" | Ya enviaste esa factura → subí el secuencial |

---

# 🚀 PASO 2 — Desplegar en Debian 13

## 2.1 SSH desde tu Mac
```bash
ssh-keygen -t ed25519 -C "mario@monkeycomputer"    # una sola vez
ssh-copy-id root@IP_DEL_SERVIDOR
```
`~/.ssh/config` en tu Mac:
```
Host contable
    HostName IP_DEL_SERVIDOR
    User root
    IdentityFile ~/.ssh/id_ed25519
```
→ entrás con `ssh contable`

## 2.2 Servidor
```bash
apt update && apt upgrade -y
apt install -y nginx mariadb-server git unzip curl \
  php-fpm php-mysql php-xml php-curl php-mbstring php-zip php-gd php-bcmath php-soap

php -m | grep -i soap        # ⚠️ SIN ESTO EL SRI NO RECIBE NADA

curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs

mysql -e "CREATE DATABASE contable CHARACTER SET utf8mb4;"
mysql -e "CREATE USER 'contable'@'localhost' IDENTIFIED BY 'CLAVE_FUERTE';"
mysql -e "GRANT ALL ON contable.* TO 'contable'@'localhost'; FLUSH PRIVILEGES;"
```

## 2.3 Subir
```bash
cd /var/www && git clone TU_REPO contable && cd contable/contabilidad-backend
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
DB_PASSWORD=CLAVE_FUERTE
```
```bash
php artisan migrate --force && php artisan db:seed --force
php artisan config:cache && php artisan route:cache
chown -R www-data:www-data /var/www/contable && chmod -R 775 storage bootstrap/cache
cd ../contabilidad-vue && npm ci && npm run build
```

## 2.4 nginx (subdominio para la API — más simple, no falla)
```nginx
server {
    listen 80;
    server_name tudominio.com;
    root /var/www/contable/contabilidad-vue/dist;
    index index.html;
    location / { try_files $uri $uri/ /index.html; }
}
server {
    listen 80;
    server_name api.tudominio.com;
    root /var/www/contable/contabilidad-backend/public;
    index index.php;
    client_max_body_size 20M;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```
```bash
ln -s /etc/nginx/sites-available/contable /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default && nginx -t && systemctl reload nginx
```

**Apuntar el Vue a la API** — `contabilidad-vue/.env.production`:
```
VITE_API_URL=https://api.tudominio.com/api
```
En `src/lib/api.ts`: `baseURL: import.meta.env.VITE_API_URL ?? '/api'` → `npm run build`

**CORS** — `contabilidad-backend/config/cors.php`:
```php
'allowed_origins' => ['https://tudominio.com'],
'supports_credentials' => true,
```

## 2.5 SSL + firewall + backup
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
1. **`php-soap`** → sin eso la librería no envía ni autoriza al SRI.
2. **`APP_KEY`** → el `.p12` y las claves se guardan encriptadas con ella.
   **Si la perdés o la regenerás, perdés todas las firmas cargadas.** Copiala aparte.
3. **`APP_DEBUG=false`** → si queda en `true`, exponés tu `.env` entero a internet.

---

# 👩‍💼 PASO 3 — Que la contadora pruebe

## 3.1 Preparar
```bash
php artisan db:seed --class=DemoSeeder --force
php artisan contable:chequeo                # TIENE que decir TODO OK

php artisan tinker
>>> App\Models\User::create([
...   'company_id' => 1, 'name' => 'Contadora',
...   'email' => 'contadora@empresa.com',
...   'password' => Hash::make('claveSegura123'),
...   'rol' => 'contador', 'activo' => true,
... ]);
>>> App\Models\Company::first()->update(['plan' => 'business']);
```

## 3.2 Lo que ella va a buscar (de sus videos) — y dónde está

| Va a preguntar | Respuesta |
|---|---|
| *"¿Puedo editar un asiento?"* | Contabilidad → **Desmayorizar** ← lo que ama de KVS |
| *"¿Importa las compras del SRI?"* | Compras → **Importar del SRI (lote)**: subís el TXT del mes, el sistema trae los XML solo |
| *"¿Y si me pagan con cheque + efectivo?"* | Cuentas por cobrar → **cobro repartido** (10 formas) |
| *"¿Puedo cruzar un anticipo?"* | Ventas → Anticipos → "Usar saldo" |
| *"¿Conciliación bancaria?"* | Caja y Bancos → Conciliación (auto-match) |
| *"¿El sustento tributario?"* | Compras → los 11 sustentos del SRI |
| *"¿Puedo crear mis cuentas?"* | Plan de cuentas → **Nueva cuenta** *(arreglado hoy)* |
| *"¿Quién hizo esta factura?"* | Administración → Auditoría |
| *"¿La factura le llega al cliente?"* | Sí: XML + PDF automático al emitir |

## 3.3 Guion del demo (10 min)
1. Inventario → Reportes → "Existencias Bodega" → Imprimir/PDF
2. **Garantías por serie** → `350269500001` → *"a quién le compré y a quién le vendí ESTA unidad"* 🎯
3. POS → escaneá `350269500002` → vendé → **la factura llega al correo**
4. Cuentas por cobrar → **cobro repartido** (efectivo + transferencia)
5. Libro diario → asiento automático → **Desmayorizar**
6. Estados financieros → **"Cuadrado ✓"**

---

# ✅ CHECKLIST DE LA TARDE

- [ ] `.p12` cargado → factura **AUTORIZADO**
- [ ] SMTP → "Probar correo" llega
- [ ] `php -m | grep soap` en el servidor → aparece
- [ ] Deploy con dominio + SSL
- [ ] `APP_DEBUG=false` · `APP_KEY` respaldada
- [ ] Backup diario en cron
- [ ] `contable:chequeo` → **TODO OK**
- [ ] Usuario de la contadora + empresa en `business`
- [ ] Le mandaste: **URL + usuario + clave + el guion**
- [ ] **Borrar** `sistema-captura-creador/Screenshot 2026-07-16 at 1.43.17 PM.png`
      *(tiene las credenciales reales del creador de KBS)*

---

# 💰 COBRANZA

| | |
|---|---|
| Pagado (adelanto) | **$90** |
| Librería SRI | **-$30** |
| **Falta cobrar** | **$_____** ← *confirmá el número: leí $350 y también $260* |

**Cuándo:** cuando la contadora valide **en el servidor**.

**Si te regatea:**
> *"KBS Inventario Pro son **$389 + IVA por año**. Al segundo año van $778, al tercero $1.167,
> y siguen pagando para siempre. Esto es pago único."*

---

# 🔜 DESPUÉS: el módulo más fuerte de KBS

Ya tenés **Business completo** (contabilidad + nómina + conciliación). Lo que falta es el
**Corporativo**, y son 4 cosas:

| Módulo | Esfuerzo | ¿Lo pidieron? |
|---|---|---|
| **Multi-sucursal** | Alto — toca todo el sistema | ❌ |
| **Activos fijos + depreciación** | Medio — es el más vendible | ❌ |
| **Portal del empleado** | Medio | ❌ |
| **Portal de pagos online** | Alto (pasarela) | ❌ |

## 👉 Mi recomendación: **Activos Fijos**

Es el más fuerte por relación valor/esfuerzo:
- Todo cliente **obligado a llevar contabilidad** lo necesita.
- Se apoya en lo que ya tenés: genera su **asiento de depreciación mensual** automático.
- Es acotado: tabla de activos + cálculo lineal + asiento. **No toca el resto del sistema.**
- Multi-sucursal, en cambio, te obliga a tocar inventario, ventas, compras y caja **enteros**.

**Pero no lo hagas todavía.** Primero cobrá, y pedí los requisitos **por escrito**
(las 16 preguntas están en [ENTREGA-BASICO-PRO.md](ENTREGA-BASICO-PRO.md)).

---

## ⭐ Tu as bajo la manga (decilo en el demo)
> *"Aunque contrate PRO y no vea la contabilidad, el sistema la está llevando igual. El día
> que quiera Business, abre el módulo y ya tiene toda su historia desde el día uno."*

**KBS no puede hacer eso.** Ya está construido y no te cuesta nada.

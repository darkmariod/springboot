# 🖥️ Desplegar en la laptop Debian 13 (headless, desde la Mac)

> Runbook para seguir paso a paso. La laptop = tu servidor de despliegue en casa (Docker + Dokploy),
> manejado desde la Mac por SSH, apps expuestas por Cloudflare Tunnel (sin dominio ni IP fija).
> Mac y laptop en la MISMA WiFi.

---

## FASE 0 — Instalar Debian con SSH (único paso frente a la pantalla)
La pantalla está rota → conectá la laptop a un **monitor/TV por HDMI** para este paso.
1. Instalá Debian 13. En la pantalla de `tasksel`, marcá **"SSH server"** (barra espaciadora).
2. Creá tu usuario (anotalo) y una contraseña temporal.
3. Anotá el **hostname** (ej. `debian`). Primer boot → ya tenés SSH andando. Sacá el monitor.

## FASE 1 — Conectarte desde la Mac
Tu Mac YA tiene llave SSH (`~/.ssh/id_ed25519`). En la Mac:
```bash
# 1) Encontrar la laptop en la red (probá el hostname primero)
ping -c2 debian.local        # si responde, usá debian.local abajo
# si no, escaneá la red:  nmap -sn 192.168.1.0/24   (ajustá a tu red)

# 2) Entrar (primera vez con password)
ssh usuario@debian.local

# 3) Dejar login por llave (sin password). Salí del server y en la MAC:
ssh-copy-id usuario@debian.local
```
Endurecer SSH — en el server, editar `/etc/ssh/sshd_config`:
```
PasswordAuthentication no
PermitRootLogin no
```
```bash
sudo systemctl restart ssh   # probá reconectar en OTRA terminal antes de cerrar esta
```

## FASE 2 — Base del server (por SSH)
```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y ufw
sudo ufw allow OpenSSH && sudo ufw allow 3000 && sudo ufw enable
sudo timedatectl set-timezone America/Guayaquil
```

## FASE 3 — Docker + Dokploy
```bash
curl -sSL https://dokploy.com/install.sh | sudo sh   # instala Docker + Traefik + Dokploy
docker ps                                             # verificar que corre
```
Desde la Mac, abrí en el navegador: **http://debian.local:3000** → creá el usuario admin de Dokploy.

## FASE 4 — Desplegar la app contable
### 4.1 Llevar el repo (SIN GitHub — regla del proyecto)
En la Mac:
```bash
rsync -avz --exclude node_modules --exclude vendor --exclude .git \
  /Users/mariopazmino/Desktop/springboot/app/ usuario@debian.local:~/contable/
```
### 4.2 ⚠️ APP_KEY FIJA (crítico — si no, se rompe la firma en cada reinicio)
En el server:
```bash
cd ~/contable
cp .env.docker .env.docker    # (ya viene la plantilla)
# Generar UNA APP_KEY y pegarla FIJA en .env.docker:
docker compose run --rm backend php artisan key:generate --show
```
Copiá esa clave (`base64:....`) y en `~/contable/.env.docker` poné:
```
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:LA_CLAVE_QUE_TE_DIO
DB_CONNECTION=sqlite
# + SMTP y credenciales SRI reales
```
> El entrypoint ahora FALLA a propósito si arrancás en producción con APP_KEY vacía —
> así no te rompe la firma en silencio. Respaldá esa APP_KEY fuera del server.

### 4.3 Levantar
```bash
cd ~/contable && docker compose up -d --build
docker ps                                    # backend, frontend, tunnel arriba
docker exec contable-backend php artisan contable:chequeo   # → TODO OK
```
### 4.4 Firma
Cargá el `.p12` en EDocuments → Configuración de firma. Emití 1 factura → debe llegar a **AUTORIZADO**.

## FASE 5 — Exponer con Cloudflare Tunnel
El `docker-compose.yml` ya trae el servicio `tunnel`. Para ver la URL pública temporal:
```bash
docker logs contable-tunnel | grep trycloudflare
```
Para demos con URL estable: creá un **named tunnel** (cuenta Cloudflare gratis) o usá el reverse
proxy de Dokploy con un dominio. Otros proyectos → cada uno su app en Dokploy + su tunnel.

## FASE 6 — opencode (para codear en el server)
```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
# instalar opencode (paquete oficial) y aplicar la config de Gentleman si la tenés
```

---

## ✅ Verificación final
1. `ssh usuario@debian.local` entra sin password.
2. `docker ps` → `contable-backend`, `contable-frontend`, `contable-tunnel` corriendo.
3. Abrí la URL del tunnel → la app carga; login `admin@demo.com` / `password123`.
4. `docker exec contable-backend php artisan contable:chequeo` → TODO OK.
5. Con `.p12` cargado → 1 factura en AUTORIZADO.

## ⚠️ Gotchas (no repetir)
- **APP_KEY fija** en `.env.docker` (ya blindado en el entrypoint). Respaldala.
- `.env.docker` NUNCA a git (ya está en `.gitignore`).
- La DB sqlite vive en el volumen `db-data` (persiste entre reinicios). Primer arranque = DB vacía;
  seedear si querés datos demo: `docker exec contable-backend php artisan db:seed --class=DemoSeeder`.
- IP dinámica de casa → por eso Cloudflare Tunnel (no dependés de IP fija ni de abrir puertos).

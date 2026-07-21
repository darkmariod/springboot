#!/bin/bash
# ── Setup Debian 13 (Trixie) for Docker + Cloudflare Tunnel ──
# Ejecutar como root en el servidor
set -e

echo "=== Actualizando sistema ==="
apt update && apt upgrade -y

echo "=== Instalando Docker ==="
apt install -y ca-certificates curl gnupg
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  tee /etc/apt/sources.list.d/docker.list > /dev/null
apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

echo "=== Instalando Docker Compose standalone (fallback) ==="
COMPOSE_VERSION=$(curl -s https://api.github.com/repos/docker/compose/releases/latest | grep tag_name | cut -d '"' -f 4)
curl -L "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
chmod +x /usr/local/bin/docker-compose

echo "=== Habilitando Docker ==="
systemctl enable docker
systemctl start docker

echo "=== Instalando git ==="
apt install -y git

echo "=== Configurando firewall ==="
apt install -y ufw
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 8080/tcp   # Frontend directo (opcional)
ufw --force enable

echo ""
echo "=== ✅ Debian 13 listo para Docker ==="
echo ""
echo "Próximos pasos:"
echo "  1. Cloná el repo:  git clone TU_REPO /opt/contable"
echo "  2. Entrá:          cd /opt/contable/app"
echo "  3. Levantá:        docker compose up -d --build"
echo "  4. Cloudflare te dará una URL tipo: https://xxx-xxx.trycloudflare.com"
echo ""
echo "Para ver los logs del tunnel:"
echo "  docker logs contable-tunnel"
echo ""

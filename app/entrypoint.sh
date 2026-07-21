#!/bin/sh
set -e

echo "🚀 Iniciando backend..."

# APP_KEY: en producción DEBE ser fija. Si se regenera en cada arranque, el .p12 y las
# claves SMTP guardadas (encriptadas con APP_KEY) quedan ilegibles y la facturación se rompe.
if [ -z "$APP_KEY" ]; then
    if [ "$APP_ENV" = "production" ]; then
        echo "❌ APP_KEY vacía en producción."
        echo "   Seteá una APP_KEY FIJA en .env.docker (generala una vez con:"
        echo "   docker compose run --rm backend php artisan key:generate --show)."
        echo "   Sin clave fija, el .p12 y el SMTP guardados se rompen en el próximo reinicio."
        exit 1
    fi
    echo "📝 Generando APP_KEY (entorno no productivo)..."
    php artisan key:generate --force
fi

# Ensure database exists
touch database/database.sqlite
chown www-data:www-data database/database.sqlite

# Run migrations
echo "🔄 Ejecutando migraciones..."
php artisan migrate --force --no-interaction

# Cache config
echo "⚡ Cacheando configuración..."
php artisan config:cache
php artisan route:cache
php artisan view:cache 2>/dev/null || true

# Storage link
php artisan storage:link --force 2>/dev/null || true

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache

echo "✅ Backend listo"

# Start PHP-FPM
exec php-fpm

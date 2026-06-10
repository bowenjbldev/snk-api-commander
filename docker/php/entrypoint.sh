#!/bin/bash
set -e

# Asegurar que existe el archivo .env
if [ ! -f ".env" ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Instalar dependencias si no existen (optimizado)
if [ ! -d "vendor" ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Generar Key si está vacía
if [ -z "$(grep APP_KEY .env | cut -d '=' -f2)" ]; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force
fi

# Fix permissions (www-data owns files via UID matching with host user)
# database/ se trata aparte: chmod recursivo alteraria el modo de archivos trackeados en git
echo "Fixing permissions for storage, cache and database..."
chmod -R 775 storage bootstrap/cache
chmod 775 database
find database -name '*.sqlite*' -exec chmod 664 {} + 2>/dev/null || true

# Limpiar config cache (no requiere DB)
echo "Clearing config cache..."
php artisan config:clear

# Ejecutar migraciones
echo "Running database migrations..."
php artisan migrate --force

# Limpiar cache (requiere DB, va después de migrate)
echo "Clearing application cache..."
php artisan cache:clear

exec "$@"

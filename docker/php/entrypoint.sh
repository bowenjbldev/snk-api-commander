#!/bin/bash
set -e

# --- Seccion critica: muta archivos del bind mount compartido (./src) ---
# flock sobre un archivo del mount compartido => exclusion entre N contenedores
# (con varias suscripciones arrancando a la vez, solo uno instala/genera key).
mkdir -p storage/framework
(
    flock 9

    if [ ! -f ".env" ]; then
        echo "Creating .env from .env.example..."
        cp .env.example .env
    fi

    if [ ! -d "vendor" ]; then
        echo "Installing composer dependencies..."
        composer install --no-interaction --prefer-dist --optimize-autoloader
    fi

    if [ -z "$(grep APP_KEY .env | cut -d '=' -f2)" ]; then
        echo "Generating APP_KEY..."
        php artisan key:generate --force
    fi
) 9>storage/framework/.entrypoint.lock

# --- Por contenedor ---
# ug+rwX (no 775): la X solo aplica ejecucion a directorios, asi no se altera
# el bit de ejecucion de archivos trackeados en git (storage/*/.gitignore, etc.)
echo "Fixing permissions for storage, cache and database..."
chmod -R ug+rwX storage bootstrap/cache
chmod ug+rwX database
find database -name '*.sqlite*' -exec chmod 664 {} + 2>/dev/null || true

# bootstrap/cache es un volumen anonimo: puede arrancar vacio.
# Laravel regenera services.php solo; packages.php lo regeneramos explicitamente.
if [ ! -f bootstrap/cache/packages.php ]; then
    php artisan package:discover --ansi
fi

# Limpiar config cache (no requiere DB)
echo "Clearing config cache..."
php artisan config:clear

# Ejecutar migraciones (Core + paquete activo via API_PACKAGE)
echo "Running database migrations..."
php artisan migrate --force

# Limpiar cache (requiere DB, va después de migrate)
echo "Clearing application cache..."
php artisan cache:clear

exec "$@"

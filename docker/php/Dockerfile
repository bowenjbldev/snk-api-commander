FROM php:8.3-fpm-alpine

# Install system dependencies and PHP extensions in one layer
RUN apk add --no-cache \
    bash \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zlib-dev \
    icu-dev \
    sqlite-dev \
    oniguruma-dev \
    libxml2-dev \
    shadow && \
    docker-php-ext-configure intl && \
    docker-php-ext-install \
    pdo_mysql \
    pdo_sqlite \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/src

# Map www-data to host UID and GID for easier permission management (Common on Linux/Docker)
ARG WWWUSER=1000
ARG WWWGROUP=1000
RUN groupmod -g ${WWWGROUP} www-data && \
    usermod -u ${WWWUSER} -g www-data www-data

# Copy entrypoint script
COPY ./docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Mountpoint para el volumen anonimo de bootstrap/cache (aislado por contenedor):
# debe existir en la imagen con owner www-data o Docker lo crearia como root
RUN mkdir -p /var/www/src/bootstrap/cache && \
    chown -R www-data:www-data /var/www/src

# Default user for container (entrypoint, exec, php-fpm)
USER www-data

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

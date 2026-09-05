# La API sobre Laravel Octane con FrankenPHP en worker mode: la aplicación arranca una vez por
# worker y sirve peticiones en memoria. Imagen oficial (Debian/glibc) con el binario `frankenphp`
# en PATH, que es lo que Octane busca. Es también la imagen de scheduler, queue y provisioner.
FROM dunglas/frankenphp:1-php8.3

# Mismas extensiones que docker/php/fpm.Dockerfile; git para el historial por aplicación y ffmpeg
# para los fotogramas de vídeo.
RUN install-php-extensions pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd zip intl && \
    apt-get update && \
    apt-get install -y --no-install-recommends git ffmpeg && \
    rm -rf /var/lib/apt/lists/* && \
    # La imagen trae el binario con cap_net_bind_service para escuchar en 80/443; aquí escucha en
    # 8000 y `no-new-privileges` rechaza ejecutar un fichero con capabilities: se le quitan.
    setcap -r /usr/local/bin/frankenphp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/src

# www-data con el UID/GID del host, como en fpm.Dockerfile.
ARG WWWUSER=1000
ARG WWWGROUP=1000
RUN groupmod -g ${WWWGROUP} www-data && \
    usermod -u ${WWWUSER} -g www-data www-data

COPY ./docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Mountpoint del volumen anónimo de bootstrap/cache: debe existir con owner www-data.
RUN mkdir -p /var/www/src/bootstrap/cache && \
    chown -R www-data:www-data /var/www/src

# Caddy escribe su estado aquí; www-data no tiene HOME utilizable.
ENV XDG_CONFIG_HOME=/tmp/caddy \
    XDG_DATA_HOME=/tmp/caddy \
    OCTANE_SERVER=frankenphp

USER www-data

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=8", "--max-requests=500"]

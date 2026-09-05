# Octane sobre FrankenPHP en worker mode: la app arranca una vez por worker y sirve peticiones
# en memoria. Imagen oficial (Debian/glibc) con el binario `frankenphp` en PATH, que es lo que
# Octane busca; `octane:install` descargaría un estático musl que aquí sobra.
FROM dunglas/frankenphp:1-php8.3

# Mismas extensiones que docker/php/Dockerfile; ffmpeg para los fotogramas de vídeo.
RUN install-php-extensions pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd zip intl && \
    apt-get update && \
    apt-get install -y --no-install-recommends ffmpeg && \
    rm -rf /var/lib/apt/lists/* && \
    # La imagen trae el binario con cap_net_bind_service para escuchar en 80/443; aquí escucha en
    # 8000 y `no-new-privileges` rechaza ejecutar un fichero con capabilities: se le quitan.
    setcap -r /usr/local/bin/frankenphp

WORKDIR /var/www/src

ARG WWWUSER=1000
ARG WWWGROUP=1000
RUN groupmod -g ${WWWGROUP} www-data && \
    usermod -u ${WWWUSER} -g www-data www-data && \
    mkdir -p /var/www/src/bootstrap/cache && \
    chown -R www-data:www-data /var/www/src

# vendor/ y .env llegan por el bind mount que instala `app`: aquí no hay composer ni entrypoint.
USER www-data

EXPOSE 8000

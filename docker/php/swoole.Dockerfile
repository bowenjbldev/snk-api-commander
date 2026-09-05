# Octane sobre Swoole: un worker por proceso, sin corrutinas (Octane arranca con
# enable_coroutine=false), así que el modelo de aislamiento es el mismo que FrankenPHP.
FROM php:8.3-cli-alpine

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Mismas extensiones que docker/php/Dockerfile más swoole; ffmpeg para los fotogramas de vídeo.
RUN apk add --no-cache bash ffmpeg shadow && \
    install-php-extensions swoole pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd zip intl

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

FROM webdevops/php-nginx:8.4-alpine
WORKDIR /var/www/html

# Instalar Node.js 22
RUN apk add --no-cache --repository=https://dl-cdn.alpinelinux.org/alpine/edge/community nodejs npm

# Copiar dependencias primero (mejor cache de Docker)
COPY composer.json composer.lock package.json package-lock.json ./

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction && npm ci

COPY . .

# Declarar ARGs para Vite
ARG VITE_PUSHER_APP_KEY
ARG VITE_PUSHER_APP_CLUSTER
ARG VITE_PUSHER_HOST
ARG VITE_PUSHER_PORT
ARG VITE_PUSHER_SCHEME

# Convertirlos en ENV para que Vite los lea
ENV VITE_PUSHER_APP_KEY=$VITE_PUSHER_APP_KEY
ENV VITE_PUSHER_APP_CLUSTER=$VITE_PUSHER_APP_CLUSTER
ENV VITE_PUSHER_HOST=$VITE_PUSHER_HOST
ENV VITE_PUSHER_PORT=$VITE_PUSHER_PORT
ENV VITE_PUSHER_SCHEME=$VITE_PUSHER_SCHEME

# Compilar assets y preparar Laravel
RUN composer dump-autoload --optimize \
    && php artisan filament:assets \
    && php artisan livewire:publish --assets \
    && npm run build

# Permisos correctos para webdevops
RUN chown -R application:application /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

RUN mkdir -p /var/www/html/public/infected_files \
    && chown -R application:application /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/infected_files \
    && chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/infected_files

RUN mkdir -p /var/www/html/public/infected_files /var/www/html/public/extracted_files \
    && chown -R application:application /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/infected_files /var/www/html/public/extracted_files /var/www/html/database \
    && chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/infected_files /var/www/html/public/extracted_files /var/www/html/database

# Script de inicio
COPY scripts/install.sh /var/www/html/scripts/install.sh
RUN chmod +x /var/www/html/scripts/install.sh

ENV WEB_DOCUMENT_ROOT=/var/www/html/public

COPY scripts/install.sh /opt/docker/provision/entrypoint.d/10-laravel.sh
RUN chmod +x /opt/docker/provision/entrypoint.d/10-laravel.sh
EXPOSE 80

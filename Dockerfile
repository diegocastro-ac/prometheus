FROM php:8.4-fpm-alpine AS php-base

RUN apk add --no-cache \
        unzip \
        icu-dev \
        libxml2-dev \
        oniguruma-dev \
        curl-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        curl \
        gd \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_sqlite \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


FROM php-base AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts


FROM node:22-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build


FROM php-base AS runtime

RUN apk add --no-cache nginx supervisor

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/public/css/filament/app-utilities.css ./public/css/filament/app-utilities.css
COPY . ./

RUN mkdir -p \
    database \
    bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    public/css/filament \
    && chown -R www-data:www-data \
        database \
        bootstrap/cache \
        storage \
        public

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

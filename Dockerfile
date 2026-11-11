FROM node:22-alpine AS assets

WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction \
    --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libicu-dev libzip-dev libonig-dev \
    libxml2-dev unzip git \
    && docker-php-ext-install \
    pdo_mysql mbstring intl zip bcmath opcache \
    && a2dismod mpm_event mpm_worker || true

RUN a2enmod mpm_prefork rewrite headers

RUN rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN sed -ri \
    's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/apache2.conf

RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80

CMD ["apache2-foreground"]

# Stage 1: Build frontend assets
FROM node:22-alpine AS assets

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build


# Stage 2: Install Laravel dependencies
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts


# Stage 3: PHP and Apache
FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    git \
    && docker-php-ext-install \
       pdo_mysql \
       mbstring \
       intl \
       zip \
       bcmath \
       opcache \
    && rm -rf /var/lib/apt/lists/*


# Fix Apache MPM conflict
# PHP Apache requires prefork.
RUN set -eux; \
    find /etc/apache2/mods-enabled \
      -maxdepth 1 \
      -name 'mpm_*' \
      -delete; \
    a2enmod mpm_prefork rewrite headers; \
    echo "Checking Apache MPM configuration"; \
    apache2ctl configtest


# Laravel application
WORKDIR /var/www/html

COPY . .

COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build


# Serve Laravel from public/
RUN sed -ri \
    's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#g' \
    /etc/apache2/sites-available/000-default.conf


# Allow Laravel routing
RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel


# Writable Laravel directories
RUN mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache


# Final Apache configuration validation
RUN apache2ctl configtest


EXPOSE 80

CMD ["apache2-foreground"]

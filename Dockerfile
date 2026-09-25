FROM composer:2 AS composer

FROM php:8.5.10-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_pgsql pcntl intl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

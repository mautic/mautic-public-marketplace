FROM composer:2.7.2-php8.4 AS build

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-progress

COPY . ./
RUN composer dump-autoload --classmap-authoritative --no-dev

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libpq-dev \
    && docker-php-ext-install intl pdo_pgsql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APP_ENV=prod
ENV APP_DEBUG=0

COPY --from=build /app /var/www/html

WORKDIR /var/www/html

RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www!/var/www/html/public!g' /etc/apache2/apache2.conf

EXPOSE 80

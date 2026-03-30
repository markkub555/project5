FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install pdo pdo_mysql mysqli zip opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/php/custom.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html

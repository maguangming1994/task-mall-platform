FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache curl icu-dev libzip-dev oniguruma-dev postgresql-dev \
    && docker-php-ext-install intl mbstring opcache pcntl pdo_pgsql zip
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --classmap-authoritative
COPY apps/api/ ./
COPY infra/php/production.ini /usr/local/etc/php/conf.d/zz-task-mall.ini
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

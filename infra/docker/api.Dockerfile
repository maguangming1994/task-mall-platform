FROM php:8.5-fpm-alpine AS runtime

ARG ALPINE_MIRROR=https://mirrors.cloud.tencent.com/alpine
RUN sed -i "s|https://dl-cdn.alpinelinux.org/alpine|${ALPINE_MIRROR}|g" /etc/apk/repositories \
    && apk add --no-cache curl icu-dev libzip-dev oniguruma-dev postgresql-dev \
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

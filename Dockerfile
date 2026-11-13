# syntax=docker/dockerfile:1

FROM node:24-bookworm-slim AS frontend-build
WORKDIR /build/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
ENV VITE_API_URL=/api/v1
RUN npm run build

FROM php:8.3-apache-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates libonig-dev libxml2-dev libssl-dev pkg-config \
    && docker-php-ext-install -j"$(nproc)" mbstring dom simplexml \
    && pecl install mongodb-2.5.0 \
    && docker-php-ext-enable mongodb \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
WORKDIR /var/www/adoptplace

FROM php-base AS backend-build
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY backend/ ./
# No install/update hooks, .env generation or application commands at build time.
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
        --optimize-autoloader --no-scripts \
    && composer check-platform-reqs --no-dev

FROM php-base AS runtime
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=10000
COPY --from=backend-build /var/www/adoptplace/ ./
COPY --from=frontend-build /build/frontend/dist/ ./public/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/mpm-prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/adoptplace.ini
COPY docker/entrypoint.sh /usr/local/bin/adoptplace-entrypoint
RUN printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf \
    && chmod 755 /usr/local/bin/adoptplace-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache
EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/adoptplace-entrypoint"]
CMD ["apache2-foreground"]

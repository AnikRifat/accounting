# Production image for Dokploy (or any Docker host). See README "Deploying on Dokploy".

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY app/Livewire ./app/Livewire
RUN npm run build

FROM serversideup/php:8.4-fpm-nginx

# On every start: wait for the database, run pending migrations, link storage, cache config/routes/views/events.
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION_TIMEOUT=60 \
    PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --no-cache

COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction

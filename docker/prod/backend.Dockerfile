# Production image of the backend (php-fpm) and of the nginx in front of it.
# Build context: the repository root (see docker-compose.prod.yml); the ignore list is backend.Dockerfile.dockerignore.
# Secrets are never baked in: APP_SECRET, JWT_PASSPHRASE, DATABASE_URL, … come from .env.prod at runtime.

FROM php:8.4-fpm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install intl bcmath pdo_mysql zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-prod.ini

WORKDIR /var/www/backend


FROM php-base AS app

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Dependencies first: this layer is reused while composer.lock does not change.
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY backend/ ./

# Build-time boot only needs placeholders: the real values are read from the environment at runtime
# (the compiled container keeps %env()% lookups, nothing secret is written into the cache).
ENV APP_ENV=prod APP_DEBUG=0
RUN composer dump-autoload --optimize --no-dev \
    && APP_SECRET=build-only php bin/console assets:install public --no-interaction \
    && APP_SECRET=build-only php bin/console cache:warmup --no-interaction \
    && mkdir -p var/log config/jwt \
    && chown -R www-data:www-data var config/jwt \
    && rm /usr/local/bin/composer

USER www-data
EXPOSE 9000
CMD ["php-fpm"]


# nginx with the public directory of the same build (index.php + bundle assets).
FROM nginx:1.27-alpine AS nginx

COPY docker/prod/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/backend/public /var/www/backend/public

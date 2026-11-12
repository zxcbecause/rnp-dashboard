FROM php:8.4-fpm

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
    && docker-php-ext-install intl bcmath pdo_mysql zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Unprivileged dev user that owns files created inside the container.
RUN groupadd -g ${GROUP_ID} dev && useradd -m -u ${USER_ID} -g ${GROUP_ID} -s /bin/bash dev

WORKDIR /var/www/backend
USER dev
